<?php

/**
 * Pipelinq SessionUser.
 *
 * The session user of a request, and whether a user is an administrator.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
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
 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Reads the user from the session and asks the group manager about admin
 * membership.
 *
 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-2
 */
class SessionUser {
	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The user session.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The session user's id.
	 *
	 * @return string The UID, or an empty string without a session.
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-2
	 */
	public function uid(): string {
		$user = $this->userSession->getUser();
		if ($user instanceof IUser) {
			return $user->getUID();
		}

		return '';
	}//end uid()

	/**
	 * Whether a user is an administrator.
	 *
	 * @param string $uid The user.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-2
	 */
	public function isAdmin(string $uid): bool {
		return $this->groupManager->isAdmin($uid);
	}//end isAdmin()
}//end class
