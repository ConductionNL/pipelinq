<?php

/**
 * Pipelinq ActivityController.
 *
 * REST controller exposing the per-entity activity feed (notes + contactmomenten)
 * for programmatic access by third-party integrations and reporting.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
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
 * @spec openspec/changes/entity-notes/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\EntityActivityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller exposing GET /api/activity/{entityType}/{entityId}.
 *
 * Authentication is mandated by @NoAdminRequired (no @PublicPage), so all
 * routes require a logged-in Nextcloud user. The entity type is validated
 * server-side against an explicit allowlist; error responses use static
 * messages and never leak exception details or stack traces (ADR-005).
 *
 * @spec openspec/changes/entity-notes/tasks.md#task-3
 */
class ActivityController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request     The request.
     * @param EntityActivityService $service     The entity activity service.
     * @param IUserSession          $userSession The user session.
     * @param LoggerInterface       $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private EntityActivityService $service,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the activity feed for an entity.
     *
     * Reads `type`, `_page` and `_limit` from the query string.
     *
     * @param string $entityType The entity type (client|contact|lead|request).
     * @param string $entityId   The entity UUID.
     *
     * @return JSONResponse The paginated activity feed or an error response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/entity-notes/tasks.md#task-3
     */
    public function index(string $entityType, string $entityId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        if ($entityId === '') {
            return new JSONResponse(['message' => 'Entity id is required'], Http::STATUS_BAD_REQUEST);
        }

        $type  = (string) $this->request->getParam('type', 'all');
        $page  = (int) $this->request->getParam('_page', 1);
        $limit = (int) $this->request->getParam('_limit', 20);

        try {
            $result = $this->service->getActivity(
                entityType: $entityType,
                entityId: $entityId,
                type: $type,
                page: $page,
                limit: $limit
            );
            return new JSONResponse($result);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['message' => 'Invalid entity type'], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ActivityController: failed to load activity feed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['message' => 'Failed to load activity'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()
}//end class
