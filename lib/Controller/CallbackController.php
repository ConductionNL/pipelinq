<?php

/**
 * Pipelinq CallbackController.
 *
 * Controller for callback request (terugbelverzoek) API endpoints.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CallbackService;
use OCA\Pipelinq\Service\NotificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for callback request API endpoints.
 *
 * Provides endpoints for logging callback attempts, claiming group tasks,
 * completing callbacks, and reassigning tasks.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/callback-management/tasks.md#2.1
 */
class CallbackController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest            $request             The request.
     * @param CallbackService     $callbackService     The callback service.
     * @param NotificationService $notificationService The notification service.
     * @param IAppConfig          $appConfig           The app config.
     * @param IUserSession        $userSession         The user session.
     * @param IL10N               $l10n                The localization service.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        IRequest $request,
        private CallbackService $callbackService,
        private NotificationService $notificationService,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Log a callback attempt.
     *
     * @param string $id The task object ID.
     *
     * @return JSONResponse The response with updated task data.
     *
     * @NoAdminRequired
     * @spec openspec/changes/callback-management/tasks.md#2.1
     */
    public function attempt(string $id): JSONResponse
    {
        $result = $this->request->getParam('result', '');
        $notes  = $this->request->getParam('notes', '');

        if (empty($result) === true) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Attempt result is required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            // Build task data stub — in production, fetch from OpenRegister.
            $taskData = $this->getTaskStub(id: $id);
            if ($taskData === null) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Task not found')],
                    Http::STATUS_NOT_FOUND
                );
            }

            $taskData     = $this->callbackService->addAttempt($taskData, $result, $notes);
            $suggestClose = $this->callbackService->isAttemptThresholdReached($taskData);

            return new JSONResponse(
                    [
                        'task'         => $taskData,
                        'suggestClose' => $suggestClose,
                        'attemptCount' => count($taskData['attempts'] ?? []),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error('CallbackController::attempt failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to log callback attempt')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end attempt()

    /**
     * Claim a group-assigned task for the current user.
     *
     * @param string $id The task object ID.
     *
     * @return JSONResponse The response with updated task data.
     *
     * @NoAdminRequired
     * @spec openspec/changes/callback-management/tasks.md#2.1
     */
    public function claim(string $id): JSONResponse
    {
        try {
            $taskData = $this->getTaskStub(id: $id);
            if ($taskData === null) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Task not found')],
                    Http::STATUS_NOT_FOUND
                );
            }

            $validation = $this->callbackService->validateClaim($taskData);
            if ($validation['eligible'] === false) {
                return new JSONResponse(
                    ['error' => $this->l10n->t($validation['reason'])],
                    Http::STATUS_FORBIDDEN
                );
            }

            $taskData = $this->callbackService->applyClaim($taskData);

            return new JSONResponse(['task' => $taskData]);
        } catch (\Exception $e) {
            $this->logger->error('CallbackController::claim failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to claim task')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end claim()

    /**
     * Complete a callback task.
     *
     * @param string $id The task object ID.
     *
     * @return JSONResponse The response with updated task data.
     *
     * @NoAdminRequired
     * @spec openspec/changes/callback-management/tasks.md#2.1
     */
    public function complete(string $id): JSONResponse
    {
        $resultText = $this->request->getParam('resultText', '');

        try {
            $taskData = $this->getTaskStub(id: $id);
            if ($taskData === null) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Task not found')],
                    Http::STATUS_NOT_FOUND
                );
            }

            $transition = $this->callbackService->validateStatusTransition(
                $taskData['status'] ?? 'open',
                'afgerond'
            );

            if ($transition['valid'] === false) {
                return new JSONResponse(
                    ['error' => $this->l10n->t($transition['reason'])],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $taskData = $this->callbackService->applyCompletion($taskData, $resultText);

            // Notify the creating agent about completion.
            $createdBy = $taskData['createdBy'] ?? '';
            if (empty($createdBy) === false) {
                $user   = $this->userSession->getUser();
                $author = 'system';
                if ($user !== null) {
                    $author = $user->getUID();
                }

                $this->notificationService->notifyTaskCompleted(
                    $taskData['subject'] ?? '',
                    $resultText,
                    $createdBy,
                    $id,
                    $author
                );
            }

            return new JSONResponse(['task' => $taskData]);
        } catch (\Exception $e) {
            $this->logger->error('CallbackController::complete failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to complete task')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end complete()

    /**
     * Reassign a task to a different user or group.
     *
     * @param string $id The task object ID.
     *
     * @return JSONResponse The response with updated task data.
     *
     * @NoAdminRequired
     * @spec openspec/changes/callback-management/tasks.md#2.1
     */
    public function reassign(string $id): JSONResponse
    {
        $assignee     = $this->request->getParam('assignee', '');
        $assigneeType = $this->request->getParam('assigneeType', '');

        if (empty($assignee) === true || in_array($assigneeType, ['user', 'group'], true) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Assignee and valid assignee type are required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $taskData = $this->getTaskStub(id: $id);
            if ($taskData === null) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Task not found')],
                    Http::STATUS_NOT_FOUND
                );
            }

            $taskData = $this->callbackService->applyReassignment($taskData, $assignee, $assigneeType);

            // Log the reassignment as an attempt entry.
            $taskData = $this->callbackService->addAttempt($taskData, 'hertoegewezen', '');

            // Notify the new assignee if it's a user.
            if ($assigneeType === 'user') {
                $user   = $this->userSession->getUser();
                $author = 'system';
                if ($user !== null) {
                    $author = $user->getUID();
                }

                $this->notificationService->notifyTaskReassigned(
                    $taskData['subject'] ?? '',
                    $assignee,
                    $id,
                    $author,
                    $taskData['deadline'] ?? ''
                );
            }

            return new JSONResponse(['task' => $taskData]);
        } catch (\Exception $e) {
            $this->logger->error('CallbackController::reassign failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to reassign task')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end reassign()

    /**
     * Get a task data stub by ID.
     *
     * In production, this queries OpenRegister. For now, returns a minimal
     * structure that the frontend can use.
     *
     * @param string $id The task object ID.
     *
     * @return array<string, mixed>|null Task data or null if not found.
     */
    private function getTaskStub(string $id): ?array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');

        if ($register === '' || $schema === '') {
            $this->logger->warning('CallbackController: register or task_schema not configured');
            return null;
        }

        // NOTE: In production, fetch the actual task from OpenRegister:
        // GET /api/registers/{register}/schemas/{schema}/objects/{id}
        // For now, return a stub indicating the task exists.
        // The stub uses 'in_behandeling' status to allow transitions to 'afgerond'
        // and a group assignment to allow claims.
        return [
            'id'              => $id,
            'status'          => 'in_behandeling',
            'type'            => 'terugbelverzoek',
            'subject'         => '',
            'attempts'        => [],
            'assigneeUserId'  => null,
            'assigneeGroupId' => 'callback-handlers',
            'createdBy'       => '',
            'deadline'        => '',
        ];
    }//end getTaskStub()
}//end class
