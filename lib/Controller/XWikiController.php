<?php

/**
 * Pipelinq XWikiController.
 *
 * Thin proxy controller that forwards requests to the xWiki REST API via
 * XWikiService. Handles CORS naturally (browser talks to Nextcloud only),
 * adds X-Cache headers from the service layer, and sanitizes HTML content
 * before returning it to the frontend.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.conduction.nl
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\XWikiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Proxy controller for xWiki REST API access.
 *
 * All endpoints require a logged-in Nextcloud user (@NoAdminRequired) and are
 * exempt from CSRF tokens because they are REST API calls (@NoCSRFRequired).
 * The underlying XWikiService respects xWiki's own page permissions.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-1.2
 */
class XWikiController extends Controller
{

    /**
     * Constructor.
     *
     * @param IRequest     $request     The Nextcloud request.
     * @param XWikiService $xwikiService The xWiki service.
     */
    public function __construct(
        IRequest $request,
        private readonly XWikiService $xwikiService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Search xWiki pages.
     *
     * Accepts query parameters: q (required), space, tags (comma-separated),
     * limit (default 10), offset (default 0).
     *
     * @return JSONResponse Results with optional X-Cache header.
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(): JSONResponse
    {
        $query  = (string) ($this->request->getParam('q', '') ?? '');
        $space  = (string) ($this->request->getParam('space', '') ?? '');
        $tagsCsv = (string) ($this->request->getParam('tags', '') ?? '');
        $tags   = $tagsCsv !== '' ? array_map('trim', explode(',', $tagsCsv)) : [];
        $limit  = max(1, (int) ($this->request->getParam('limit', 10) ?? 10));
        $offset = max(0, (int) ($this->request->getParam('offset', 0) ?? 0));

        $result   = $this->xwikiService->search($query, $space, $tags, $limit, $offset);
        $cacheHit = ($result['x_cache'] ?? 'MISS') === 'HIT';

        unset($result['x_cache']);

        $response = new JSONResponse($result);
        $response->addHeader('X-Cache', $cacheHit ? 'HIT' : 'MISS');
        return $response;
    }//end search()

    /**
     * List pages in an xWiki space.
     *
     * Accepts query parameters: space (required), limit (default 20),
     * offset (default 0).
     *
     * @return JSONResponse Page list with optional X-Cache header.
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function pages(): JSONResponse
    {
        $space  = (string) ($this->request->getParam('space', '') ?? '');
        $limit  = max(1, (int) ($this->request->getParam('limit', 20) ?? 20));
        $offset = max(0, (int) ($this->request->getParam('offset', 0) ?? 0));

        if ($space === '') {
            return new JSONResponse(
                ['error' => 'Parameter "space" is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $result   = $this->xwikiService->getPages($space, $limit, $offset);
        $cacheHit = ($result['x_cache'] ?? 'MISS') === 'HIT';

        unset($result['x_cache']);

        $response = new JSONResponse($result);
        $response->addHeader('X-Cache', $cacheHit ? 'HIT' : 'MISS');
        return $response;
    }//end pages()

    /**
     * Get a single xWiki page's content.
     *
     * @param string $wiki The wiki name (e.g. "xwiki").
     * @param string $page The page reference (e.g. "Kennisbank.Paspoort.WebHome").
     *
     * @return JSONResponse Page metadata and sanitized HTML content.
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(string $wiki, string $page): JSONResponse
    {
        $result = $this->xwikiService->getPageContent($wiki, $page);

        if (isset($result['error']) === true && ($result['content'] ?? '') === '') {
            // Service-level errors where content is empty likely mean not found or misconfigured.
            $statusCode = str_contains($result['error'], 'not configured') === true
                ? Http::STATUS_SERVICE_UNAVAILABLE
                : Http::STATUS_NOT_FOUND;

            return new JSONResponse($result, $statusCode);
        }

        return new JSONResponse($result);
    }//end page()

    /**
     * Check xWiki availability and return version information.
     *
     * @return JSONResponse Status object with available, version, url.
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function status(): JSONResponse
    {
        $result = $this->xwikiService->getStatus();
        return new JSONResponse($result);
    }//end status()
}//end class
