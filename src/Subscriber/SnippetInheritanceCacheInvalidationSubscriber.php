<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Subscriber;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Scythe\SnippetSetInheritance\Config\PluginConfig;
use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceResolver;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Translation\Translator;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Snippet\Aggregate\SnippetSet\SnippetSetDefinition;
use Shopware\Core\System\Snippet\SnippetDefinition;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Recursively invalidates translation catalogs for the inheritance feature.
 *
 * The core `CacheInvalidationSubscriber::invalidateSnippets()` invalidates
 * `Translator::tag(<setId>)` only for the sets a `snippet` write touched — not
 * recursively, and not at all for `snippet_set` writes or deletes. Since a
 * child's cached catalog now embeds ancestor values, every change must also
 * invalidate all dependent descendant sets (and, for `snippet_set`, the changed
 * set itself).
 */
class SnippetInheritanceCacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CacheInvalidator $cacheInvalidator,
        private readonly Connection $connection,
        private readonly SnippetInheritanceResolver $resolver,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EntityWrittenContainerEvent::class => 'invalidateOnWrite',
            EntityDeleteEvent::class => 'invalidateOnDelete',
            SystemConfigChangedEvent::class => 'invalidateOnConfigChange',
        ];
    }

    public function invalidateOnWrite(EntityWrittenContainerEvent $event): void
    {
        $setIds = [];

        $snippetIds = $event->getPrimaryKeys(SnippetDefinition::ENTITY_NAME);
        if ($snippetIds !== []) {
            $setIds = array_merge($setIds, $this->getSetIdsBySnippetIds($snippetIds));
        }

        foreach ($event->getPrimaryKeys(SnippetSetDefinition::ENTITY_NAME) as $id) {
            $setIds[] = strtolower((string) $id);
        }

        $this->invalidate($this->expandToSelfAndDescendants($setIds));
    }

    public function invalidateOnDelete(EntityDeleteEvent $event): void
    {
        $setIds = [];

        $snippetIds = $event->getIds(SnippetDefinition::ENTITY_NAME);
        if ($snippetIds !== []) {
            $setIds = array_merge($setIds, $this->getSetIdsBySnippetIds($snippetIds));
        }

        foreach ($event->getIds(SnippetSetDefinition::ENTITY_NAME) as $id) {
            $setIds[] = strtolower((string) $id);
        }

        if ($setIds === []) {
            return;
        }

        // Resolve descendants before the delete runs — `ON DELETE SET NULL`
        // detaches children, and deleted `snippet` rows can no longer be queried.
        $tags = $this->expandToSelfAndDescendants($setIds);

        $event->addSuccess(function () use ($tags): void {
            $this->invalidate($tags);
        });
    }

    public function invalidateOnConfigChange(SystemConfigChangedEvent $event): void
    {
        if (\in_array($event->getKey(), [PluginConfig::FALLBACK_SNIPPET_SET_ID, PluginConfig::MAX_INHERITANCE_DEPTH], true)) {
            $this->cacheInvalidator->invalidate([Translator::ALL_CACHE_TAG]);
        }
    }

    /**
     * @param list<string> $snippetIds
     *
     * @return list<string>
     */
    private function getSetIdsBySnippetIds(array $snippetIds): array
    {
        if ($snippetIds === []) {
            return [];
        }

        /** @var list<string> $result */
        $result = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(`snippet_set_id`)) FROM `snippet` WHERE `id` IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($snippetIds)],
            ['ids' => ArrayParameterType::BINARY]
        );

        return $result;
    }

    /**
     * @param list<string> $setIds
     *
     * @return list<string>
     */
    private function expandToSelfAndDescendants(array $setIds): array
    {
        $all = [];
        foreach ($setIds as $setId) {
            $setId = strtolower($setId);
            $all[$setId] = true;
            foreach ($this->resolver->collectDescendants($setId) as $descendantId) {
                $all[$descendantId] = true;
            }
        }

        return array_keys($all);
    }

    /**
     * @param list<string> $setIds
     */
    private function invalidate(array $setIds): void
    {
        if ($setIds === []) {
            return;
        }

        $this->cacheInvalidator->invalidate(array_map(Translator::tag(...), $setIds));
    }
}
