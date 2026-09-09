<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Core\System\Snippet;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceResolver;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Snippet\SnippetService;
use Symfony\Component\Translation\MessageCatalogueInterface;

/**
 * Decorates the core {@see SnippetService} at the two points where it assembles
 * a snippet catalog for a whole set:
 *
 *  - {@see self::getStorefrontSnippets()} — the per-set catalog that
 *    {@see \Shopware\Core\Framework\Adapter\Translation\Translator::loadSnippets()}
 *    caches and hands to the storefront translator.
 *  - {@see self::getList()} — the admin snippet grid / editor data
 *    (POST /api/_action/snippet-set, {@see \Shopware\Core\System\Snippet\Api\SnippetController}).
 *
 * For a key the child set does not maintain itself, resolution walks
 * {@see SnippetInheritanceResolver::resolveFallbackChain()} (parent chain +
 * fallback set) in two passes: first a value maintained (DB) anywhere in the
 * chain, then a base-file value — nearest set winning within each pass. So a DB
 * value anywhere in the chain always beats any base-file value, and a parent's
 * base file still beats the child's. Storefront resolution runs only on a cache
 * miss.
 *
 * The core `SnippetService` is only ever consumed through its public surface
 * (`Translator` + `SnippetController`), so this decorator extends the class for
 * type compatibility, deliberately does NOT call the parent constructor,
 * overrides the two methods it changes and forwards the other three to $inner.
 */
#[Package('discovery')]
class SnippetServiceDecorator extends SnippetService
{
    public function __construct(
        private readonly SnippetService $inner,
        private readonly SnippetInheritanceResolver $resolver,
        private readonly Connection $connection,
    ) {
        // no parent::__construct() on purpose — see class docblock
    }

    /**
     * Enriches the core result: an unmaintained cell whose chain resolves a
     * value gets that value plus the `inherited` flag; a maintained cell keeps
     * its value but has `resetTo` pointed at the inherited value (so the editor
     * shows what a reset falls back to). `inheritedFromSnippetSetId` /
     * `inheritedFromSnippetSetName` are set whenever the chain resolves a value.
     */
    public function getList(int $page, int $limit, Context $context, array $requestFilters, array $sort): array
    {
        $result = $this->inner->getList($page, $limit, $context, $requestFilters, $sort);

        if (!isset($result['data']) || !\is_array($result['data'])) {
            return $result;
        }

        /** @var array<string, list<string>> $chains */
        $chains = [];
        /** @var array<string, string|null> $names */
        $names = [];

        foreach ($result['data'] as &$snippets) {
            if (!\is_array($snippets) || $snippets === []) {
                continue;
            }

            // Snapshot before mutating so ancestor look-ups are order-independent.
            $ownBySet = [];
            foreach ($snippets as $snippet) {
                $ownBySet[strtolower((string) $snippet['setId'])] = [
                    'value' => (string) ($snippet['value'] ?? ''),
                    'maintained' => ($snippet['id'] ?? null) !== null,
                    'hasFileValue' => !empty($snippet['hasFileValue']),
                ];
            }

            foreach ($snippets as &$snippet) {
                $setId = strtolower((string) $snippet['setId']);
                $chains[$setId] ??= $this->resolver->resolveFallbackChain($setId);
                if ($chains[$setId] === []) {
                    continue;
                }

                [$inheritedValue, $inheritedFrom] = $this->resolveInheritedFromCells($chains[$setId], $ownBySet);
                if ($inheritedValue === null || $inheritedFrom === null) {
                    continue;
                }

                if (!\array_key_exists($inheritedFrom, $names)) {
                    $names[$inheritedFrom] = $this->resolver->getSetNames([$inheritedFrom])[$inheritedFrom] ?? null;
                }

                $snippet['resetTo'] = $inheritedValue;
                $snippet['inheritedFromSnippetSetId'] = $inheritedFrom;
                $snippet['inheritedFromSnippetSetName'] = $names[$inheritedFrom];

                if (($snippet['id'] ?? null) === null) {
                    $snippet['value'] = $inheritedValue;
                    $snippet['origin'] = $inheritedValue;
                    $snippet['inherited'] = true;
                }
            }
            unset($snippet);
        }
        unset($snippets);

        return $result;
    }

    public function getStorefrontSnippets(MessageCatalogueInterface $catalog, string $snippetSetId, ?string $fallbackLocale = null, ?string $salesChannelId = null): array
    {
        $base = $this->inner->getStorefrontSnippets($catalog, $snippetSetId, $fallbackLocale, $salesChannelId);

        $chain = $this->resolver->resolveFallbackChain($snippetSetId);
        if ($chain === []) {
            return $base;
        }

        $dbBySet = $this->fetchMaintainedSnippets([$snippetSetId, ...$chain]);
        $childDb = $dbBySet[strtolower($snippetSetId)] ?? [];

        $inherited = [];

        // Pass 1: values maintained (DB) anywhere in the chain, nearest first.
        foreach ($chain as $ancestorId) {
            foreach ($dbBySet[$ancestorId] ?? [] as $key => $value) {
                if ($value === '' || \array_key_exists($key, $childDb) || \array_key_exists($key, $inherited)) {
                    continue;
                }
                $inherited[$key] = $value;
            }
        }

        // Pass 2: remaining keys from the chain's base files, nearest first.
        foreach ($chain as $ancestorId) {
            foreach ($this->inner->getStorefrontSnippets($catalog, $ancestorId, $fallbackLocale, $salesChannelId) as $key => $value) {
                if ($value === '' || \array_key_exists($key, $childDb) || \array_key_exists($key, $inherited)) {
                    continue;
                }
                $inherited[$key] = $value;
            }
        }

        return array_replace($base, $inherited);
    }

    public function getRegionFilterItems(Context $context): array
    {
        return $this->inner->getRegionFilterItems($context);
    }

    public function getAuthors(Context $context): array
    {
        return $this->inner->getAuthors($context);
    }

    public function findSnippetSetId(string $salesChannelId, string $languageId, string $locale): string
    {
        return $this->inner->findSnippetSetId($salesChannelId, $languageId, $locale);
    }

    /**
     * @param list<string> $chain nearest-first
     * @param array<string, array{value: string, maintained: bool, hasFileValue: bool}> $ownBySet
     *
     * @return array{0: string|null, 1: string|null} [inherited value, source set id]
     */
    private function resolveInheritedFromCells(array $chain, array $ownBySet): array
    {
        // Pass 1: nearest chain member that maintains the key itself.
        foreach ($chain as $ancestorId) {
            $cell = $ownBySet[$ancestorId] ?? null;
            if ($cell !== null && $cell['maintained'] && $cell['value'] !== '') {
                return [$cell['value'], $ancestorId];
            }
        }

        // Pass 2: nearest chain member that has a base-file value.
        foreach ($chain as $ancestorId) {
            $cell = $ownBySet[$ancestorId] ?? null;
            if ($cell !== null && $cell['hasFileValue'] && $cell['value'] !== '') {
                return [$cell['value'], $ancestorId];
            }
        }

        return [null, null];
    }

    /**
     * @param list<string> $snippetSetIds
     *
     * @return array<string, array<string, string>> set id => (translation key => value)
     */
    private function fetchMaintainedSnippets(array $snippetSetIds): array
    {
        $unique = array_values(array_unique(array_map('strtolower', $snippetSetIds)));
        if ($unique === []) {
            return [];
        }

        /** @var list<array{set_id: string, translation_key: string, value: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`snippet_set_id`)) AS set_id, `translation_key`, `value`
             FROM `snippet` WHERE `snippet_set_id` IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($unique)],
            ['ids' => ArrayParameterType::BINARY]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['set_id']][$row['translation_key']] = $row['value'];
        }

        return $result;
    }
}
