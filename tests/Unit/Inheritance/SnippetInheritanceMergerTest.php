<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Tests\Unit\Inheritance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceMerger;
use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceResolver;

#[CoversClass(SnippetInheritanceMerger::class)]
class SnippetInheritanceMergerTest extends TestCase
{
    private const string CHILD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string PARENT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string GRANDPARENT = 'cccccccccccccccccccccccccccccccc';
    private const string FALLBACK = 'dddddddddddddddddddddddddddddddd';

    public function testEnrichesUnmaintainedCell(): void
    {
        $merger = $this->merger(
            chains: [self::CHILD => [self::PARENT]],
            names: [self::PARENT => 'Parent set'],
        );

        $result = $merger->enrichAdminList(['total' => 1, 'data' => ['foo.bar' => [
            $this->cell(self::PARENT, value: 'parent value', id: 'snippet-1', hasFileValue: false),
            $this->cell(self::CHILD, value: '', id: null, hasFileValue: false),
            $this->cell(self::FALLBACK, value: '', id: null, hasFileValue: false),
        ]]]);

        $child = $result['data']['foo.bar'][1];
        static::assertTrue($child['inherited']);
        static::assertSame('parent value', $child['value']);
        static::assertSame('parent value', $child['resetTo']);
        static::assertSame(self::PARENT, $child['inheritedFromSnippetSetId']);
        static::assertSame('Parent set', $child['inheritedFromSnippetSetName']);

        static::assertArrayNotHasKey('inherited', $result['data']['foo.bar'][0]);
        static::assertArrayNotHasKey('inherited', $result['data']['foo.bar'][2]);
    }

    public function testPointsMaintainedCellResetToTheInheritedValue(): void
    {
        // Child overrides the value; the editor's "Original" must be the
        // inherited parent value, not the child's base file.
        $merger = $this->merger(
            chains: [self::CHILD => [self::PARENT]],
            names: [self::PARENT => 'de-DE'],
        );

        $result = $merger->enrichAdminList(['total' => 1, 'data' => ['foo.bar' => [
            $this->cell(self::PARENT, value: '5555', id: 'snippet-1', hasFileValue: true),
            $this->cell(self::CHILD, value: '6666', id: 'snippet-2', hasFileValue: true, resetTo: '0180'),
        ]]]);

        $child = $result['data']['foo.bar'][1];
        static::assertSame('6666', $child['value'], 'own value untouched');
        static::assertArrayNotHasKey('inherited', $child, 'value is not inherited, only the reset target');
        static::assertSame('5555', $child['resetTo']);
        static::assertSame('de-DE', $child['inheritedFromSnippetSetName']);
    }

    public function testInheritsOverAnOwnBaseFileValue(): void
    {
        $merger = $this->merger(
            chains: [self::CHILD => [self::PARENT]],
            names: [self::PARENT => 'de-DE'],
        );

        $result = $merger->enrichAdminList(['total' => 1, 'data' => ['foo.bar' => [
            $this->cell(self::PARENT, value: '5555', id: 'snippet-1', hasFileValue: true),
            $this->cell(self::CHILD, value: '0180', id: null, hasFileValue: true, resetTo: '0180'),
        ]]]);

        $child = $result['data']['foo.bar'][1];
        static::assertTrue($child['inherited']);
        static::assertSame('5555', $child['value']);
        static::assertSame('5555', $child['resetTo']);
    }

    public function testMultiLevelMaintainedGrandparentBeatsParentBaseFile(): void
    {
        $merger = $this->merger(
            chains: [self::CHILD => [self::PARENT, self::GRANDPARENT]],
            names: [self::GRANDPARENT => 'Grandparent'],
        );

        $result = $merger->enrichAdminList(['total' => 1, 'data' => ['foo.bar' => [
            $this->cell(self::GRANDPARENT, value: 'gp value', id: 'snippet-g', hasFileValue: true),
            $this->cell(self::PARENT, value: 'parent file', id: null, hasFileValue: true),
            $this->cell(self::CHILD, value: 'child file', id: null, hasFileValue: true),
        ]]]);

        $child = $result['data']['foo.bar'][2];
        static::assertSame('gp value', $child['value']);
        static::assertSame(self::GRANDPARENT, $child['inheritedFromSnippetSetId']);
    }

    public function testLeavesCellsWithoutAChainUntouched(): void
    {
        $merger = $this->merger(chains: [self::CHILD => []], names: []);

        $result = $merger->enrichAdminList(['total' => 1, 'data' => ['foo.bar' => [
            $this->cell(self::CHILD, value: 'child file', id: null, hasFileValue: true, resetTo: 'child file'),
        ]]]);

        static::assertArrayNotHasKey('inherited', $result['data']['foo.bar'][0]);
        static::assertSame('child file', $result['data']['foo.bar'][0]['resetTo']);
    }

    public function testReturnsResultUnchangedWithoutData(): void
    {
        $merger = $this->merger(chains: [], names: []);

        static::assertSame(['total' => 0], $merger->enrichAdminList(['total' => 0]));
    }

    /**
     * @param array<string, list<string>> $chains
     * @param array<string, string> $names
     */
    private function merger(array $chains, array $names): SnippetInheritanceMerger
    {
        $resolver = $this->createStub(SnippetInheritanceResolver::class);
        $resolver->method('resolveFallbackChain')->willReturnCallback(
            static fn (string $setId): array => $chains[strtolower($setId)] ?? []
        );
        $resolver->method('getSetNames')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key($names, array_flip(array_map('strtolower', $ids)))
        );

        return new SnippetInheritanceMerger($resolver);
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
