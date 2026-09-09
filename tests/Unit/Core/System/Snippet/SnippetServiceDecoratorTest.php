<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Tests\Unit\Core\System\Snippet;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scythe\SnippetSetInheritance\Core\System\Snippet\SnippetServiceDecorator;
use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceResolver;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Snippet\SnippetService;
use Symfony\Component\Translation\MessageCatalogue;

#[CoversClass(SnippetServiceDecorator::class)]
class SnippetServiceDecoratorTest extends TestCase
{
    private const string CHILD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string PARENT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string GRANDPARENT = 'cccccccccccccccccccccccccccccccc';
    private const string FALLBACK = 'dddddddddddddddddddddddddddddddd';

    // --- getStorefrontSnippets ------------------------------------------------

    public function testStorefrontReturnsBaseUnchangedWithoutChain(): void
    {
        $decorator = $this->decorator(
            storefront: [self::CHILD => ['a' => 'child-a']],
            chain: [],
            db: [],
        );

        static::assertSame(
            ['a' => 'child-a'],
            $decorator->getStorefrontSnippets($this->catalogue(), self::CHILD)
        );
    }

    public function testStorefrontInheritsMaintainedKeyFromParent(): void
    {
        $decorator = $this->decorator(
            storefront: [
                self::CHILD => ['a' => 'child-a'],
                self::PARENT => ['b' => 'parent-b'],
            ],
            chain: [self::PARENT],
            db: [[self::PARENT, 'b', 'parent-b']],
        );

        static::assertSame(
            ['a' => 'child-a', 'b' => 'parent-b'],
            $decorator->getStorefrontSnippets($this->catalogue(), self::CHILD)
        );
    }

    public function testStorefrontInheritedMaintainedValueOutranksOwnBaseFile(): void
    {
        $decorator = $this->decorator(
            storefront: [
                self::CHILD => ['a' => 'child-a', 'b' => 'child-b-from-file'],
                self::PARENT => ['b' => 'parent-b'],
            ],
            chain: [self::PARENT],
            db: [[self::PARENT, 'b', 'parent-b']],
        );

        $result = $decorator->getStorefrontSnippets($this->catalogue(), self::CHILD);

        static::assertSame('parent-b', $result['b']);
        static::assertSame('child-a', $result['a']);
    }

    public function testStorefrontNeverOverridesMaintainedChildValue(): void
    {
        $decorator = $this->decorator(
            storefront: [
                self::CHILD => ['a' => 'child-a'],
                self::PARENT => ['a' => 'parent-a'],
            ],
            chain: [self::PARENT],
            db: [[self::CHILD, 'a', 'child-a'], [self::PARENT, 'a', 'parent-a']],
        );

        static::assertSame(
            ['a' => 'child-a'],
            $decorator->getStorefrontSnippets($this->catalogue(), self::CHILD)
        );
    }

    public function testStorefrontMaintainedGrandparentValueBeatsParentBaseFile(): void
    {
        // Multi-level: parent has the key only in its base file, grandparent
        // maintains it in DB — the DB value must win.
        $decorator = $this->decorator(
            storefront: [
                self::CHILD => ['k' => 'child-file'],
                self::PARENT => ['k' => 'parent-file'],
                self::GRANDPARENT => ['k' => 'grandparent-db'],
            ],
            chain: [self::PARENT, self::GRANDPARENT],
            db: [[self::GRANDPARENT, 'k', 'grandparent-db']],
        );

        static::assertSame(
            'grandparent-db',
            $decorator->getStorefrontSnippets($this->catalogue(), self::CHILD)['k']
        );
    }

    public function testStorefrontParentBaseFileOutranksChildBaseFile(): void
    {
        // The parent's effective value includes the parent's own base file, and
        // that still outranks the child's own base file.
        $decorator = $this->decorator(
            storefront: [
                self::CHILD => ['k' => 'child-file'],
                self::PARENT => ['k' => 'parent-file'],
            ],
            chain: [self::PARENT],
            db: [],
        );

        static::assertSame(
            'parent-file',
            $decorator->getStorefrontSnippets($this->catalogue(), self::CHILD)['k']
        );
    }

    public function testStorefrontFallsThroughToBaseFilesNearestFirst(): void
    {
        $decorator = $this->decorator(
            storefront: [
                self::CHILD => [],
                self::PARENT => ['k' => 'parent-file'],
                self::GRANDPARENT => ['k' => 'grandparent-file'],
            ],
            chain: [self::PARENT, self::GRANDPARENT],
            db: [],
        );

        static::assertSame(
            'parent-file',
            $decorator->getStorefrontSnippets($this->catalogue(), self::CHILD)['k']
        );
    }

    public function testStorefrontUsesConfiguredFallbackSet(): void
    {
        $decorator = $this->decorator(
            storefront: [
                self::CHILD => [],
                self::PARENT => [],
                self::FALLBACK => ['c' => 'fallback-c'],
            ],
            chain: [self::PARENT, self::FALLBACK],
            db: [[self::FALLBACK, 'c', 'fallback-c']],
        );

        static::assertSame(
            'fallback-c',
            $decorator->getStorefrontSnippets($this->catalogue(), self::CHILD)['c']
        );
    }

    public function testStorefrontSkipsEmptyAncestorValues(): void
    {
        $decorator = $this->decorator(
            storefront: [
                self::CHILD => [],
                self::PARENT => ['b' => ''],
                self::GRANDPARENT => ['b' => 'grandparent-b'],
            ],
            chain: [self::PARENT, self::GRANDPARENT],
            db: [[self::GRANDPARENT, 'b', 'grandparent-b']],
        );

        static::assertSame(
            'grandparent-b',
            $decorator->getStorefrontSnippets($this->catalogue(), self::CHILD)['b']
        );
    }

    // --- getList ------------------------------------------------------------

    public function testGetListEnrichesUnmaintainedCell(): void
    {
        $decorator = $this->listDecorator(
            data: ['foo.bar' => [
                $this->cell(self::PARENT, value: 'parent value', id: 'snippet-1', hasFileValue: false),
                $this->cell(self::CHILD, value: '', id: null, hasFileValue: false),
                $this->cell(self::FALLBACK, value: '', id: null, hasFileValue: false),
            ]],
            chains: [self::CHILD => [self::PARENT]],
            names: [self::PARENT => 'Parent set'],
        );

        $result = $decorator->getList(1, 25, Context::createDefaultContext(), [], []);
        $child = $result['data']['foo.bar'][1];

        static::assertTrue($child['inherited']);
        static::assertSame('parent value', $child['value']);
        static::assertSame('parent value', $child['resetTo']);
        static::assertSame(self::PARENT, $child['inheritedFromSnippetSetId']);
        static::assertSame('Parent set', $child['inheritedFromSnippetSetName']);

        static::assertArrayNotHasKey('inherited', $result['data']['foo.bar'][0]);
        static::assertArrayNotHasKey('inherited', $result['data']['foo.bar'][2]);
    }

    public function testGetListPointsMaintainedCellResetToTheInheritedValue(): void
    {
        // Child overrides the value; the editor's "Original" must be the
        // inherited parent value, not the child's base file.
        $decorator = $this->listDecorator(
            data: ['foo.bar' => [
                $this->cell(self::PARENT, value: '5555', id: 'snippet-1', hasFileValue: true),
                $this->cell(self::CHILD, value: '6666', id: 'snippet-2', hasFileValue: true, resetTo: '0180'),
            ]],
            chains: [self::CHILD => [self::PARENT]],
            names: [self::PARENT => 'de-DE'],
        );

        $result = $decorator->getList(1, 25, Context::createDefaultContext(), [], []);
        $child = $result['data']['foo.bar'][1];

        static::assertSame('6666', $child['value'], 'own value untouched');
        static::assertArrayNotHasKey('inherited', $child, 'value is not inherited, only the reset target');
        static::assertSame('5555', $child['resetTo']);
        static::assertSame('de-DE', $child['inheritedFromSnippetSetName']);
    }

    public function testGetListInheritsOverAnOwnBaseFileValue(): void
    {
        $decorator = $this->listDecorator(
            data: ['foo.bar' => [
                $this->cell(self::PARENT, value: '5555', id: 'snippet-1', hasFileValue: true),
                $this->cell(self::CHILD, value: '0180', id: null, hasFileValue: true, resetTo: '0180'),
            ]],
            chains: [self::CHILD => [self::PARENT]],
            names: [self::PARENT => 'de-DE'],
        );

        $result = $decorator->getList(1, 25, Context::createDefaultContext(), [], []);
        $child = $result['data']['foo.bar'][1];

        static::assertTrue($child['inherited']);
        static::assertSame('5555', $child['value']);
        static::assertSame('5555', $child['resetTo']);
    }

    public function testGetListMultiLevelMaintainedGrandparentBeatsParentBaseFile(): void
    {
        $decorator = $this->listDecorator(
            data: ['foo.bar' => [
                $this->cell(self::GRANDPARENT, value: 'gp value', id: 'snippet-g', hasFileValue: true),
                $this->cell(self::PARENT, value: 'parent file', id: null, hasFileValue: true),
                $this->cell(self::CHILD, value: 'child file', id: null, hasFileValue: true),
            ]],
            chains: [self::CHILD => [self::PARENT, self::GRANDPARENT]],
            names: [self::GRANDPARENT => 'Grandparent'],
        );

        $result = $decorator->getList(1, 25, Context::createDefaultContext(), [], []);
        $child = $result['data']['foo.bar'][2];

        static::assertSame('gp value', $child['value']);
        static::assertSame(self::GRANDPARENT, $child['inheritedFromSnippetSetId']);
    }

    public function testGetListLeavesCellsWithoutAChainUntouched(): void
    {
        $decorator = $this->listDecorator(
            data: ['foo.bar' => [
                $this->cell(self::CHILD, value: 'child file', id: null, hasFileValue: true, resetTo: 'child file'),
            ]],
            chains: [self::CHILD => []],
            names: [],
        );

        $result = $decorator->getList(1, 25, Context::createDefaultContext(), [], []);

        static::assertArrayNotHasKey('inherited', $result['data']['foo.bar'][0]);
        static::assertSame('child file', $result['data']['foo.bar'][0]['resetTo']);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<string, array<string, string>> $storefront setId => (key => value) returned by inner::getStorefrontSnippets
     * @param list<string> $chain
     * @param list<array{0: string, 1: string, 2: string}> $db rows [setId, key, value] of maintained snippets
     */
    private function decorator(array $storefront, array $chain, array $db): SnippetServiceDecorator
    {
        $inner = $this->createStub(SnippetService::class);
        $inner->method('getStorefrontSnippets')->willReturnCallback(
            static fn (MessageCatalogue $c, string $setId): array => $storefront[$setId] ?? []
        );

        $resolver = $this->createStub(SnippetInheritanceResolver::class);
        $resolver->method('resolveFallbackChain')->willReturn($chain);

        $rows = array_map(
            static fn (array $r): array => ['set_id' => strtolower($r[0]), 'translation_key' => $r[1], 'value' => $r[2]],
            $db
        );
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return new SnippetServiceDecorator($inner, $resolver, $connection);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $data
     * @param array<string, list<string>> $chains
     * @param array<string, string> $names
     */
    private function listDecorator(array $data, array $chains, array $names): SnippetServiceDecorator
    {
        $inner = $this->createStub(SnippetService::class);
        $inner->method('getList')->willReturn(['total' => \count($data), 'data' => $data]);

        $resolver = $this->createStub(SnippetInheritanceResolver::class);
        $resolver->method('resolveFallbackChain')->willReturnCallback(
            static fn (string $setId): array => $chains[strtolower($setId)] ?? []
        );
        $resolver->method('getSetNames')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key($names, array_flip(array_map('strtolower', $ids)))
        );

        return new SnippetServiceDecorator($inner, $resolver, $this->createStub(Connection::class));
    }

    private function catalogue(): MessageCatalogue
    {
        return new MessageCatalogue('en-GB');
    }

    /**
     * @return array<string, mixed>
     */
    private function cell(string $setId, string $value, ?string $id, bool $hasFileValue, string $resetTo = ''): array
    {
        return [
            'value' => $value,
            'origin' => '',
            'resetTo' => $resetTo,
            'translationKey' => 'foo.bar',
            'author' => '',
            'id' => $id,
            'setId' => $setId,
            'hasFileValue' => $hasFileValue,
        ];
    }
}
