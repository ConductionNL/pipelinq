<?php

/**
 * Pipelinq WorkScope.
 *
 * Whose work a My work list reads: the user's own, their team's or the whole
 * organisation's.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Worklist
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Worklist;

use InvalidArgumentException;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;

/**
 * Resolves a My work scope to the users whose work it lists.
 *
 * - `mine`: the user alone. Everyone has it.
 * - `team`: every member of each team manager group the user belongs to. The
 *   groups are named in the app setting `my_work_manager_groups` (group ids,
 *   separated by commas, spaces or new lines). Only a member of such a group
 *   has it, and may narrow it to one member of that team.
 * - `organisation`: everyone's work. Only a Nextcloud administrator has it.
 *
 * A scope only widens whose work is asked for. OpenRegister still reads every
 * object under the user's own permissions (design D5).
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
 */
class WorkScope {

	/**
	 * The user's own work.
	 *
	 * @var string
	 */
	public const MINE = 'mine';

	/**
	 * The work of the user's team.
	 *
	 * @var string
	 */
	public const TEAM = 'team';

	/**
	 * Everyone's work.
	 *
	 * @var string
	 */
	public const ORGANISATION = 'organisation';

	/**
	 * The app setting naming the team manager groups.
	 *
	 * @var string
	 */
	public const MANAGER_GROUPS_KEY = 'my_work_manager_groups';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig    The app configuration holding the manager groups.
	 * @param IGroupManager $groupManager The Nextcloud groups.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The scopes a user may choose, `mine` first.
	 *
	 * @param string $userId The Nextcloud UID.
	 *
	 * @return array<int, string> The scopes.
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	public function available(string $userId): array {
		$scopes = [self::MINE];
		if ($this->managedGroups(userId: $userId) !== []) {
			$scopes[] = self::TEAM;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			$scopes[] = self::ORGANISATION;
		}

		return $scopes;
	}//end available()

	/**
	 * The users whose work a scope lists, or null for everyone.
	 *
	 * @param string $userId The Nextcloud UID asking.
	 * @param string $scope  One of the scopes the user may choose.
	 * @param string $member With `team`: narrow to this member of the team; '' for the whole team.
	 *
	 * @return array<int, string>|null The UIDs, or null when the scope is the whole organisation.
	 *
	 * @throws InvalidArgumentException For a scope the user may not choose, or a member outside their team.
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	public function assignees(string $userId, string $scope, string $member=''): ?array {
		if (in_array($scope, $this->available(userId: $userId), true) === false) {
			throw new InvalidArgumentException(message: 'Unknown scope');
		}

		if ($scope === self::MINE) {
			return [$userId];
		}

		if ($scope === self::ORGANISATION) {
			return null;
		}

		$team = $this->teamMembers(userId: $userId);
		if ($member === '') {
			return $team;
		}

		if (in_array($member, $team, true) === false) {
			throw new InvalidArgumentException(message: 'Not a member of the team');
		}

		return [$member];
	}//end assignees()

	/**
	 * One `assignee` filter per user a scope lists; one empty filter for everyone.
	 *
	 * @param string $userId The Nextcloud UID asking.
	 * @param string $scope  One of the scopes the user may choose.
	 * @param string $member With `team`: narrow to this member of the team; '' for the whole team.
	 *
	 * @return array<int, array<string, string>> The filters.
	 *
	 * @throws InvalidArgumentException For a scope the user may not choose, or a member outside their team.
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	public function assigneeFilters(string $userId, string $scope, string $member=''): array {
		$assignees = $this->assignees(userId: $userId, scope: $scope, member: $member);
		if ($assignees === null) {
			return [[]];
		}

		return array_map(static fn (string $uid): array => ['assignee' => $uid], $assignees);
	}//end assigneeFilters()

	/**
	 * Every member of the team manager groups the user belongs to, sorted.
	 *
	 * @param string $userId The Nextcloud UID.
	 *
	 * @return array<int, string> The UIDs; empty when the user manages no team.
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	public function teamMembers(string $userId): array {
		return array_map(static fn (int|string $uid): string => (string)$uid, array_keys($this->team(userId: $userId)));
	}//end teamMembers()

	/**
	 * The members of the user's team with their display names, sorted by UID.
	 *
	 * @param string $userId The Nextcloud UID.
	 *
	 * @return array<string, string> Display name per UID; empty when the user manages no team.
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	public function team(string $userId): array {
		$members = [];
		foreach ($this->managedGroups(userId: $userId) as $group) {
			foreach ($group->getUsers() as $user) {
				$members[$user->getUID()] = $user->getDisplayName();
			}
		}

		ksort($members, SORT_STRING);

		return $members;
	}//end team()

	/**
	 * The configured team manager groups that hold the user.
	 *
	 * @param string $userId The Nextcloud UID.
	 *
	 * @return array<int, IGroup> The groups.
	 */
	private function managedGroups(string $userId): array {
		$setting = $this->appConfig->getValueString(AppIdentity::APP_ID, self::MANAGER_GROUPS_KEY, '');
		$groupIds = preg_split(pattern: '/[\s,]+/', subject: $setting, limit: -1, flags: PREG_SPLIT_NO_EMPTY);
		if ($groupIds === false) {
			$groupIds = [];
		}

		$groups = [];
		foreach (array_unique($groupIds) as $groupId) {
			if ($this->groupManager->isInGroup($userId, $groupId) === false) {
				continue;
			}

			$group = $this->groupManager->get($groupId);
			if ($group !== null) {
				$groups[] = $group;
			}
		}

		return $groups;
	}//end managedGroups()
}//end class
