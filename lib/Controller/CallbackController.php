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

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CallbackServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\NotificationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ScheduledTaskServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
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
 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-2.1
 */
class CallbackController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param CallbackServiceInterface $callbackService The callback service.
	 * @param NotificationServiceInterface $notificationService The notification service.
	 * @param ScheduledTaskServiceInterface $scheduledTaskService The scheduled task service.
	 * @param IGroupManager $groupManager The group manager.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private CallbackServiceInterface $callbackService,
		private NotificationServiceInterface $notificationService,
		private ScheduledTaskServiceInterface $scheduledTaskService,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Log a callback attempt.
	 *
	 * @param string $id The task object ID.
	 *
	 * @return JSONResponse The response with updated task data.
	 *
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function attempt(string $id): JSONResponse {
		$user = $this->currentUser();
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$this->applyAcceptLanguage();

		$result = $this->request->getParam('result', '');
		$notes = $this->request->getParam('notes', '');

		if (empty($result) === true) {
			return new JSONResponse(
				['error' => $this->l10n->t('Attempt result is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$taskData = $this->authorizedTask(id: $id, userId: $user->getUID());
			if ($taskData instanceof JSONResponse) {
				return $taskData;
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
			$this->logFailure(action: 'attempt', error: $e);
			return $this->serverError(message: $this->l10n->t('Failed to log callback attempt'));
		}//end try
	}//end attempt()

	/**
	 * Claim a group-assigned task for the current user.
	 *
	 * @param string $id The task object ID.
	 *
	 * @return JSONResponse The response with updated task data.
	 *
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function claim(string $id): JSONResponse {
		$user = $this->currentUser();
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$this->applyAcceptLanguage();

		try {
			$taskData = $this->taskOrNotFound(id: $id);
			if ($taskData instanceof JSONResponse) {
				return $taskData;
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
			$this->logFailure(action: 'claim', error: $e);
			return $this->serverError(message: $this->l10n->t('Failed to claim task'));
		}//end try
	}//end claim()

	/**
	 * Complete a callback task.
	 *
	 * @param string $id The task object ID.
	 *
	 * @return JSONResponse The response with updated task data.
	 *
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function complete(string $id): JSONResponse {
		$user = $this->currentUser();
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$this->applyAcceptLanguage();

		$resultText = $this->request->getParam('resultText', '');

		try {
			$taskData = $this->authorizedTask(id: $id, userId: $user->getUID());
			if ($taskData instanceof JSONResponse) {
				return $taskData;
			}

			$refusal = $this->completionRefusal(taskData: $taskData);
			if ($refusal !== null) {
				return $refusal;
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
			$this->logFailure(action: 'complete', error: $e);
			return $this->serverError(message: $this->l10n->t('Failed to complete task'));
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
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-2.1
	 */
	public function reassign(string $id): JSONResponse {
		$user = $this->currentUser();
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$assignee = $this->request->getParam('assignee', '');
		$assigneeType = $this->request->getParam('assigneeType', '');

		$refusal = $this->reassignRefusal(assignee: $assignee, assigneeType: $assigneeType) ?? $this->nonAdminRefusal(user: $user);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$taskData = $this->taskOrNotFound(id: $id);
			if ($taskData instanceof JSONResponse) {
				return $taskData;
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
			$this->logFailure(action: 'reassign', error: $e);
			return $this->serverError(message: $this->l10n->t('Failed to reassign task'));
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

	/**
	 * The logged-in user, or the 401 refusal.
	 *
	 * @return IUser|JSONResponse The user, or the refusal.
	 */
	private function currentUser(): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		return $user;
	}//end currentUser()

	/**
	 * Translate the task texts into the request's language.
	 *
	 * @return void
	 */
	private function applyAcceptLanguage(): void {
		$this->scheduledTaskService->applyAcceptLanguage(
			acceptLanguage: $this->request->getHeader('Accept-Language')
		);
	}//end applyAcceptLanguage()

	/**
	 * The task, or the 404 refusal.
	 *
	 * @param string $id The task object ID.
	 *
	 * @return array<string, mixed>|JSONResponse The task data, or the refusal.
	 */
	private function taskOrNotFound(string $id): array|JSONResponse {
		$taskData = $this->fetchTask(id: $id);
		if ($taskData === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Task not found')],
				Http::STATUS_NOT_FOUND
			);
		}

		return $taskData;
	}//end taskOrNotFound()

	/**
	 * The task the user may change, or the 404 / 403 refusal.
	 *
	 * @param string $id     The task object ID.
	 * @param string $userId The acting user ID.
	 *
	 * @return array<string, mixed>|JSONResponse The task data, or the refusal.
	 */
	private function authorizedTask(string $id, string $userId): array|JSONResponse {
		$taskData = $this->taskOrNotFound(id: $id);
		if ($taskData instanceof JSONResponse) {
			return $taskData;
		}

		$authCheck = $this->checkTaskAuth(task: $taskData, userId: $userId);
		if ($authCheck !== null) {
			return $authCheck;
		}

		return $taskData;
	}//end authorizedTask()

	/**
	 * Log a failed action.
	 *
	 * @param string     $action The action name.
	 * @param \Exception $error  The failure.
	 *
	 * @return void
	 */
	private function logFailure(string $action, \Exception $error): void {
		$this->logger->error('CallbackController::' . $action . ' failed', ['exception' => $error->getMessage()]);
	}//end logFailure()

	/**
	 * A 500 answer with a translated message.
	 *
	 * @param string $message The translated message.
	 *
	 * @return JSONResponse The response.
	 */
	private function serverError(string $message): JSONResponse {
		return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end serverError()

	/**
	 * The 400 refusal when the task cannot move to completed.
	 *
	 * @param array<string, mixed> $taskData The task data.
	 *
	 * @return JSONResponse|null The refusal, or null when the transition is valid.
	 */
	private function completionRefusal(array $taskData): ?JSONResponse {
		$transition = $this->callbackService->validateStatusTransition(
			$taskData['status'] ?? 'open',
			'completed'
		);

		if ($transition['valid'] === false) {
			return new JSONResponse(
				['error' => $this->l10n->t($transition['reason'])],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end completionRefusal()

	/**
	 * The 400 refusal for a missing assignee or an assignee type other than user or group.
	 *
	 * @param mixed $assignee     The requested assignee.
	 * @param mixed $assigneeType The requested assignee type.
	 *
	 * @return JSONResponse|null The refusal, or null when both are valid.
	 */
	private function reassignRefusal(mixed $assignee, mixed $assigneeType): ?JSONResponse {
		if (empty($assignee) === true || in_array($assigneeType, ['user', 'group'], true) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Assignee and valid assignee type are required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end reassignRefusal()

	/**
	 * The 403 refusal for a user who is not an administrator: reassigning is
	 * a manager/admin-only operation.
	 *
	 * @param IUser $user The acting user.
	 *
	 * @return JSONResponse|null The refusal, or null for an administrator.
	 */
	private function nonAdminRefusal(IUser $user): ?JSONResponse {
		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Only administrators may reassign tasks')],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end nonAdminRefusal()
}//end class
