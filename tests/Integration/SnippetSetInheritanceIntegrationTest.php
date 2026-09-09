<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceResolver;
use Scythe\SnippetSetInheritance\Subscriber\SnippetInheritanceCacheInvalidationSubscriber;
use Scythe\SnippetSetInheritance\Subscriber\SnippetSetWriteValidationSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\System\Snippet\SnippetService;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * End-to-end wiring: the `EntityExtension` really persists `parentId`, the
 * resolver reads the graph back, and the validation subscriber blocks
 * self-references / cycles on the real DAL write path.
 */
#[CoversNothing]
class SnippetSetInheritanceIntegrationTest extends TestCase
{
    use IntegrationTestBehaviour;

    private EntityRepository $snippetSetRepository;

    private SnippetInheritanceResolver $resolver;

    private Context $context;

    protected function setUp(): void
    {
        $this->snippetSetRepository = static::getContainer()->get('snippet_set.repository');
        $this->resolver = static::getContainer()->get(SnippetInheritanceResolver::class);
        $this->context = Context::createDefaultContext();
    }

    public function testServicesAreRegistered(): void
    {
        static::assertInstanceOf(
            SnippetInheritanceResolver::class,
            static::getContainer()->get(SnippetInheritanceResolver::class)
        );
        static::assertInstanceOf(
            SnippetInheritanceCacheInvalidationSubscriber::class,
            static::getContainer()->get(SnippetInheritanceCacheInvalidationSubscriber::class)
        );
        static::assertInstanceOf(
            SnippetSetWriteValidationSubscriber::class,
            static::getContainer()->get(SnippetSetWriteValidationSubscriber::class)
        );
    }

    public function testParentIdIsPersistedAndResolvedAsAChain(): void
    {
        $grandParentId = $this->createSnippetSet('SSSI grandparent');
        $parentId = $this->createSnippetSet('SSSI parent', $grandParentId);
        $childId = $this->createSnippetSet('SSSI child', $parentId);

        static::assertSame($parentId, $this->resolver->getParentId($childId));
        static::assertSame([$parentId, $grandParentId], $this->resolver->resolveAncestorChain($childId));

        $descendants = $this->resolver->collectDescendants($grandParentId);
        sort($descendants);
        $expected = [$childId, $parentId];
        sort($expected);
        static::assertSame($expected, $descendants);
    }

    public function testParentAssociationIsReadable(): void
    {
        $parentId = $this->createSnippetSet('SSSI parent assoc');
        $childId = $this->createSnippetSet('SSSI child assoc', $parentId);

        $criteria = new Criteria([$childId]);
        $criteria->addAssociation('parent');

        $child = $this->snippetSetRepository->search($criteria, $this->context)->first();

        static::assertNotNull($child);
        static::assertSame($parentId, $child->get('parentId'));
    }

    public function testSelfReferenceIsRejected(): void
    {
        $id = $this->createSnippetSet('SSSI self ref');

        $this->assertWriteBlocked(
            fn () => $this->snippetSetRepository->update([['id' => $id, 'parentId' => $id]], $this->context),
            'cannot inherit from itself'
        );
    }

    public function testCycleIsRejected(): void
    {
        $aId = $this->createSnippetSet('SSSI cycle a');
        $bId = $this->createSnippetSet('SSSI cycle b', $aId);

        // b already inherits from a; making a inherit from b closes the loop
        $this->assertWriteBlocked(
            fn () => $this->snippetSetRepository->update([['id' => $aId, 'parentId' => $bId]], $this->context),
            'create a cycle'
        );
    }

    public function testChangingParentToNullIsAllowed(): void
    {
        $parentId = $this->createSnippetSet('SSSI detach parent');
        $childId = $this->createSnippetSet('SSSI detach child', $parentId);

        $this->snippetSetRepository->update([['id' => $childId, 'parentId' => null]], $this->context);

        static::assertNull($this->resolver->getParentId($childId));
    }

    public function testStorefrontCatalogInheritsFromParentThroughTheDecorator(): void
    {
        $parentId = $this->createSnippetSet('SSSI storefront parent');
        $childId = $this->createSnippetSet('SSSI storefront child', $parentId);

        $key = 'scythe.sssi.test.' . Uuid::randomHex();

        static::getContainer()->get('snippet.repository')->create([[
            'translationKey' => $key,
            'value' => 'value from parent',
            'setId' => $parentId,
            'author' => 'test',
        ]], $this->context);

        /** @var SnippetService $snippetService */
        $snippetService = static::getContainer()->get(SnippetService::class);

        $childCatalog = $snippetService->getStorefrontSnippets(new MessageCatalogue('en-GB'), $childId, 'en');
        static::assertSame('value from parent', $childCatalog[$key] ?? null);

        // A maintained value in the child must win over the inherited one.
        static::getContainer()->get('snippet.repository')->create([[
            'translationKey' => $key,
            'value' => 'value from child',
            'setId' => $childId,
            'author' => 'test',
        ]], $this->context);

        $childCatalog = $snippetService->getStorefrontSnippets(new MessageCatalogue('en-GB'), $childId, 'en');
        static::assertSame('value from child', $childCatalog[$key] ?? null);
    }

    public function testAdminListingReportsInheritedOriginForAnOverriddenChildSnippet(): void
    {
        // Parent maintains "5555", child overrides with "6666" — the editor's
        // "Original" must be the inherited "5555", not the base-file default.
        $parentId = $this->createSnippetSet('SSSI list parent');
        $childId = $this->createSnippetSet('SSSI list child', $parentId);

        $key = 'scythe.sssi.list.' . Uuid::randomHex();

        static::getContainer()->get('snippet.repository')->create([
            ['translationKey' => $key, 'value' => '5555', 'setId' => $parentId, 'author' => 'test'],
            ['translationKey' => $key, 'value' => '6666', 'setId' => $childId, 'author' => 'test'],
        ], $this->context);

        /** @var SnippetService $snippetService */
        $snippetService = static::getContainer()->get(SnippetService::class);
        $list = $snippetService->getList(1, 100, $this->context, ['term' => $key], []);

        $childCell = null;
        foreach ($list['data'][$key] ?? [] as $cell) {
            if ($cell['setId'] === $childId) {
                $childCell = $cell;
            }
        }

        static::assertNotNull($childCell);
        static::assertSame('6666', $childCell['value']);
        static::assertSame('5555', $childCell['resetTo']);
        static::assertArrayNotHasKey('inherited', $childCell);
        static::assertSame(
            static::getContainer()->get(SnippetInheritanceResolver::class)->getSetNames([$parentId])[$parentId],
            $childCell['inheritedFromSnippetSetName']
        );
    }

    private function assertWriteBlocked(callable $write, string $expectedMessageFragment): void
    {
        try {
            $write();
            static::fail('Expected the write to be blocked by a constraint violation.');
        } catch (WriteException $exception) {
            $violations = $exception->getExceptions();
            static::assertNotEmpty($violations);
            static::assertInstanceOf(WriteConstraintViolationException::class, $violations[0]);
            static::assertStringContainsString($expectedMessageFragment, $exception->getMessage());
        }
    }

    private function createSnippetSet(string $name, ?string $parentId = null): string
    {
        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'name' => $name . ' ' . $id,
            'baseFile' => 'messages.en-GB',
            'iso' => 'en-GB',
        ];

        if ($parentId !== null) {
            $data['parentId'] = $parentId;
        }

        $this->snippetSetRepository->create([$data], $this->context);

        return $id;
    }
}
