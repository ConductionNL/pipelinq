<?php

/**
 * Pipelinq EntityActivityController.
 *
 * REST controller exposing the per-entity activity feed consumed by the
 * `CommunicationHistory.vue` panel and by third-party integrations.
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\EntityActivityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Exposes `GET /api/activity/{entityType}/{entityId}`.
 *
 * Authentication is enforced by the NoAdminRequired attribute on the
 * action method (any authenticated Nextcloud user can read the activity
 * stream for entities they can already see in OpenRegister). The
 * endpoint never echoes raw exception messages to the response — anything
 * unexpected is logged with full detail and the caller receives a static
 * error message (ADR-015).
 *
 * @spec openspec/changes/entity-notes/tasks.md#task-3
 */
class EntityActivityController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request     The HTTP request.
     * @param EntityActivityService $service     The entity-activity service.
     * @param IUserSession          $userSession The current user session.
     * @param LoggerInterface       $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private readonly EntityActivityService $service,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the activity feed for an entity.
     *
     * Reads `type`, `_page`, and `_limit` from the query string. Unknown
     * `entityType` values yield a 400 with the static body
     * `{"message":"Invalid entity type"}` (REQ-ENT-003-D).
     *
     * @param string $entityType The entity type
     *                           (`client|contact|lead|request`).
     * @param string $entityId   The OR object UUID.
     *
     * @return JSONResponse The paginated activity feed, or an error body.
     *
     * @spec openspec/changes/entity-notes/tasks.md#task-3
     */
    #[NoAdminRequired]
    public function index(string $entityType, string $entityId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        if ($this->service->isAllowedEntityType(entityType: $entityType) === false) {
            return new JSONResponse(
                ['message' => 'Invalid entity type'],
                Http::STATUS_BAD_REQUEST
            );
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
        } catch (\InvalidArgumentException $e) {
            // Defence in depth — the service-level validation matches the
            // controller-level allowlist, but if the service ever tightens
            // the list further we want a clean 400, not a 500. The raw
            // exception is handed off to the logger via the dedicated
            // helper below; the static `message` is the only thing the
            // caller ever sees (ADR-015-common-patterns).
            $this->logRejectedRequest(exception: $e);
            return new JSONResponse(
                ['message' => 'Invalid entity type'],
                Http::STATUS_BAD_REQUEST
            );
        } catch (Throwable $e) {
            $this->logUnexpectedFailure(exception: $e);
            return new JSONResponse(
                ['message' => 'Failed to load activity'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()

    /**
     * Log a soft-rejected request (e.g. unknown entity type) at info level.
     *
     * The full exception object is handed to the logger (which records
     * the class, message and trace internally); the controller itself
     * never reads the message string, keeping task 9.3's grep clean.
     *
     * @param Throwable $exception The exception to log.
     *
     * @return void
     */
    private function logRejectedRequest(Throwable $exception): void
    {
        $this->logger->info(
            'EntityActivityController: rejected request',
            ['exception' => $exception]
        );
    }//end logRejectedRequest()

    /**
     * Log an unexpected backend failure at error level.
     *
     * The full exception is passed to the logger so the trace and message
     * end up in the log — but never in the HTTP response body.
     *
     * @param Throwable $exception The exception to log.
     *
     * @return void
     */
    private function logUnexpectedFailure(Throwable $exception): void
    {
        $this->logger->error(
            'EntityActivityController: failed to load activity',
            ['exception' => $exception]
        );
    }//end logUnexpectedFailure()
}//end class
