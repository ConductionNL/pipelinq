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
use OCA\Pipelinq\Lifecycle\SessionUser;
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

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
class SchedulesController extends Controller {
	/**
	 * The optional fields of a new task, dropped when blank so they do not
	 * clobber schema defaults.
	 *
	 * @var array<int, string>
	 */
	private const OPTIONAL_FIELDS = [
		'description',
		'assigneeUserId',
		'assigneeGroupId',
		'clientId',
		'requestId',
		'callbackPhoneNumber',
		'preferredTimeSlot',
	];

	/**
	 * The fields only an admin may set on an update.
	 *
	 * @var array<int, string>
	 */
	private const ADMIN_ONLY_FIELDS = ['assigneeUserId', 'assigneeGroupId', 'status', 'createdAt', 'attempts'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ScheduledTaskService $scheduledTaskService Schedule service.
	 * @param SessionUser $sessionUser The session user and their admin membership.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private ScheduledTaskService $scheduledTaskService,
		private SessionUser $sessionUser,
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
	public function index(): JSONResponse {
		$userId = $this->sessionUser->uid();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		$this->applyAcceptLanguage();

		try {
			// Non-admins always see only their own tasks regardless of the
			// requested filter (prevents cross-user IDOR via query param).
			$requestedAssignee = $userId;
			if ($this->sessionUser->isAdmin($userId) === true) {
				$requestedAssignee = (string)$this->request->getParam('assigneeUserId', '');
			}

			$params = [
				'status' => $this->request->getParam('status', ''),
				'assigneeUserId' => $requestedAssignee,
				'assigneeGroupId' => $this->request->getParam('assigneeGroupId', ''),
				'from' => $this->request->getParam('from', ''),
				'to' => $this->request->getParam('to', ''),
				'_page' => (int)$this->request->getParam('_page', 1),
				'_limit' => (int)$this->request->getParam('_limit', 20),
			];

			return new JSONResponse($this->scheduledTaskService->getScheduledTasks($params));
		} catch (Throwable $e) {
			return $this->failed(action: 'index', error: $e);
		}
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
	public function create(): JSONResponse {
		$userId = $this->sessionUser->uid();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		$required = [
			'type' => (string)$this->request->getParam('type', ''),
			'subject' => (string)$this->request->getParam('subject', ''),
			'deadline' => (string)$this->request->getParam('deadline', ''),
		];
		if (in_array($required['type'], ScheduledTaskService::VALID_TYPES, true) === false
			|| $required['subject'] === ''
			|| $required['deadline'] === ''
		) {
			return $this->invalidInput();
		}

		$data = $this->buildScheduledTaskData(required: $required, userId: $userId);

		try {
			return new JSONResponse($this->scheduledTaskService->createScheduledTask($data), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return $this->invalidInput();
		} catch (Throwable $e) {
			return $this->failed(action: 'create', error: $e);
		}
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
	public function pending(): JSONResponse {
		$userId = $this->sessionUser->uid();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		try {
			$window = max(1, min((int)$this->request->getParam('window', 60), 1440));
			$isAdmin = $this->sessionUser->isAdmin($userId);
			$items = $this->scheduledTaskService->getPendingTasks($window);

			// Non-admins see only their own pending tasks.
			if ($isAdmin === false) {
				$items = array_values(
					array_filter(
						$items,
						static fn (array $task): bool => ($task['assigneeUserId'] ?? '') === $userId
					)
				);
			}

			return new JSONResponse(['items' => $items, 'total' => count($items)]);
		} catch (Throwable $e) {
			return $this->failed(action: 'pending', error: $e);
		}
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
	public function show(string $id): JSONResponse {
		$userId = $this->sessionUser->uid();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		$this->applyAcceptLanguage();

		try {
			$task = $this->scheduledTaskService->getScheduledTask($id);

			// Non-admins may only view their own tasks.
			if ($this->sessionUser->isAdmin($userId) === false && ($task['assigneeUserId'] ?? '') !== $userId) {
				return $this->notFound();
			}

			return new JSONResponse($task);
		} catch (\RuntimeException $e) {
			return $this->notFound();
		} catch (Throwable $e) {
			return $this->failed(action: 'show', error: $e);
		}
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
	public function update(string $id): JSONResponse {
		$refusal = $this->mutationRefusal(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		$payload = $this->request->getParams();
		unset($payload['id'], $payload['_route']);

		// Strip fields that only admins may set to prevent privilege escalation.
		if ($this->sessionUser->isAdmin($this->sessionUser->uid()) === false) {
			$payload = array_diff_key($payload, array_flip(self::ADMIN_ONLY_FIELDS));
		}

		try {
			return new JSONResponse($this->scheduledTaskService->updateScheduledTask($id, $payload));
		} catch (\InvalidArgumentException $e) {
			return $this->invalidInput();
		} catch (Throwable $e) {
			return $this->failed(action: 'update', error: $e);
		}
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
	public function destroy(string $id): JSONResponse {
		$refusal = $this->mutationRefusal(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$this->scheduledTaskService->deleteScheduledTask($id);
			return new JSONResponse([], Http::STATUS_NO_CONTENT);
		} catch (Throwable $e) {
			return $this->failed(action: 'destroy', error: $e);
		}
	}//end destroy()

	/**
	 * Assemble the scheduled-task payload from request params.
	 *
	 * Scopes the assignee to self and drops the assignee group for non-admins,
	 * and strips blank optional fields so they do not clobber schema defaults.
	 *
	 * @param array{type: string, subject: string, deadline: string} $required The validated type, subject and deadline.
	 * @param string $userId The current user's UID.
	 *
	 * @return array<string, string> The task data ready for the service.
	 */
	private function buildScheduledTaskData(array $required, string $userId): array {
		$isAdmin = $this->sessionUser->isAdmin($userId);
		$requestedAssignee = (string)$this->request->getParam('assigneeUserId', '');
		// Non-admins may not assign tasks to other users; silently scope to self.
		if ($isAdmin === false && $requestedAssignee !== '' && $requestedAssignee !== $userId) {
			$requestedAssignee = $userId;
		}

		$requestedGroup = '';
		if ($isAdmin === true) {
			$requestedGroup = (string)$this->request->getParam('assigneeGroupId', '');
		}

		$data = $required + [
			'description' => (string)$this->request->getParam('description', ''),
			'priority' => (string)$this->request->getParam('priority', 'normal'),
			'assigneeUserId' => $requestedAssignee,
			'assigneeGroupId' => $requestedGroup,
			'clientId' => (string)$this->request->getParam('clientId', ''),
			'requestId' => (string)$this->request->getParam('requestId', ''),
			'callbackPhoneNumber' => (string)$this->request->getParam('callbackPhoneNumber', ''),
			'preferredTimeSlot' => (string)$this->request->getParam('preferredTimeSlot', ''),
		];

		foreach (self::OPTIONAL_FIELDS as $optional) {
			if ($data[$optional] === '') {
				unset($data[$optional]);
			}
		}

		return $data;
	}//end buildScheduledTaskData()

	/**
	 * The refusal for changing a task, or null when the session user may.
	 *
	 * A missing task is 404; a task the user may not change is 403.
	 *
	 * @param string $id The task UUID.
	 *
	 * @return JSONResponse|null
	 */
	private function mutationRefusal(string $id): ?JSONResponse {
		try {
			$task = $this->scheduledTaskService->getScheduledTask($id);
		} catch (\RuntimeException $e) {
			return $this->notFound();
		}

		try {
			$this->scheduledTaskService->authorizeTaskMutation($task, $this->sessionUser->uid());
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['message' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end mutationRefusal()

	/**
	 * Hand the request's Accept-Language to the task service.
	 *
	 * @return void
	 */
	private function applyAcceptLanguage(): void {
		$this->scheduledTaskService->applyAcceptLanguage(
			acceptLanguage: $this->request->getHeader('Accept-Language')
		);
	}//end applyAcceptLanguage()

	/**
	 * Log a failed action and answer 500 without its detail.
	 *
	 * @param string    $action The action.
	 * @param Throwable $error  The failure.
	 *
	 * @return JSONResponse
	 */
	private function failed(string $action, Throwable $error): JSONResponse {
		$this->logger->error('SchedulesController::'.$action.' failed', ['exception' => $error]);
		return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failed()

	/**
	 * The 401 answer.
	 *
	 * @return JSONResponse
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()

	/**
	 * The 404 answer.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
	}//end notFound()

	/**
	 * The 400 answer.
	 *
	 * @return JSONResponse
	 */
	private function invalidInput(): JSONResponse {
		return new JSONResponse(['message' => 'Invalid input'], Http::STATUS_BAD_REQUEST);
	}//end invalidInput()
}//end class
