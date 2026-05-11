<?php

/**
 * Pipelinq SchedulesController.
 *
 * REST API controller for the Schedules API. Exposes CRUD plus a `pending`
 * window query for the scheduled-task lifecycle.
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
 * @spec openspec/changes/task-background-jobs/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Schedules API controller.
 *
 * Each public method returns a JSONResponse and never leaks raw exception
 * messages to clients (ADR-005). Per-object authorisation is delegated to
 * ScheduledTaskService::authorizeTaskMutation() for PUT and DELETE.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/task-background-jobs/tasks.md#task-2
 */
class SchedulesController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request              The request.
     * @param ScheduledTaskService $scheduledTaskService Schedule service.
     * @param IGroupManager        $groupManager         Group manager.
     * @param IUserSession         $userSession          User session.
     * @param LoggerInterface      $logger               Logger.
     */
    public function __construct(
        IRequest $request,
        private ScheduledTaskService $scheduledTaskService,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List scheduled tasks.
     *
     * GET /api/schedules
     *
     * @return JSONResponse Paginated task envelope.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        try {
            $params = [
                'status'          => $this->request->getParam('status', ''),
                'assigneeUserId'  => $this->request->getParam('assigneeUserId', ''),
                'assigneeGroupId' => $this->request->getParam('assigneeGroupId', ''),
                'from'            => $this->request->getParam('from', ''),
                'to'              => $this->request->getParam('to', ''),
                '_page'           => (int) $this->request->getParam('_page', 1),
                '_limit'          => (int) $this->request->getParam('_limit', 20),
            ];

            $result = $this->scheduledTaskService->getScheduledTasks($params);

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'SchedulesController::index failed',
                ['exception' => $e]
            );
            return new JSONResponse(
                ['message' => 'Operation failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()

    /**
     * Create a scheduled task.
     *
     * POST /api/schedules
     *
     * @return JSONResponse The created task (201) or an error envelope.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $type     = (string) $this->request->getParam('type', '');
        $subject  = (string) $this->request->getParam('subject', '');
        $deadline = (string) $this->request->getParam('deadline', '');

        if ($type === ''
            || in_array($type, ScheduledTaskService::VALID_TYPES, true) === false
        ) {
            return new JSONResponse(
                ['message' => 'Invalid input'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($subject === '' || $deadline === '') {
            return new JSONResponse(
                ['message' => 'Invalid input'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $data = [
            'type'                => $type,
            'subject'             => $subject,
            'deadline'            => $deadline,
            'description'         => (string) $this->request->getParam('description', ''),
            'priority'            => (string) $this->request->getParam('priority', 'normaal'),
            'assigneeUserId'      => (string) $this->request->getParam('assigneeUserId', ''),
            'assigneeGroupId'     => (string) $this->request->getParam('assigneeGroupId', ''),
            'clientId'            => (string) $this->request->getParam('clientId', ''),
            'requestId'           => (string) $this->request->getParam('requestId', ''),
            'callbackPhoneNumber' => (string) $this->request->getParam('callbackPhoneNumber', ''),
            'preferredTimeSlot'   => (string) $this->request->getParam('preferredTimeSlot', ''),
        ];

        // Strip blank optional fields to avoid clobbering schema defaults.
        $optionalFields = [
            'description',
            'assigneeUserId',
            'assigneeGroupId',
            'clientId',
            'requestId',
            'callbackPhoneNumber',
            'preferredTimeSlot',
        ];
        foreach ($optionalFields as $optional) {
            if ($data[$optional] === '') {
                unset($data[$optional]);
            }
        }

        try {
            $created = $this->scheduledTaskService->createScheduledTask($data);
            return new JSONResponse($created, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => 'Invalid input'],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'SchedulesController::create failed',
                ['exception' => $e]
            );
            return new JSONResponse(
                ['message' => 'Operation failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end create()

    /**
     * List tasks due within a window.
     *
     * GET /api/schedules/pending
     *
     * @return JSONResponse Items envelope (always 200 when not erroring).
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function pending(): JSONResponse
    {
        try {
            $window = (int) $this->request->getParam('window', 60);
            if ($window > 1440) {
                $window = 1440;
            }

            if ($window < 1) {
                $window = 1;
            }

            $items = $this->scheduledTaskService->getPendingTasks($window);

            return new JSONResponse(
                [
                    'items' => $items,
                    'total' => count($items),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'SchedulesController::pending failed',
                ['exception' => $e]
            );
            return new JSONResponse(
                ['message' => 'Operation failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end pending()

    /**
     * Get a single scheduled task.
     *
     * GET /api/schedules/{id}
     *
     * @param string $id The task UUID.
     *
     * @return JSONResponse The task or 404.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        try {
            $task = $this->scheduledTaskService->getScheduledTask($id);
            return new JSONResponse($task);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => 'Not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'SchedulesController::show failed',
                ['exception' => $e]
            );
            return new JSONResponse(
                ['message' => 'Operation failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end show()

    /**
     * Update a scheduled task.
     *
     * PUT /api/schedules/{id}
     *
     * @param string $id The task UUID.
     *
     * @return JSONResponse The updated task or an error envelope.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        try {
            $task = $this->scheduledTaskService->getScheduledTask($id);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => 'Not found'],
                Http::STATUS_NOT_FOUND
            );
        }

        $user   = $this->userSession->getUser();
        $userId = '';
        if ($user !== null) {
            $userId = $user->getUID();
        }

        try {
            $this->scheduledTaskService->authorizeTaskMutation($task, $userId);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(
                ['message' => 'Not authorized'],
                Http::STATUS_FORBIDDEN
            );
        }

        $payload = $this->request->getParams();
        unset($payload['id'], $payload['_route']);

        try {
            $updated = $this->scheduledTaskService->updateScheduledTask($id, $payload);
            return new JSONResponse($updated);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => 'Invalid input'],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'SchedulesController::update failed',
                ['exception' => $e]
            );
            return new JSONResponse(
                ['message' => 'Operation failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end update()

    /**
     * Cancel (delete) a scheduled task.
     *
     * DELETE /api/schedules/{id}
     *
     * @param string $id The task UUID.
     *
     * @return JSONResponse 204 on success or an error envelope.
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        try {
            $task = $this->scheduledTaskService->getScheduledTask($id);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => 'Not found'],
                Http::STATUS_NOT_FOUND
            );
        }

        $user   = $this->userSession->getUser();
        $userId = '';
        if ($user !== null) {
            $userId = $user->getUID();
        }

        try {
            $this->scheduledTaskService->authorizeTaskMutation($task, $userId);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(
                ['message' => 'Not authorized'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $this->scheduledTaskService->deleteScheduledTask($id);
            return new JSONResponse([], Http::STATUS_NO_CONTENT);
        } catch (\Throwable $e) {
            $this->logger->error(
                'SchedulesController::destroy failed',
                ['exception' => $e]
            );
            return new JSONResponse(
                ['message' => 'Operation failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end destroy()
}//end class
