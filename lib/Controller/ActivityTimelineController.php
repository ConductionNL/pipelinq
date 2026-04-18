<?php

/**
 * Pipelinq ActivityTimelineController.
 *
 * Controller for activity timeline and worklog API endpoints.
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for activity timeline and worklog APIs.
 *
 * @spec openspec/changes/activity-timeline/tasks.md#task-2
 */
class ActivityTimelineController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest               $request The request.
     * @param ActivityTimelineService $service The activity timeline service.
     * @param LoggerInterface        $logger  The logger.
     */
    public function __construct(
        IRequest $request,
        private ActivityTimelineService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get the merged activity timeline for an entity.
     *
     * @return JSONResponse The activity timeline.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/activity-timeline/tasks.md#task-2
     */
    public function getTimeline(): JSONResponse
    {
        $entityType = $this->request->getParam('entityType', '');
        $entityId   = $this->request->getParam('entityId', '');

        // Validate required parameters
        if ($entityType === '' || $entityId === '') {
            return new JSONResponse(
                ['message' => 'Missing required parameters'],
                400
            );
        }

        try {
            // Collect query parameters
            $params = [
                'from'    => $this->request->getParam('from'),
                'to'      => $this->request->getParam('to'),
                'types'   => $this->request->getParam('types', []),
                '_page'   => $this->request->getParam('_page', 1),
                '_limit'  => $this->request->getParam('_limit', 20),
            ];

            // Clean up empty values
            $params = array_filter($params, function ($value) {
                return $value !== null && $value !== '';
            });

            $timeline = $this->service->getTimeline($entityType, $entityId, $params);

            return new JSONResponse($timeline);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to load activity timeline',
                ['exception' => $e->getMessage()]
            );

            return new JSONResponse(
                ['message' => 'Failed to load timeline'],
                500
            );
        }
    }//end getTimeline()

    /**
     * Get worklog entries for an entity.
     *
     * @return JSONResponse The worklog entries.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/activity-timeline/tasks.md#task-2
     */
    public function getWorklog(): JSONResponse
    {
        $entityType = $this->request->getParam('entityType', '');
        $entityId   = $this->request->getParam('entityId', '');

        // Validate required parameters
        if ($entityType === '' || $entityId === '') {
            return new JSONResponse(
                ['message' => 'Missing required parameters'],
                400
            );
        }

        try {
            $params = [
                '_page'  => $this->request->getParam('_page', 1),
                '_limit' => $this->request->getParam('_limit', 20),
            ];

            $worklog = $this->service->getWorklog($entityType, $entityId, $params);

            return new JSONResponse($worklog);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to load worklog',
                ['exception' => $e->getMessage()]
            );

            return new JSONResponse(
                ['message' => 'Failed to load worklog'],
                500
            );
        }
    }//end getWorklog()

    /**
     * Create a worklog entry.
     *
     * @return JSONResponse The created worklog entry.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/activity-timeline/tasks.md#task-2
     */
    public function createWorklog(): JSONResponse
    {
        $entityType = $this->request->getParam('entityType', '');
        $entityId   = $this->request->getParam('entityId', '');
        $duration   = $this->request->getParam('duration', '');
        $description = $this->request->getParam('description', '');
        $date       = $this->request->getParam('date', '');

        // Validate required fields
        if ($entityType === '' || $entityId === '' || $duration === '') {
            return new JSONResponse(
                ['message' => 'Missing required fields'],
                400
            );
        }

        try {
            $data = [
                'duration'    => $duration,
                'description' => $description,
                'date'        => $date,
            ];

            $worklog = $this->service->createWorklog($entityType, $entityId, $data);

            return new JSONResponse($worklog, 201);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to create worklog',
                ['exception' => $e->getMessage()]
            );

            return new JSONResponse(
                ['message' => 'Failed to create worklog'],
                500
            );
        }
    }//end createWorklog()
}//end class
