<?php

/**
 * Pipelinq KennisbankController.
 *
 * REST API for the knowledge base (kennisbank): public full-text search and category
 * collections (unauthenticated), and admin/authenticated endpoints for bulk export,
 * article version history and diffing, and the compliance audit log. Thin controller —
 * all business logic lives in KennisbankService (ADR-003).
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kennisbank/tasks.md#task-1
 * @spec openspec/changes/kennisbank/tasks.md#task-2
 * @spec openspec/changes/kennisbank/tasks.md#task-3
 * @spec openspec/changes/kennisbank/tasks.md#task-4
 * @spec openspec/changes/kennisbank/tasks.md#task-5
 * @spec openspec/changes/kennisbank/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\KennisbankService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for knowledge base API operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/kennisbank/tasks.md#task-1
 */
class KennisbankController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request.
     * @param KennisbankService $kennisbankService The knowledge base service.
     * @param IUserSession      $userSession       The user session.
     * @param IGroupManager     $groupManager      The group manager.
     * @param IL10N             $l10n              The localization service.
     * @param LoggerInterface   $logger            The logger.
     */
    public function __construct(
        IRequest $request,
        private readonly KennisbankService $kennisbankService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Add CORS headers to allow cross-origin consumption of public endpoints.
     *
     * @param JSONResponse $response The response to add headers to.
     *
     * @return JSONResponse The response with CORS headers.
     */
    private function addCorsHeaders(JSONResponse $response): JSONResponse
    {
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->addHeader('Access-Control-Allow-Headers', 'Content-Type');
        return $response;
    }//end addCorsHeaders()

    /**
     * Verify the current request is from an authenticated admin user.
     *
     * @return JSONResponse|null A ready-to-send error response, or null when authorized.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => $this->l10n->t('Admin privileges required')], Http::STATUS_FORBIDDEN);
        }

        return null;
    }//end requireAdmin()

    /**
     * Read an integer request param with a default and lower bound.
     *
     * @param string $name    The param name.
     * @param int    $default The default value.
     *
     * @return int The parsed integer.
     */
    private function intParam(string $name, int $default): int
    {
        $value = $this->request->getParam($name);
        if ($value === null || is_numeric($value) === false) {
            return $default;
        }

        return (int) $value;
    }//end intParam()

    /**
     * Public full-text search over published+public articles.
     *
     * @return JSONResponse The search result page.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @CORS
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-1
     */
    public function searchPublic(): JSONResponse
    {
        try {
            $query    = (string) ($this->request->getParam('q') ?? '');
            $category = $this->request->getParam('category');
            $tags     = $this->request->getParam('tags');

            $categories = [];
            if ($category !== null && $category !== '') {
                $categories = [(string) $category];
            }

            $tagList = [];
            if (is_array($tags) === true) {
                $tagList = array_map('strval', $tags);
            }

            $result = $this->kennisbankService->searchPublicArticles(
                query: $query,
                categories: $categories,
                tags: $tagList,
                page: $this->intParam(name: '_page', default: 1),
                limit: $this->intParam(name: '_limit', default: 20),
            );

            return $this->addCorsHeaders(response: new JSONResponse($result));
        } catch (\RuntimeException $e) {
            $this->logger->warning('Kennisbank search unavailable', ['exception' => $e->getMessage()]);
            return $this->addCorsHeaders(
                response: new JSONResponse(['message' => $this->l10n->t('Knowledge base is not available')], Http::STATUS_SERVICE_UNAVAILABLE)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Kennisbank search failed', ['exception' => $e]);
            return $this->addCorsHeaders(
                response: new JSONResponse(['message' => $this->l10n->t('Search failed')], Http::STATUS_INTERNAL_SERVER_ERROR)
            );
        }//end try
    }//end searchPublic()

    /**
     * CORS preflight for the public search endpoint.
     *
     * @return JSONResponse An empty CORS response.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @CORS
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-1
     */
    public function searchPublicCors(): JSONResponse
    {
        return $this->addCorsHeaders(response: new JSONResponse([]));
    }//end searchPublicCors()

    /**
     * Public category tree with published+public article counts.
     *
     * @return JSONResponse The category tree.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @CORS
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-2
     */
    public function getCollections(): JSONResponse
    {
        try {
            $tree = $this->kennisbankService->getCategoryTree();
            return $this->addCorsHeaders(response: new JSONResponse(['results' => $tree]));
        } catch (\RuntimeException $e) {
            $this->logger->warning('Kennisbank collections unavailable', ['exception' => $e->getMessage()]);
            return $this->addCorsHeaders(
                response: new JSONResponse(['message' => $this->l10n->t('Knowledge base is not available')], Http::STATUS_SERVICE_UNAVAILABLE)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Kennisbank collections failed', ['exception' => $e]);
            return $this->addCorsHeaders(
                response: new JSONResponse(['message' => $this->l10n->t('Operation failed')], Http::STATUS_INTERNAL_SERVER_ERROR)
            );
        }//end try
    }//end getCollections()

    /**
     * CORS preflight for the public collections endpoint.
     *
     * @return JSONResponse An empty CORS response.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @CORS
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-2
     */
    public function getCollectionsCors(): JSONResponse
    {
        return $this->addCorsHeaders(response: new JSONResponse([]));
    }//end getCollectionsCors()

    /**
     * Public listing of published+public articles within a category.
     *
     * @param string $slug The category slug.
     *
     * @return JSONResponse The paginated article page, or 404 for an unknown slug.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @CORS
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-2
     */
    public function getCollectionArticles(string $slug): JSONResponse
    {
        try {
            $result = $this->kennisbankService->getArticlesByCategory(
                slug: $slug,
                page: $this->intParam(name: '_page', default: 1),
                limit: $this->intParam(name: '_limit', default: 20),
            );

            if ($result === null) {
                return $this->addCorsHeaders(
                    response: new JSONResponse(['message' => $this->l10n->t('Category not found')], Http::STATUS_NOT_FOUND)
                );
            }

            return $this->addCorsHeaders(response: new JSONResponse($result));
        } catch (\RuntimeException $e) {
            $this->logger->warning('Kennisbank collection articles unavailable', ['exception' => $e->getMessage()]);
            return $this->addCorsHeaders(
                response: new JSONResponse(['message' => $this->l10n->t('Knowledge base is not available')], Http::STATUS_SERVICE_UNAVAILABLE)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Kennisbank collection articles failed', ['exception' => $e]);
            return $this->addCorsHeaders(
                response: new JSONResponse(['message' => $this->l10n->t('Operation failed')], Http::STATUS_INTERNAL_SERVER_ERROR)
            );
        }//end try
    }//end getCollectionArticles()

    /**
     * Bulk export of articles in JSON or CSV. Admin only.
     *
     * @return DataDownloadResponse|JSONResponse The export download, or an error.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-3
     */
    public function exportArticles(): DataDownloadResponse | JSONResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }

        try {
            $format = strtolower((string) ($this->request->getParam('format') ?? 'json'));

            $filters = [];
            $status  = $this->request->getParam('status');
            if ($status !== null && $status !== '') {
                $filters['status'] = (string) $status;
            }

            $export = $this->kennisbankService->exportArticles(format: $format, filters: $filters);

            return new DataDownloadResponse(
                $export['body'],
                $export['filename'],
                $export['contentType'],
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $this->l10n->t('Unsupported export format')], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Kennisbank export unavailable', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => $this->l10n->t('Knowledge base is not available')], Http::STATUS_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Kennisbank export failed', ['exception' => $e]);
            return new JSONResponse(['message' => $this->l10n->t('Export failed')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end exportArticles()

    /**
     * Article version history. Authenticated users only.
     *
     * @param string $id The article UUID.
     *
     * @return JSONResponse The version list, or 404 for an unknown article.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-4
     */
    public function getVersions(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $versions = $this->kennisbankService->getArticleVersions(id: $id);
            if ($versions === null) {
                return new JSONResponse(['message' => $this->l10n->t('Article not found')], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['results' => $versions]);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Kennisbank versions unavailable', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => $this->l10n->t('Knowledge base is not available')], Http::STATUS_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Kennisbank versions failed', ['exception' => $e]);
            return new JSONResponse(['message' => $this->l10n->t('Operation failed')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end getVersions()

    /**
     * Diff between two article versions. Authenticated users only.
     *
     * @param string $id   The article UUID.
     * @param int    $from The base version number.
     * @param int    $to   The target version number.
     *
     * @return JSONResponse The diff, 404 for an unknown article, or 400 for an unknown version.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-5
     */
    public function compareVersions(string $id, int $from, int $to): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $diff = $this->kennisbankService->compareVersions(id: $id, fromVersion: $from, toVersion: $to);
            if ($diff === null) {
                return new JSONResponse(['message' => $this->l10n->t('Article not found')], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($diff);
        } catch (\OutOfRangeException $e) {
            return new JSONResponse(['message' => $this->l10n->t('Requested version not found')], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Kennisbank version compare unavailable', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => $this->l10n->t('Knowledge base is not available')], Http::STATUS_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Kennisbank version compare failed', ['exception' => $e]);
            return new JSONResponse(['message' => $this->l10n->t('Operation failed')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end compareVersions()

    /**
     * Consolidated audit log for all knowledge base entities. Admin only.
     *
     * @return JSONResponse The audit page.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-6
     */
    public function getAuditLog(): JSONResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }

        try {
            $filters = [
                'schema'   => (string) ($this->request->getParam('schema') ?? ''),
                'action'   => (string) ($this->request->getParam('action') ?? ''),
                'actor'    => (string) ($this->request->getParam('actor') ?? ''),
                'dateFrom' => (string) ($this->request->getParam('dateFrom') ?? ''),
                'dateTo'   => (string) ($this->request->getParam('dateTo') ?? ''),
            ];

            $result = $this->kennisbankService->getAuditLog(
                filters: $filters,
                page: $this->intParam(name: '_page', default: 1),
                limit: $this->intParam(name: '_limit', default: 20),
            );

            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Kennisbank audit unavailable', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => $this->l10n->t('Knowledge base is not available')], Http::STATUS_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Kennisbank audit failed', ['exception' => $e]);
            return new JSONResponse(['message' => $this->l10n->t('Operation failed')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end getAuditLog()
}//end class
