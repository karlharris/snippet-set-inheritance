<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Subscriber;

use Doctrine\DBAL\Connection;
use Scythe\SnippetSetInheritance\Config\PluginConfig;
use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceResolver;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Snippet\Extension\StorefrontSnippetsExtension;
use Shopware\Core\System\Snippet\SnippetService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies snippet-set inheritance to the storefront translation catalog.
 *
 * Hooks the official `storefront.snippets.post` extension point
 * ({@see StorefrontSnippetsExtension}, dispatched by
 * {@see SnippetService::getStorefrontSnippets()}): after the core merge
 * (base file + default catalog + DB overrides) sits in `$extension->result`,
 * every key the set does not maintain itself is filled from the effective
 * catalog of its parent set, then of the configured fallback set.
 *
 * Resolution is recursive: fetching an ancestor's catalog re-enters this
 * subscriber, so `$parentEffective` already carries the parent's own
 * inheritance. The core `Translator` caches the outermost result, so this
 * only runs on a cache miss.
 */
final class StorefrontSnippetsSubscriber implements EventSubscriberInterface
{
    /**
     * Snippet-set ids currently on the resolution stack — guards against a
     * (validation-prevented, but defended anyway) cycle and caps recursion.
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    public function __construct(
        private readonly SnippetService $snippetService,
        private readonly SnippetInheritanceResolver $resolver,
        private readonly PluginConfig $pluginConfig,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontSnippetsExtension::onPost() => 'enrich',
        ];
    }

    public function enrich(StorefrontSnippetsExtension $extension): void
    {
        $setId = strtolower($extension->snippetSetId);

        if (isset($this->resolving[$setId]) || \count($this->resolving) >= $this->pluginConfig->getMaxInheritanceDepth()) {
            return;
        }

        $ancestorIds = [];

        $parentId = $this->resolver->getParentId($setId);
        if ($parentId !== null) {
            $ancestorIds[] = $parentId;
        }

        $fallbackId = $this->pluginConfig->getFallbackSnippetSetId();
        if ($fallbackId !== null && strtolower($fallbackId) !== $setId && !\in_array(strtolower($fallbackId), array_map('strtolower', $ancestorIds), true)) {
            $ancestorIds[] = $fallbackId;
        }

        if ($ancestorIds === []) {
            return;
        }

        /** @var array<string, string> $result */
        $result = \is_array($extension->result) ? $extension->result : [];
        $ownKeys = $this->maintainedKeys($setId);

        $this->resolving[$setId] = true;

        try {
            $inherited = [];
            foreach ($ancestorIds as $ancestorId) {
                $ancestorSnippets = $this->snippetService->getStorefrontSnippets(
                    $extension->catalog,
                    $ancestorId,
                    $extension->fallbackLocale,
                    $extension->salesChannelId,
                );

                foreach ($ancestorSnippets as $key => $value) {
                    if ($value === '' || \array_key_exists($key, $ownKeys) || \array_key_exists($key, $inherited)) {
                        continue;
                    }
                    $inherited[$key] = $value;
                }
            }
        } finally {
            unset($this->resolving[$setId]);
        }

        $extension->result = array_replace($result, $inherited);
    }

    /**
     * @return array<string, true>
     */
    private function maintainedKeys(string $snippetSetId): array
    {
        /** @var list<string> $keys */
        $keys = $this->connection->fetchFirstColumn(
            'SELECT `translation_key` FROM `snippet` WHERE `snippet_set_id` = :id',
            ['id' => Uuid::fromHexToBytes($snippetSetId)],
        );

        return array_fill_keys($keys, true);
    }
}
