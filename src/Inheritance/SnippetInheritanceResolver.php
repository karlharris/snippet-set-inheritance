<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Inheritance;

use Doctrine\DBAL\Connection;
use Scythe\SnippetSetInheritance\Config\PluginConfig;

/**
 * Graph operations on the `snippet_set` parent/child relation — chain traversal,
 * cycle detection, descendant collection. It does not resolve snippet values;
 * the storefront and admin decorators feed it and apply the merge themselves.
 *
 * All ids passed in and returned are lowercase hex. Every public method reloads
 * the full adjacency once rather than memoising, so results stay correct when a
 * caller runs right after a write (e.g. the cache-invalidation subscriber);
 * `snippet_set` is tiny enough that this is cheap.
 */
class SnippetInheritanceResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PluginConfig $pluginConfig,
    ) {
    }

    public function getParentId(string $snippetSetId): ?string
    {
        return $this->loadAdjacency()['parents'][strtolower($snippetSetId)] ?? null;
    }

    /**
     * Ancestor chain nearest-first: [parentId, grandParentId, …]. Stops at the
     * configured max depth or as soon as a set is seen twice.
     *
     * @return list<string>
     */
    public function resolveAncestorChain(string $snippetSetId): array
    {
        $parents = $this->loadAdjacency()['parents'];
        $maxDepth = $this->pluginConfig->getMaxInheritanceDepth();

        $chain = [];
        $visited = [strtolower($snippetSetId) => true];
        $current = $parents[strtolower($snippetSetId)] ?? null;

        while ($current !== null && !isset($visited[$current]) && \count($chain) < $maxDepth) {
            $chain[] = $current;
            $visited[$current] = true;
            $current = $parents[$current] ?? null;
        }

        return $chain;
    }

    /**
     * The ancestor chain plus the configured fallback set appended as its last
     * member. Deduplicated; never contains the set itself.
     *
     * @return list<string>
     */
    public function resolveFallbackChain(string $snippetSetId): array
    {
        $chain = $this->resolveAncestorChain($snippetSetId);

        $fallbackId = $this->pluginConfig->getFallbackSnippetSetId();
        if ($fallbackId !== null) {
            $fallbackId = strtolower($fallbackId);
            if ($fallbackId !== strtolower($snippetSetId) && !\in_array($fallbackId, $chain, true)) {
                $chain[] = $fallbackId;
            }
        }

        return $chain;
    }

    /**
     * @param list<string> $snippetSetIds
     *
     * @return array<string, string> setId => name
     */
    public function getSetNames(array $snippetSetIds): array
    {
        if ($snippetSetIds === []) {
            return [];
        }

        $names = $this->loadAdjacency()['names'];

        $result = [];
        foreach ($snippetSetIds as $id) {
            $id = strtolower($id);
            if (isset($names[$id])) {
                $result[$id] = $names[$id];
            }
        }

        return $result;
    }

    /**
     * Whether pointing `$snippetSetId` at `$newParentId` would create a
     * self-reference or a cycle. Evaluated against the current, not-yet-written
     * graph — call it from pre-write validation.
     */
    public function wouldCreateCycleOrSelfReference(string $snippetSetId, ?string $newParentId): bool
    {
        if ($newParentId === null) {
            return false;
        }

        $snippetSetId = strtolower($snippetSetId);
        $newParentId = strtolower($newParentId);

        if ($newParentId === $snippetSetId) {
            return true;
        }

        $parents = $this->loadAdjacency()['parents'];

        $visited = [];
        $current = $newParentId;
        while ($current !== null && !isset($visited[$current])) {
            if ($current === $snippetSetId) {
                return true;
            }
            $visited[$current] = true;
            $current = $parents[$current] ?? null;
        }

        return false;
    }

    /**
     * Every descendant set id, recursively. The cache-invalidation subscriber
     * uses this to also invalidate sets whose effective values depend on a
     * changed set.
     *
     * @return list<string>
     */
    public function collectDescendants(string $snippetSetId): array
    {
        $children = $this->loadAdjacency()['children'];

        $result = [];
        $visited = [strtolower($snippetSetId) => true];
        $queue = $children[strtolower($snippetSetId)] ?? [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $result[] = $id;

            foreach ($children[$id] ?? [] as $childId) {
                if (!isset($visited[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return $result;
    }

    /**
     * @return array{parents: array<string, string|null>, children: array<string, list<string>>, names: array<string, string>}
     */
    private function loadAdjacency(): array
    {
        /** @var array<array{id: string, parent_id: string|null, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`id`)) AS id, LOWER(HEX(`parent_id`)) AS parent_id, `name` FROM `snippet_set`'
        );

        $parents = [];
        $children = [];
        $names = [];

        foreach ($rows as $row) {
            $id = $row['id'];
            $parentId = $row['parent_id'];

            $parents[$id] = $parentId;
            $names[$id] = $row['name'];
            $children[$id] ??= [];

            if ($parentId !== null) {
                $children[$parentId][] = $id;
            }
        }

        return ['parents' => $parents, 'children' => $children, 'names' => $names];
    }
}
