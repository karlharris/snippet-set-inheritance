<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Inheritance;

/**
 * Enriches the payload of the core snippet list / editor endpoint
 * ({@see \Shopware\Core\System\Snippet\SnippetService::getList()}) with
 * inheritance information, so the Administration can display inherited values
 * and where they come from.
 *
 * This is display-only. The storefront resolves inheritance independently in
 * {@see \Scythe\SnippetSetInheritance\Subscriber\StorefrontSnippetsSubscriber};
 * both share {@see SnippetInheritanceResolver} for the graph.
 */
final class SnippetInheritanceMerger
{
    public function __construct(private readonly SnippetInheritanceResolver $resolver)
    {
    }

    /**
     * For every cell whose set resolves a value for that key through the parent
     * chain or the configured fallback set:
     *
     *  - an unmaintained cell gets that value, its origin and the `inherited`
     *    flag (the inherited value outranks the set's own base file);
     *  - a maintained cell keeps its value but has `resetTo` pointed at the
     *    inherited value, so the single-snippet editor shows what a reset falls
     *    back to.
     *
     * `inheritedFromSnippetSetId` / `inheritedFromSnippetSetName` are set
     * whenever the chain resolves a value.
     *
     * @param array<string, mixed> $result the untouched `SnippetService::getList()` return value
     *
     * @return array<string, mixed>
     */
    public function enrichAdminList(array $result): array
    {
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
}
