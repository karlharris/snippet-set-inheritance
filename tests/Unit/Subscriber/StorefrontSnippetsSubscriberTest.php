<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Tests\Unit\Subscriber;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scythe\SnippetSetInheritance\Config\PluginConfig;
use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceResolver;
use Scythe\SnippetSetInheritance\Subscriber\StorefrontSnippetsSubscriber;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Snippet\Extension\StorefrontSnippetsExtension;
use Shopware\Core\System\Snippet\SnippetService;
use Symfony\Component\Translation\MessageCatalogue;

#[CoversClass(StorefrontSnippetsSubscriber::class)]
class StorefrontSnippetsSubscriberTest extends TestCase
{
    private const string CHILD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string PARENT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string GRANDPARENT = 'cccccccccccccccccccccccccccccccc';
    private const string FALLBACK = 'dddddddddddddddddddddddddddddddd';

    public function testSubscribesToTheStorefrontSnippetsPostEvent(): void
    {
        static::assertSame(
            ['storefront.snippets.post' => 'enrich'],
            StorefrontSnippetsSubscriber::getSubscribedEvents()
        );
    }

    public function testLeavesResultUnchangedWithoutParentOrFallback(): void
    {
        $subscriber = $this->subscriber(
            baseBySet: [self::CHILD => ['a' => 'child-a']],
            parents: [],
            maintainedBySet: [],
        );

        $extension = $this->resolve($subscriber, self::CHILD, ['a' => 'child-a']);

        static::assertSame(['a' => 'child-a'], $extension->result);
    }

    public function testInheritsKeyFromParent(): void
    {
        $subscriber = $this->subscriber(
            baseBySet: [
                self::CHILD => ['a' => 'child-a'],
                self::PARENT => ['b' => 'parent-b'],
            ],
            parents: [self::CHILD => self::PARENT],
            maintainedBySet: [],
        );

        $extension = $this->resolve($subscriber, self::CHILD, ['a' => 'child-a']);

        static::assertSame(['a' => 'child-a', 'b' => 'parent-b'], $extension->result);
    }

    public function testInheritedValueOutranksOwnBaseFile(): void
    {
        $subscriber = $this->subscriber(
            baseBySet: [
                self::CHILD => ['a' => 'child-a', 'b' => 'child-b-file'],
                self::PARENT => ['b' => 'parent-b'],
            ],
            parents: [self::CHILD => self::PARENT],
            maintainedBySet: [],
        );

        $extension = $this->resolve($subscriber, self::CHILD, ['a' => 'child-a', 'b' => 'child-b-file']);

        static::assertSame('parent-b', $extension->result['b']);
        static::assertSame('child-a', $extension->result['a']);
    }

    public function testNeverOverridesAValueMaintainedByTheChild(): void
    {
        $subscriber = $this->subscriber(
            baseBySet: [
                self::CHILD => ['a' => 'child-a'],
                self::PARENT => ['a' => 'parent-a'],
            ],
            parents: [self::CHILD => self::PARENT],
            maintainedBySet: [self::CHILD => ['a']],
        );

        $extension = $this->resolve($subscriber, self::CHILD, ['a' => 'child-a']);

        static::assertSame(['a' => 'child-a'], $extension->result);
    }

    public function testRecursesThroughTheParentChain(): void
    {
        // Parent has the key only in its base file, grandparent maintains it —
        // the parent's *effective* value (grandparent's) is what the child gets.
        $subscriber = $this->subscriber(
            baseBySet: [
                self::CHILD => ['k' => 'child-file'],
                self::PARENT => ['k' => 'parent-file'],
                self::GRANDPARENT => ['k' => 'grandparent-db'],
            ],
            parents: [self::CHILD => self::PARENT, self::PARENT => self::GRANDPARENT],
            maintainedBySet: [self::GRANDPARENT => ['k']],
        );

        $extension = $this->resolve($subscriber, self::CHILD, ['k' => 'child-file']);

        static::assertSame('grandparent-db', $extension->result['k']);
    }

    public function testUsesTheConfiguredFallbackSet(): void
    {
        $subscriber = $this->subscriber(
            baseBySet: [
                self::CHILD => [],
                self::PARENT => [],
                self::FALLBACK => ['c' => 'fallback-c'],
            ],
            parents: [self::CHILD => self::PARENT],
            maintainedBySet: [],
            fallbackId: self::FALLBACK,
        );

        $extension = $this->resolve($subscriber, self::CHILD, []);

        static::assertSame('fallback-c', $extension->result['c']);
    }

    public function testSkipsEmptyAncestorValues(): void
    {
        $subscriber = $this->subscriber(
            baseBySet: [
                self::CHILD => [],
                self::PARENT => ['b' => ''],
                self::FALLBACK => ['b' => 'fallback-b'],
            ],
            parents: [self::CHILD => self::PARENT],
            maintainedBySet: [],
            fallbackId: self::FALLBACK,
        );

        $extension = $this->resolve($subscriber, self::CHILD, []);

        static::assertSame('fallback-b', $extension->result['b']);
    }

    public function testHonoursTheConfiguredMaxDepth(): void
    {
        // grandparent maintains the key, the parent has nothing for it.
        $baseBySet = [
            self::CHILD => [],
            self::PARENT => [],
            self::GRANDPARENT => ['k' => 'grandparent-k'],
        ];
        $parents = [self::CHILD => self::PARENT, self::PARENT => self::GRANDPARENT];
        $maintained = [self::GRANDPARENT => ['k']];

        $capped = $this->subscriber($baseBySet, $parents, $maintained, maxDepth: 1);
        static::assertSame([], $this->resolve($capped, self::CHILD, [])->result, 'depth 1 stops before the grandparent');

        $deep = $this->subscriber($baseBySet, $parents, $maintained, maxDepth: 2);
        static::assertSame('grandparent-k', $this->resolve($deep, self::CHILD, [])->result['k']);
    }

    /**
     * @param array<string, array<string, string>> $baseBySet   set id => core result (before inheritance)
     * @param array<string, string> $parents                    set id => parent set id
     * @param array<string, list<string>> $maintainedBySet       set id => keys maintained in DB
     */
    private function subscriber(
        array $baseBySet,
        array $parents,
        array $maintainedBySet,
        ?string $fallbackId = null,
        int $maxDepth = 10,
    ): StorefrontSnippetsSubscriber {
        $snippetService = $this->createStub(SnippetService::class);
        $resolver = $this->createStub(SnippetInheritanceResolver::class);
        $config = $this->createStub(PluginConfig::class);
        $connection = $this->createStub(Connection::class);

        $config->method('getFallbackSnippetSetId')->willReturn($fallbackId);
        $config->method('getMaxInheritanceDepth')->willReturn($maxDepth);

        $resolver->method('getParentId')->willReturnCallback(
            static fn (string $id): ?string => $parents[strtolower($id)] ?? null
        );

        $connection->method('fetchFirstColumn')->willReturnCallback(
            static fn (string $sql, array $params): array => $maintainedBySet[strtolower(Uuid::fromBytesToHex($params['id']))] ?? []
        );

        $subscriber = new StorefrontSnippetsSubscriber($snippetService, $resolver, $config, $connection);

        // getStorefrontSnippets() for an ancestor re-enters the subscriber, so
        // the value it returns is the ancestor's *effective* catalog.
        $snippetService->method('getStorefrontSnippets')->willReturnCallback(
            function (MessageCatalogue $catalog, string $setId) use (&$subscriber, $baseBySet): array {
                $base = $baseBySet[strtolower($setId)] ?? [];
                $extension = new StorefrontSnippetsExtension($base, 'en-GB', $catalog, $setId, null, null, []);
                $extension->result = $base;
                $subscriber->enrich($extension);

                return \is_array($extension->result) ? $extension->result : [];
            }
        );

        return $subscriber;
    }

    /**
     * @param array<string, string> $base
     */
    private function resolve(StorefrontSnippetsSubscriber $subscriber, string $setId, array $base): StorefrontSnippetsExtension
    {
        $extension = new StorefrontSnippetsExtension($base, 'en-GB', new MessageCatalogue('en-GB'), $setId, null, null, []);
        $extension->result = $base;
        $subscriber->enrich($extension);

        return $extension;
    }
}
