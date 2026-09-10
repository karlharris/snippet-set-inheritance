<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Tests\Integration\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scythe\SnippetSetInheritance\Core\System\Snippet\Api\SnippetInheritanceController;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\AdminApiTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(SnippetInheritanceController::class)]
class SnippetInheritanceControllerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use AdminApiTestBehaviour;

    public function testListRouteReturnsTheEnrichedPayload(): void
    {
        $context = Context::createDefaultContext();
        $repo = static::getContainer()->get('snippet_set.repository');

        $parentId = Uuid::randomHex();
        $childId = Uuid::randomHex();
        $repo->create([
            ['id' => $parentId, 'name' => 'SSSI ctrl parent ' . $parentId, 'baseFile' => 'messages.en-GB', 'iso' => 'en-GB'],
            ['id' => $childId, 'name' => 'SSSI ctrl child ' . $childId, 'baseFile' => 'messages.en-GB', 'iso' => 'en-GB', 'parentId' => $parentId],
        ], $context);

        $key = 'scythe.sssi.ctrl.' . Uuid::randomHex();
        static::getContainer()->get('snippet.repository')->create([
            ['translationKey' => $key, 'value' => 'parent value', 'setId' => $parentId, 'author' => 'test'],
        ], $context);

        $browser = $this->getBrowser();
        $browser->request('POST', '/api/_action/scythe-snippet-set/list', [
            'page' => 1,
            'limit' => 100,
            'filters' => ['term' => $key],
            'sort' => [],
        ]);

        $response = $browser->getResponse();
        static::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $childCell = null;
        foreach ($data['data'][$key] ?? [] as $cell) {
            if ($cell['setId'] === $childId) {
                $childCell = $cell;
            }
        }

        static::assertNotNull($childCell);
        static::assertTrue($childCell['inherited']);
        static::assertSame('parent value', $childCell['value']);
        static::assertSame($parentId, $childCell['inheritedFromSnippetSetId']);
    }
}
