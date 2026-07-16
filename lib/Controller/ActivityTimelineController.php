<?php

/**
 * Pipelinq ActivityTimelineController.
 *
 * REST controller for the merged activity timeline and worklog endpoints.
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
 * @spec openspec/changes/activity-timeline/tasks.md#task-2
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-entries-must-be-available-via-api
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller exposing /api/timeline and /api/worklog endpoints.
 *
 * Authentication is mandated by @NoAdminRequired (no @PublicPage). Error
 * responses use static messages — internal exception details are logged but
 * never returned to the caller.
 */
class ActivityTimelineController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request     The request.
     * @param ActivityTimelineService $service     The activity timeline service.
     * @param IUserSession            $userSession The user session.
     * @param LoggerInterface         $logger      The logger.
     * @param ContainerInterface      $container   The DI container.
     */
    public function __construct(
        IRequest $request,
        private ActivityTimelineService $service,
        private IUserSession $userSession,
        private LoggerInterface $logger,
        private ContainerInterface $container,
    ) {
        // @PublicPage — DI constructor (not HTTP-routable). The actual auth
        // posture for each endpoint lives on its own method attribute; this
        // ctor is wired by the Nextcloud app framework and never serves a
        // request.
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Verify that the underlying OR object exists and is accessible.
     *
     * Returns true if the object is found. Returns false on a clean 404.
     * Fails open on service errors so a temporary OR outage does not block
     * the entire timeline/worklog surface.
     *
     * @param string $entityId The OR object UUID.
     *
     * @return bool Whether the object can be accessed.
     */
    private function objectExists(string $entityId): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $object        = $objectService->find($entityId, []);
            return $object !== null;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ActivityTimelineController: could not verify object existence',
                ['entityId' => $entityId, 'exception' => $e->getMessage()]
            );
            return true;
        }
    }//end objectExists()

    /**
     * Return the merged activity timeline for an entity.
     *
     * Reads `entityType`, `entityId`, `from`, `to`, `types[]`, `_page`, `_limit`
     * from the request.
     *
     * @return JSONResponse The merged timeline or an error response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-entries-must-be-available-via-api
     */
    public function getTimeline(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $entityType = (string) $this->request->getParam('entityType', '');
        $entityId   = (string) $this->request->getParam('entityId', '');

        if ($entityType === '' || $entityId === '') {
            return new JSONResponse(
                ['message' => 'entityType and entityId are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($this->objectExists(entityId: $entityId) === false) {
            return new JSONResponse(['message' => 'Entity not found'], Http::STATUS_NOT_FOUND);
        }

        $params = [
            'from'   => $this->request->getParam('from'),
            'to'     => $this->request->getParam('to'),
            'types'  => $this->request->getParam('types'),
            '_page'  => $this->request->getParam('_page'),
            '_limit' => $this->request->getParam('_limit'),
        ];

        try {
            $result = $this->service->getTimeline(
                entityType: $entityType,
                entityId: $entityId,
                params: $params
            );
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ActivityTimelineController: failed to load timeline',
                [
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]
            );
            return new JSONResponse(
                ['message' => 'Failed to load timeline'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end getTimeline()

    /**
     * Return worklog entries (contactmomenten with channel=worklog) for an entity.
     *
     * @return JSONResponse The paginated worklog or an error response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
     */
    public function getWorklog(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $entityType = (string) $this->request->getParam('entityType', '');
        $entityId   = (string) $this->request->getParam('entityId', '');

        if ($entityType === '' || $entityId === '') {
            return new JSONResponse(
                ['message' => 'entityType and entityId are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($this->objectExists(entityId: $entityId) === false) {
            return new JSONResponse(['message' => 'Entity not found'], Http::STATUS_NOT_FOUND);
        }

        $params = [
            '_page'  => $this->request->getParam('_page'),
            '_limit' => $this->request->getParam('_limit'),
        ];

        try {
            $result = $this->service->getWorklog(
                entityType: $entityType,
                entityId: $entityId,
                params: $params
            );
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ActivityTimelineController: failed to load worklog',
                [
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]
            );
            return new JSONResponse(
                ['message' => 'Failed to load worklog'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end getWorklog()

    /**
     * Create a worklog entry for an entity.
     *
     * @return JSONResponse The created worklog or an error response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
     */
    public function createWorklog(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $entityType = (string) $this->request->getParam('entityType', '');
        $entityId   = (string) $this->request->getParam('entityId', '');
        $duration   = (string) $this->request->getParam('duration', '');

        if ($entityType === '' || $entityId === '' || $duration === '') {
            return new JSONResponse(
                ['message' => 'entityType, entityId and duration are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($this->objectExists(entityId: $entityId) === false) {
            return new JSONResponse(['message' => 'Entity not found'], Http::STATUS_NOT_FOUND);
        }

        $data = [
            'duration'    => $duration,
            'description' => $this->request->getParam('description'),
            'date'        => $this->request->getParam('date'),
        ];

        try {
            $created = $this->service->createWorklog(
                entityType: $entityType,
                entityId: $entityId,
                data: $data
            );
            return new JSONResponse($created, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ActivityTimelineController: failed to create worklog',
                [
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]
            );
            return new JSONResponse(
                ['message' => 'Failed to create worklog'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end createWorklog()
}//end class
