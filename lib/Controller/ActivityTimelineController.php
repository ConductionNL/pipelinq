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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
     * @param IRequest                $request The request.
     * @param ActivityTimelineService $service The activity timeline service.
     * @param LoggerInterface         $logger  The logger.
     */
    public function __construct(
        IRequest $request,
        private ActivityTimelineService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()


    /**
     * Return the merged activity timeline for an entity.
     *
     * Reads `entityType`, `entityId`, `from`, `to`, `types[]`, `_page`, `_limit`
     * from the request.
     *
     * @return JSONResponse The merged timeline or an error response.
     *
     * @NoAdminRequired
     */
    public function getTimeline(): JSONResponse
    {
        $entityType = (string) $this->request->getParam('entityType', '');
        $entityId   = (string) $this->request->getParam('entityId', '');

        if ($entityType === '' || $entityId === '') {
            return new JSONResponse(
                ['message' => 'entityType and entityId are required'],
                Http::STATUS_BAD_REQUEST
            );
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
     */
    public function getWorklog(): JSONResponse
    {
        $entityType = (string) $this->request->getParam('entityType', '');
        $entityId   = (string) $this->request->getParam('entityId', '');

        if ($entityType === '' || $entityId === '') {
            return new JSONResponse(
                ['message' => 'entityType and entityId are required'],
                Http::STATUS_BAD_REQUEST
            );
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
     */
    public function createWorklog(): JSONResponse
    {
        $entityType = (string) $this->request->getParam('entityType', '');
        $entityId   = (string) $this->request->getParam('entityId', '');
        $duration   = (string) $this->request->getParam('duration', '');

        if ($entityType === '' || $entityId === '' || $duration === '') {
            return new JSONResponse(
                ['message' => 'entityType, entityId and duration are required'],
                Http::STATUS_BAD_REQUEST
            );
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
