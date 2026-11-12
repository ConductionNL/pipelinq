<?php

/**
 * Pipelinq ScheduledTaskDeadlines.
 *
 * What happens when a scheduled task reaches its deadline: the expiry cut, and who is notified.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\ScheduledTask
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\ScheduledTask;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the task_expiry settings and notifies the assignee of a task moved
 * into processing, or the assignee and the escalation group of an expired
 * one. A failed notification is logged and does not stop the run.
 *
 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
 */
class ScheduledTaskDeadlines {
	/**
	 * Default threshold in minutes for marking overdue open tasks as expired.
	 *
	 * @var int
	 */
	private const EXPIRY_THRESHOLD_MINUTES = 240;

	/**
	 * Constructor.
	 *
	 * @param NotificationService $notificationService Notification dispatch.
	 * @param IAppConfig          $appConfig           App config (task_expiry settings).
	 * @param IGroupManager       $groupManager        Group manager (escalation group).
	 * @param LoggerInterface     $logger              Logger.
	 */
	public function __construct(
		private readonly NotificationService $notificationService,
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * How long past its deadline an open task waits before it expires, in
	 * minutes: the admin setting `task_expiry.escalation_threshold_seconds`
	 * (4 hours when unset).
	 *
	 * @return int
	 *
	 * @spec openspec/specs/task-background-jobs/spec.md#requirement-task-expiry-background-job
	 */
	public function expiryCutMinutes(): int {
		$seconds = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'task_expiry.escalation_threshold_seconds',
			default: ''
		);
		if (is_numeric(value: $seconds) === false || (int)$seconds < 0) {
			return self::EXPIRY_THRESHOLD_MINUTES;
		}

		return intdiv(num1: (int)$seconds, num2: 60);
	}//end expiryCutMinutes()

	/**
	 * Notify a task's assignee that it has moved into processing.
	 *
	 * No-op when the task has no assignee. Notification failures are logged and
	 * swallowed so they do not abort the batch run.
	 *
	 * @param array<string,mixed> $task The task being transitioned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
	 */
	public function notifyAssignee(array $task): void {
		$assignee = $task['assigneeUserId'] ?? '';
		if ($assignee === '') {
			return;
		}

		try {
			$this->notificationService->notifyAssignment(
				entityType: 'task',
				title: (string)($task['subject'] ?? 'Scheduled task'),
				assigneeUserId: (string)$assignee,
				objectId: (string)($task['id'] ?? ''),
				author: 'system'
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ScheduledTaskService: notification dispatch failed',
				['exception' => $e]
			);
		}
	}//end notifyAssignee()

	/**
	 * Escalate an expired task to its assignee.
	 *
	 * Fired when an open task passes its deadline beyond the expiry cut and is
	 * transitioned to `verlopen`. No-op when the task has no assignee.
	 * Notification failures are logged and swallowed so they never abort the
	 * batch run.
	 *
	 * @param array<string,mixed> $task The task being expired.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/task-background-jobs/spec.md#requirement-deadline-escalation-notifications
	 */
	public function notifyExpired(array $task): void {
		foreach ($this->expiryRecipients(task: $task) as $userId) {
			$this->notifyExpiredTo(task: $task, userId: $userId);
		}
	}//end notifyExpired()

	/**
	 * Who hears of an expired task: its assignee first, then every member of
	 * the escalation group not already named.
	 *
	 * @param array<string, mixed> $task The task.
	 *
	 * @return array<int, string>
	 */
	private function expiryRecipients(array $task): array {
		$recipients = [];
		$assignee   = (string)($task['assigneeUserId'] ?? '');
		if ($assignee !== '') {
			$recipients[] = $assignee;
		}

		foreach ($this->getEscalationUserIds() as $userId) {
			if (in_array(needle: $userId, haystack: $recipients, strict: true) === false) {
				$recipients[] = $userId;
			}
		}

		return $recipients;
	}//end expiryRecipients()

	/**
	 * Tell one user the task expired; a failure is logged and the next user
	 * still hears of it.
	 *
	 * @param array<string, mixed> $task   The task.
	 * @param string               $userId The user.
	 *
	 * @return void
	 */
	private function notifyExpiredTo(array $task, string $userId): void {
		try {
			$this->notificationService->notifyTaskExpired(
				title: (string)($task['subject'] ?? 'Scheduled task'),
				userId: $userId,
				objectId: (string)($task['id'] ?? ''),
				deadline: (string)($task['deadline'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->error('ScheduledTaskService: expiry notification dispatch failed', ['exception' => $e]);
		}
	}//end notifyExpiredTo()

	/**
	 * The members of the escalation group (app config
	 * `task_expiry.escalation_group`), who are told about every expired task
	 * besides its assignee. Empty when no group is configured or it is gone.
	 *
	 * @return array<int,string> User ids.
	 *
	 * @spec openspec/specs/task-background-jobs/spec.md#requirement-deadline-escalation-notifications
	 */
	private function getEscalationUserIds(): array {
		$groupId = $this->appConfig->getValueString(app: Application::APP_ID, key: 'task_expiry.escalation_group', default: '');
		if ($groupId === '') {
			return [];
		}

		$group = $this->groupManager->get(gid: $groupId);
		if ($group === null) {
			return [];
		}

		$userIds = [];
		foreach ($group->getUsers() as $user) {
			$userIds[] = $user->getUID();
		}

		return $userIds;
	}//end getEscalationUserIds()
}//end class
