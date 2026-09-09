<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Tests\Unit\Inheritance;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scythe\SnippetSetInheritance\Config\PluginConfig;
use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceResolver;

#[CoversClass(SnippetInheritanceResolver::class)]
class SnippetInheritanceResolverTest extends TestCase
{
    /**
     * c -> b -> a, d -> b, e stand-alone, fb the fallback set.
     */
    private const array GRAPH = [
        'a' => null,
        'b' => 'a',
        'c' => 'b',
        'd' => 'b',
        'e' => null,
        'fb' => null,
    ];

    public function testGetParentId(): void
    {
        $resolver = $this->createResolver(self::GRAPH);

        static::assertSame('b', $resolver->getParentId('c'));
        static::assertNull($resolver->getParentId('a'));
        static::assertNull($resolver->getParentId('unknown'));
    }

    public function testResolveAncestorChainIsNearestFirst(): void
    {
        $resolver = $this->createResolver(self::GRAPH);

        static::assertSame(['b', 'a'], $resolver->resolveAncestorChain('c'));
        static::assertSame(['a'], $resolver->resolveAncestorChain('b'));
        static::assertSame([], $resolver->resolveAncestorChain('a'));
    }

    public function testResolveAncestorChainRespectsConfiguredMaxDepth(): void
    {
        $graph = ['l0' => null, 'l1' => 'l0', 'l2' => 'l1', 'l3' => 'l2', 'l4' => 'l3'];

        $resolver = $this->createResolver($graph, maxDepth: 2);

        static::assertSame(['l3', 'l2'], $resolver->resolveAncestorChain('l4'));
    }

    public function testResolveAncestorChainStopsOnPreExistingCycle(): void
    {
        // Defensive: a corrupt graph a -> b -> a must not loop forever.
        $resolver = $this->createResolver(['a' => 'b', 'b' => 'a']);

        // start node 'a' is pre-seeded into the visited set, so traversal stops
        // as soon as the chain loops back to it
        static::assertSame(['b'], $resolver->resolveAncestorChain('a'));
    }

    public function testResolveFallbackChainAppendsConfiguredFallbackSet(): void
    {
        $resolver = $this->createResolver(self::GRAPH, fallbackSetId: 'fb');

        static::assertSame(['b', 'a', 'fb'], $resolver->resolveFallbackChain('c'));
    }

    public function testResolveFallbackChainSkipsFallbackWhenAlreadyInChainOrSelf(): void
    {
        $resolver = $this->createResolver(self::GRAPH, fallbackSetId: 'a');
        static::assertSame(['b', 'a'], $resolver->resolveFallbackChain('c'), 'fallback already an ancestor');

        $resolver = $this->createResolver(self::GRAPH, fallbackSetId: 'c');
        static::assertSame(['b', 'a'], $resolver->resolveFallbackChain('c'), 'fallback is the set itself');
    }

    public function testResolveFallbackChainWithoutConfiguredFallback(): void
    {
        $resolver = $this->createResolver(self::GRAPH, fallbackSetId: null);

        static::assertSame(['b', 'a'], $resolver->resolveFallbackChain('c'));
    }

    public function testWouldCreateCycleOrSelfReference(): void
    {
        $resolver = $this->createResolver(self::GRAPH);

        // self-reference
        static::assertTrue($resolver->wouldCreateCycleOrSelfReference('a', 'a'));
        // direct cycle: making a inherit from b, while b -> a already
        static::assertTrue($resolver->wouldCreateCycleOrSelfReference('a', 'b'));
        // indirect cycle: making a inherit from c, while c -> b -> a
        static::assertTrue($resolver->wouldCreateCycleOrSelfReference('a', 'c'));
        // no cycle: making e inherit from c
        static::assertFalse($resolver->wouldCreateCycleOrSelfReference('e', 'c'));
        // clearing the parent is always fine
        static::assertFalse($resolver->wouldCreateCycleOrSelfReference('c', null));
    }

    public function testCollectDescendantsIsRecursive(): void
    {
        $graph = [
            'root' => null,
            'x' => 'root',
            'y' => 'root',
            'x1' => 'x',
            'x2' => 'x',
            'x1a' => 'x1',
        ];

        $resolver = $this->createResolver($graph);

        $descendants = $resolver->collectDescendants('root');
        sort($descendants);

        static::assertSame(['x', 'x1', 'x1a', 'x2', 'y'], $descendants);
        static::assertSame(['x1', 'x1a', 'x2'], $this->sorted($resolver->collectDescendants('x')));
        static::assertSame([], $resolver->collectDescendants('x1a'));
    }

    public function testCollectDescendantsHandlesCycleDefensively(): void
    {
        $resolver = $this->createResolver(['a' => 'b', 'b' => 'a']);

        static::assertSame(['b'], $resolver->collectDescendants('a'));
    }

    public function testGetSetNames(): void
    {
        $resolver = $this->createResolver(self::GRAPH);

        static::assertSame(['a' => 'name-a', 'b' => 'name-b'], $resolver->getSetNames(['a', 'B']));
        static::assertSame([], $resolver->getSetNames([]));
    }

    /**
     * @param array<string, string|null> $graph child => parent
     */
    private function createResolver(array $graph, ?string $fallbackSetId = null, int $maxDepth = 10): SnippetInheritanceResolver
    {
        $rows = [];
        foreach ($graph as $id => $parentId) {
            $rows[] = ['id' => $id, 'parent_id' => $parentId, 'name' => 'name-' . $id];
        }

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        $pluginConfig = $this->createStub(PluginConfig::class);
        $pluginConfig->method('getFallbackSnippetSetId')->willReturn($fallbackSetId);
        $pluginConfig->method('getMaxInheritanceDepth')->willReturn($maxDepth);

        return new SnippetInheritanceResolver($connection, $pluginConfig);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
