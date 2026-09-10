<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Core\System\Snippet\Api;

use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceMerger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\Snippet\SnippetException;
use Shopware\Core\System\Snippet\SnippetService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API endpoint the Administration uses instead of the core
 * `api.action.snippet-set.getList` route: it runs the core
 * {@see SnippetService::getList()} unchanged and then adds inheritance info
 * via {@see SnippetInheritanceMerger}. Request handling mirrors
 * {@see \Shopware\Core\System\Snippet\Api\SnippetController::getList()} so the
 * Administration's `snippetSetService.getCustomList()` can point here 1:1.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
#[Package('discovery')]
class SnippetInheritanceController
{
    public function __construct(
        private readonly SnippetService $snippetService,
        private readonly SnippetInheritanceMerger $merger,
    ) {
    }

    #[Route(path: '/api/_action/scythe-snippet-set/list', name: 'api.action.scythe-snippet-set.list', methods: ['POST'])]
    public function getList(Request $request, Context $context): Response
    {
        $limit = $request->request->getInt('limit', 25);

        if ($limit < 1) {
            throw SnippetException::invalidLimitQuery($limit);
        }

        $filters = $request->request->all('filters');

        foreach (array_keys($filters) as $filterName) {
            if (!\is_string($filterName)) {
                throw SnippetException::invalidFilterName();
            }
        }

        $result = $this->snippetService->getList(
            $request->request->getInt('page', 1),
            $limit,
            $context,
            $filters,
            $request->request->all('sort'),
        );

        return new JsonResponse($this->merger->enrichAdminList($result));
    }
}
