<?php

/**
 * Pipelinq ScheduledTaskRights.
 *
 * Who creates a scheduled task, and who may change one.
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
 * @spec openspec/changes/task-background-jobs/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\ScheduledTask;

use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The session user is a new task's creator ('system' without a session); the
 * assignee, a member of the assigned group or an admin may change a task.
 *
 * @spec openspec/changes/task-background-jobs/tasks.md#task-1
 */
class ScheduledTaskRights {
	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  User session (createdBy derivation).
	 * @param IGroupManager $groupManager Group manager (admin + group checks).
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The creator recorded on a new task.
	 *
	 * @return string The session user's UID, or 'system' without a session.
	 *
	 * @spec openspec/changes/task-background-jobs/tasks.md#task-1
	 */
	public function createdBy(): string {
		$user = $this->userSession->getUser();
		if ($user instanceof IUser) {
			return $user->getUID();
		}

		return 'system';
	}//end createdBy()

	/**
	 * Authorise a mutation on a task.
	 *
	 * Allowed if the user is the assignee, a member of the assigned group, or
	 * an administrator. Otherwise throws an OCSForbiddenException.
	 *
	 * @param array<string, mixed> $task The task object.
	 * @param string $userId The acting user ID.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When the user is not authorised.
	 *
	 * @spec openspec/changes/task-background-jobs/tasks.md#task-1
	 */
	public function authorizeTaskMutation(array $task, string $userId): void {
		if ($userId === '') {
			throw new OCSForbiddenException('Not authorized');
		}

		$assigneeUserId = (string)($task['assigneeUserId'] ?? '');
		if ($assigneeUserId !== '' && $assigneeUserId === $userId) {
			return;
		}

		$assigneeGroupId = (string)($task['assigneeGroupId'] ?? '');
		if ($assigneeGroupId !== ''
			&& $this->groupManager->isInGroup($userId, $assigneeGroupId) === true
		) {
			return;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return;
		}

		throw new OCSForbiddenException('Not authorized');
	}//end authorizeTaskMutation()
}//end class
