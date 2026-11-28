<?php

/**
 * Unit tests for the Pipelinq WorkScope.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Worklist
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

namespace OCA\Pipelinq\Tests\Unit\Service\Worklist;

use InvalidArgumentException;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\Worklist\WorkScope;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Who has which My work scope, and whose work each one lists.
 */
class WorkScopeTest extends TestCase {

	/**
	 * A resolver over the given setting and groups.
	 *
	 * @param string                            $setting The `my_work_manager_groups` value.
	 * @param array<string, array<int, string>> $groups  Members per group id.
	 * @param array<int, string>                $admins  The Nextcloud administrators.
	 *
	 * @return WorkScope
	 */
	private function scope(string $setting, array $groups, array $admins = []): WorkScope {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap(
			[[AppIdentity::APP_ID, WorkScope::MANAGER_GROUPS_KEY, '', $setting]]
		);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $admins, true));
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($uid, $groups[$gid] ?? [], true)
		);
		$groupManager->method('get')->willReturnCallback(
			function (string $gid) use ($groups): ?IGroup {
				if (isset($groups[$gid]) === false) {
					return null;
				}

				$group = $this->createMock(originalClassName: IGroup::class);
				$group->method('getUsers')->willReturn(
					array_map(
						function (string $uid): IUser {
							$user = $this->createMock(originalClassName: IUser::class);
							$user->method('getUID')->willReturn($uid);
							$user->method('getDisplayName')->willReturn(ucfirst($uid));
							return $user;
						},
						$groups[$gid]
					)
				);
				return $group;
			}
		);

		return new WorkScope(appConfig: $appConfig, groupManager: $groupManager);
	}

	/**
	 * An agent in no manager group has only their own work.
	 *
	 * @return void
	 */
	public function testAnAgentHasOnlyTheirOwnWork(): void {
		$scope = $this->scope(setting: 'leads', groups: ['leads' => ['manager'], 'staff' => ['alice']]);

		$this->assertSame(expected: [WorkScope::MINE], actual: $scope->available(userId: 'alice'));
		$this->assertSame(expected: ['alice'], actual: $scope->assignees(userId: 'alice', scope: WorkScope::MINE));
		$this->assertSame(expected: [], actual: $scope->teamMembers(userId: 'alice'));
	}

	/**
	 * With no manager groups configured nobody has a team, not even a group's members.
	 *
	 * @return void
	 */
	public function testNoSettingMeansNoTeam(): void {
		$scope = $this->scope(setting: '', groups: ['leads' => ['manager', 'bob']]);

		$this->assertSame(expected: [WorkScope::MINE], actual: $scope->available(userId: 'manager'));
	}

	/**
	 * A manager's team is every member of each manager group they are in,
	 * sorted and without doubles; a group they are not in does not count.
	 *
	 * @return void
	 */
	public function testAManagersTeamIsTheMembersOfTheirManagerGroups(): void {
		$scope = $this->scope(
			setting: " leads,\nsupport  other",
			groups: [
				'leads' => ['manager', 'carol', 'bob'],
				'support' => ['manager', 'bob', 'dave'],
				'other' => ['erin'],
			]
		);

		$this->assertSame(expected: [WorkScope::MINE, WorkScope::TEAM], actual: $scope->available(userId: 'manager'));
		$this->assertSame(expected: ['bob', 'carol', 'dave', 'manager'], actual: $scope->assignees(userId: 'manager', scope: WorkScope::TEAM));
		$this->assertSame(expected: ['dave'], actual: $scope->assignees(userId: 'manager', scope: WorkScope::TEAM, member: 'dave'));
	}

	/**
	 * The team carries each member's display name, sorted by UID.
	 *
	 * @return void
	 */
	public function testTheTeamCarriesDisplayNames(): void {
		$scope = $this->scope(setting: 'leads', groups: ['leads' => ['manager', 'carol', 'bob']]);

		$this->assertSame(
			expected: ['bob' => 'Bob', 'carol' => 'Carol', 'manager' => 'Manager'],
			actual: $scope->team(userId: 'manager')
		);
		$this->assertSame(expected: [], actual: $scope->team(userId: 'erin'));
	}

	/**
	 * A member outside the manager's team is refused.
	 *
	 * @return void
	 */
	public function testAMemberOutsideTheTeamIsRefused(): void {
		$scope = $this->scope(setting: 'leads,other', groups: ['leads' => ['manager', 'bob'], 'other' => ['erin']]);

		$this->expectException(exception: InvalidArgumentException::class);
		$scope->assignees(userId: 'manager', scope: WorkScope::TEAM, member: 'erin');
	}

	/**
	 * A configured group that does not exist is skipped.
	 *
	 * @return void
	 */
	public function testAMissingGroupIsSkipped(): void {
		$scope = $this->scope(setting: 'gone', groups: []);

		$this->assertSame(expected: [WorkScope::MINE], actual: $scope->available(userId: 'manager'));
	}

	/**
	 * An administrator gets the organisation scope, which lists everyone.
	 *
	 * @return void
	 */
	public function testAnAdministratorSeesTheOrganisation(): void {
		$scope = $this->scope(setting: '', groups: [], admins: ['admin']);

		$this->assertSame(expected: [WorkScope::MINE, WorkScope::ORGANISATION], actual: $scope->available(userId: 'admin'));
		$this->assertNull(actual: $scope->assignees(userId: 'admin', scope: WorkScope::ORGANISATION));
	}

	/**
	 * A scope the user does not have, or an unknown one, is refused.
	 *
	 * @return void
	 */
	public function testAScopeTheUserDoesNotHaveIsRefused(): void {
		$scope = $this->scope(setting: '', groups: []);

		foreach ([WorkScope::TEAM, WorkScope::ORGANISATION, 'everyone'] as $asked) {
			try {
				$scope->assignees(userId: 'alice', scope: $asked);
				$this->fail(message: 'Expected a refusal for ' . $asked);
			} catch (InvalidArgumentException $e) {
				$this->assertSame(expected: 'Unknown scope', actual: $e->getMessage());
			}
		}
	}
}
