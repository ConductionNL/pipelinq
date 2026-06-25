<?php

/**
 * Pipelinq XWikiController.
 *
 * Thin proxy controller exposing xWiki REST API operations to the Pipelinq
 * frontend (xwiki-integration change). All endpoints are `@NoAdminRequired`
 * — any authenticated Pipelinq user may search or read xWiki pages — and
 * `@NoCSRFRequired` because the frontend calls them as plain JSON GET
 * requests via the Pinia store.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\XWikiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * XWiki REST proxy controller.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#1.2
 */
class XWikiController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request HTTP request.
     * @param XWikiService    $xwiki   xWiki proxy service.
     * @param LoggerInterface $logger  Logger.
     */
    public function __construct(
        IRequest $request,
        private XWikiService $xwiki,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Search xWiki pages.
     *
     * Query params: `q`, `space`, `tags` (comma-separated), `limit`, `offset`.
     *
     * @return JSONResponse
     *
     * @spec                 openspec/changes/xwiki-integration/tasks.md#1.2
     * @no-admin-idor-exempt read-only kennisbank proxy for all authenticated users; no caller-supplied pipelinq object ids —
     *                       visibility is scoped xwiki-side by the integration service account
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(): JSONResponse
    {
        try {
            $query  = (string) ($this->request->getParam('q') ?? '');
            $space  = $this->request->getParam('space');
            $tagsR  = $this->request->getParam('tags');
            $limit  = (int) ($this->request->getParam('limit') ?? 10);
            $offset = (int) ($this->request->getParam('offset') ?? 0);

            if (is_string($space) === true && $space !== '') {
                $space = $space;
            } else {
                $space = null;
            }

            $tags = [];
            if (is_string($tagsR) === true && $tagsR !== '') {
                $tags = array_values(array_filter(array_map('trim', explode(',', $tagsR)), static fn($t) => $t !== ''));
            }

            $result = $this->xwiki->search($query, $space, $tags, $limit, $offset);
            return new JSONResponse($result);
        } catch (Throwable $e) {
            $this->logger->warning('xWiki search proxy failed', ['exception' => $e]);
            return new JSONResponse(['results' => [], 'total' => 0, 'limit' => 10, 'offset' => 0, 'error' => 'unavailable']);
        }//end try
    }//end search()

    /**
     * List pages in a space.
     *
     * Query params: `space`, `limit`, `offset`.
     *
     * @return JSONResponse
     *
     * @spec                 openspec/changes/xwiki-integration/tasks.md#1.2
     * @no-admin-idor-exempt read-only kennisbank proxy for all authenticated users; no caller-supplied pipelinq object ids —
     *                       visibility is scoped xwiki-side by the integration service account
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function pages(): JSONResponse
    {
        try {
            $space  = (string) ($this->request->getParam('space') ?? '');
            $limit  = (int) ($this->request->getParam('limit') ?? 10);
            $offset = (int) ($this->request->getParam('offset') ?? 0);

            $result = $this->xwiki->getPages($space, $limit, $offset);
            return new JSONResponse($result);
        } catch (Throwable $e) {
            $this->logger->warning('xWiki pages proxy failed', ['exception' => $e]);
            return new JSONResponse(['results' => [], 'total' => 0, 'limit' => 10, 'offset' => 0, 'error' => 'unavailable']);
        }
    }//end pages()

    /**
     * Get rendered HTML for a single page.
     *
     * @param string $wiki Wiki id ('xwiki').
     * @param string $page Dotted page reference.
     *
     * @return JSONResponse
     *
     * @spec                 openspec/changes/xwiki-integration/tasks.md#1.2
     * @no-admin-idor-exempt read-only kennisbank proxy for all authenticated users; page refs are xwiki documents (not pipelinq
     *                       objects) — visibility is scoped xwiki-side by the integration service account, content sanitised before return
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(string $wiki, string $page): JSONResponse
    {
        try {
            $result = $this->xwiki->getPageContent($wiki, $page);
            // Belt-and-braces: re-sanitise content in case the service path
            // was bypassed in tests / fixtures.
            if (isset($result['content']) === true && is_string($result['content']) === true) {
                $result['content'] = $this->xwiki->sanitiseHtml($result['content']);
            }

            return new JSONResponse($result);
        } catch (Throwable $e) {
            $this->logger->warning('xWiki page proxy failed', ['exception' => $e]);
            return new JSONResponse(
                [
                    'title'    => '',
                    'content'  => '',
                    'url'      => '',
                    'modified' => '',
                    'space'    => '',
                    'id'       => '',
                    'error'    => 'unavailable',
                ]
            );
        }//end try
    }//end page()

    /**
     * Status / availability check.
     *
     * @return JSONResponse
     *
     * @spec                 openspec/changes/xwiki-integration/tasks.md#1.2
     * @no-admin-idor-exempt availability probe consumed by every user's dashboard widget; returns integration metadata only, no object access
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function status(): JSONResponse
    {
        try {
            return new JSONResponse($this->xwiki->getStatus());
        } catch (Throwable $e) {
            $this->logger->warning('xWiki status proxy failed', ['exception' => $e]);
            return new JSONResponse(['available' => false, 'version' => '', 'baseUrl' => '', 'source' => 'error']);
        }
    }//end status()
}//end class
