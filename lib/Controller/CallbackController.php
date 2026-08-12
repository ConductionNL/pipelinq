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
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
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
 * @spec openspec/changes/callback-management/tasks.md#task-2.1
 */
class CallbackController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param CallbackService $callbackService The callback service.
	 * @param NotificationService $notificationService The notification service.
	 * @param ScheduledTaskService $scheduledTaskService The scheduled task service.
	 * @param IGroupManager $groupManager The group manager.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private CallbackService $callbackService,
		private NotificationService $notificationService,
		private ScheduledTaskService $scheduledTaskService,
		private IGroupManager $groupManager,
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
	 * @spec openspec/changes/callback-management/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function attempt(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$this->scheduledTaskService->applyAcceptLanguage(
			acceptLanguage: $this->request->getHeader('Accept-Language')
		);

		$result = $this->request->getParam('result', '');
		$notes = $this->request->getParam('notes', '');

		if (empty($result) === true) {
			return new JSONResponse(
				['error' => $this->l10n->t('Attempt result is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$taskData = $this->fetchTask(id: $id);
			if ($taskData === null) {
				return new JSONResponse(
					['error' => $this->l10n->t('Task not found')],
					Http::STATUS_NOT_FOUND
				);
			}

			$authCheck = $this->checkTaskAuth(task: $taskData, userId: $user->getUID());
			if ($authCheck !== null) {
				return $authCheck;
			}

			$taskData = $this->callbackService->addAttempt($taskData, $result, $notes);
			$taskData = $this->scheduledTaskService->updateScheduledTask($id, $taskData);
			$suggestClose = $this->callbackService->isAttemptThresholdReached($taskData);

			return new JSONResponse(
				[
					'task' => $taskData,
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
	 * @spec openspec/changes/callback-management/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function claim(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$this->scheduledTaskService->applyAcceptLanguage(
			acceptLanguage: $this->request->getHeader('Accept-Language')
		);

		try {
			$taskData = $this->fetchTask(id: $id);
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
			$taskData = $this->scheduledTaskService->updateScheduledTask($id, $taskData);

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
	 * @spec openspec/changes/callback-management/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function complete(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$this->scheduledTaskService->applyAcceptLanguage(
			acceptLanguage: $this->request->getHeader('Accept-Language')
		);

		$resultText = $this->request->getParam('resultText', '');

		try {
			$taskData = $this->fetchTask(id: $id);
			if ($taskData === null) {
				return new JSONResponse(
					['error' => $this->l10n->t('Task not found')],
					Http::STATUS_NOT_FOUND
				);
			}

			$authCheck = $this->checkTaskAuth(task: $taskData, userId: $user->getUID());
			if ($authCheck !== null) {
				return $authCheck;
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
			$taskData = $this->scheduledTaskService->updateScheduledTask($id, $taskData);

			// Notify the creating agent about completion.
			$createdBy = $taskData['createdBy'] ?? '';
			if (empty($createdBy) === false) {
				$author = $user->getUID();

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
	 * @auth admin-only Moves a task onto another user or group; the body
	 *       additionally enforces it with an isAdmin() check.
	 *
	 * @spec openspec/changes/callback-management/tasks.md#task-2.1
	 */
	public function reassign(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$assignee = $this->request->getParam('assignee', '');
		$assigneeType = $this->request->getParam('assigneeType', '');

		if (empty($assignee) === true || in_array($assigneeType, ['user', 'group'], true) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Assignee and valid assignee type are required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Reassign is a manager/admin-only operation.
		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Only administrators may reassign tasks')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$taskData = $this->fetchTask(id: $id);
			if ($taskData === null) {
				return new JSONResponse(
					['error' => $this->l10n->t('Task not found')],
					Http::STATUS_NOT_FOUND
				);
			}

			$taskData = $this->callbackService->applyReassignment($taskData, $assignee, $assigneeType);

			// Log the reassignment as an attempt entry.
			$taskData = $this->callbackService->addAttempt($taskData, 'hertoegewezen', '');
			$taskData = $this->scheduledTaskService->updateScheduledTask($id, $taskData);

			// Notify the new assignee if it's a user.
			if ($assigneeType === 'user') {
				$author = $user->getUID();

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
	 * Fetch a task from the real persistence layer (ScheduledTaskService).
	 *
	 * @param string $id The task object ID.
	 *
	 * @return array<string, mixed>|null Task data or null if not found.
	 */
	private function fetchTask(string $id): ?array {
		try {
			return $this->scheduledTaskService->getScheduledTask($id);
		} catch (\RuntimeException $e) {
			return null;
		}
	}//end fetchTask()

	/**
	 * Check that the current user is authorised to mutate a task.
	 *
	 * Returns a JSONResponse with a 403 status when the user is not allowed,
	 * or null when the user is authorised.
	 *
	 * @param array<string, mixed> $task The task object.
	 * @param string $userId The acting user ID.
	 *
	 * @return JSONResponse|null Null on success, 403 response on failure.
	 */
	private function checkTaskAuth(array $task, string $userId): ?JSONResponse {
		try {
			$this->scheduledTaskService->authorizeTaskMutation($task, $userId);
			return null;
		} catch (\OCP\AppFramework\OCS\OCSForbiddenException $e) {
			return new JSONResponse(
				['error' => $this->l10n->t('Not authorized to modify this task')],
				Http::STATUS_FORBIDDEN
			);
		}
	}//end checkTaskAuth()
}//end class
