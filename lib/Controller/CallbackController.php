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
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
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
     * @param IUserManager        $userManager         The user manager.
     * @param IGroupManager       $groupManager        The group manager.
     * @param IClientService      $clientService       The HTTP client service.
     * @param IL10N               $l10n                The localization service.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        IRequest $request,
        private CallbackService $callbackService,
        private NotificationService $notificationService,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IClientService $clientService,
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
     * @NoCSRFRequired
     * @spec            openspec/changes/callback-management/tasks.md#2.1
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
            // Fetch task data from OpenRegister.
            $taskData = $this->getTaskData(id: $id);
            if ($taskData === null) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Task not found')],
                    Http::STATUS_NOT_FOUND
                );
            }

            // Check authorization: user must be the assignee or an admin.
            $auth = $this->authorizeTaskAccess(taskData: $taskData);
            if ($auth['authorized'] === false) {
                return new JSONResponse(
                    ['error' => $this->l10n->t($auth['reason'])],
                    Http::STATUS_FORBIDDEN
                );
            }

            $taskData     = $this->callbackService->addAttempt(taskData: $taskData, result: $result, notes: $notes);
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
     * @NoCSRFRequired
     * @spec            openspec/changes/callback-management/tasks.md#2.1
     */
    public function claim(string $id): JSONResponse
    {
        try {
            $taskData = $this->getTaskData(id: $id);
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
     * @NoCSRFRequired
     * @spec            openspec/changes/callback-management/tasks.md#2.1
     */
    public function complete(string $id): JSONResponse
    {
        $resultText = $this->request->getParam('resultText', '');

        // Validate resultText is required per spec.
        if (empty($resultText) === true) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Result text is required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $taskData = $this->getTaskData(id: $id);
            if ($taskData === null) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Task not found')],
                    Http::STATUS_NOT_FOUND
                );
            }

            // Check authorization: user must be the assignee or an admin.
            $auth = $this->authorizeTaskAccess(taskData: $taskData);
            if ($auth['authorized'] === false) {
                return new JSONResponse(
                    ['error' => $this->l10n->t($auth['reason'])],
                    Http::STATUS_FORBIDDEN
                );
            }

            $transition = $this->callbackService->validateStatusTransition(
                currentStatus: $taskData['status'] ?? 'open',
                targetStatus: 'afgerond'
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
     * @NoCSRFRequired
     * @spec            openspec/changes/callback-management/tasks.md#2.1
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
            // Validate that the assignee exists in Nextcloud.
            if ($assigneeType === 'user') {
                if ($this->userManager->get($assignee) === null) {
                    return new JSONResponse(
                        ['error' => $this->l10n->t('Assignee user not found')],
                        Http::STATUS_UNPROCESSABLE_ENTITY
                    );
                }
            } else if ($assigneeType === 'group') {
                if ($this->groupManager->get($assignee) === null) {
                    return new JSONResponse(
                        ['error' => $this->l10n->t('Assignee group not found')],
                        Http::STATUS_UNPROCESSABLE_ENTITY
                    );
                }
            }

            $taskData = $this->getTaskData(id: $id);
            if ($taskData === null) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Task not found')],
                    Http::STATUS_NOT_FOUND
                );
            }

            // Check authorization: user must be the assignee or an admin.
            $auth = $this->authorizeTaskAccess(taskData: $taskData);
            if ($auth['authorized'] === false) {
                return new JSONResponse(
                    ['error' => $this->l10n->t($auth['reason'])],
                    Http::STATUS_FORBIDDEN
                );
            }

            $taskData = $this->callbackService->applyReassignment(
                taskData: $taskData,
                assignee: $assignee,
                assigneeType: $assigneeType
            );

            // Log the reassignment as an attempt entry.
            $taskData = $this->callbackService->addAttempt(
                taskData: $taskData,
                result: 'hertoegewezen',
                notes: ''
            );

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
     * Fetch task data from OpenRegister by ID.
     *
     * @param string $id The task object ID.
     *
     * @return array<string, mixed>|null Task data or null if not found.
     */
    private function getTaskData(string $id): ?array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');

        if ($register === '' || $schema === '') {
            $this->logger->warning('CallbackController: register or task_schema not configured');
            return null;
        }

        try {
            $url = sprintf(
                '/api/registers/%s/schemas/%s/objects/%s',
                urlencode($register),
                urlencode($schema),
                urlencode($id)
            );

            $client   = $this->clientService->newClient();
            $response = $client->get('http://localhost'.$url);
            $status   = $response->getStatusCode();

            if ($status !== 200) {
                $this->logger->warning('OpenRegister query failed', ['url' => $url, 'status' => $status]);
                return null;
            }

            $data = json_decode($response->getBody(), associative: true);
            if (is_array($data) === false) {
                $this->logger->warning('OpenRegister response invalid', ['url' => $url]);
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('CallbackController: OpenRegister fetch error', ['exception' => $e->getMessage()]);
            return null;
        }//end try
    }//end getTaskData()

    /**
     * Authorize task access for the current user.
     *
     * Checks if the user is either the task assignee, a member of the assigned group,
     * or a Nextcloud admin.
     *
     * @param array<string, mixed> $taskData The task data array.
     *
     * @return array{authorized: bool, reason: string} Authorization result.
     */
    private function authorizeTaskAccess(array $taskData): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [
                'authorized' => false,
                'reason'     => 'No authenticated user',
            ];
        }

        $userId = $user->getUID();

        // Admins always have access.
        if ($this->groupManager->isAdmin($userId) === true) {
            return [
                'authorized' => true,
                'reason'     => '',
            ];
        }

        // Check if user is the assigned user.
        $assigneeUserId = $taskData['assigneeUserId'] ?? null;
        if ($assigneeUserId === $userId) {
            return [
                'authorized' => true,
                'reason'     => '',
            ];
        }

        // Check if user is in the assigned group.
        $assigneeGroupId = $taskData['assigneeGroupId'] ?? null;
        if ($assigneeGroupId !== null && $this->groupManager->isInGroup($userId, $assigneeGroupId) === true) {
            return [
                'authorized' => true,
                'reason'     => '',
            ];
        }

        return [
            'authorized' => false,
            'reason'     => 'User is not authorized to access this task',
        ];
    }//end authorizeTaskAccess()
}//end class
