<?php

/**
 * Who may change a scheduled task: its assignee, a member of its assignee
 * group, or an administrator; nobody anonymous. The creator is the session
 * user, or "system" without one.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\ScheduledTask
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\ScheduledTask;

use OCA\Pipelinq\Service\ScheduledTask\ScheduledTaskRights;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\ScheduledTask\ScheduledTaskRights
 */
class ScheduledTaskRightsTest extends TestCase {

	private IUserSession&MockObject $session;

	private IGroupManager&MockObject $groups;

	private ScheduledTaskRights $rights;

	/**
	 * Rights over a session and group manager where `sales` holds `bert` and
	 * `root` is the only administrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->groups  = $this->createMock(IGroupManager::class);
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $uid === 'bert' && $gid === 'sales'
		);
		$this->groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$this->rights = new ScheduledTaskRights($this->session, $this->groups);

	}//end setUp()

	/**
	 * Assert the mutation is refused.
	 *
	 * @param array<string, mixed> $task   The task.
	 * @param string               $userId The user.
	 *
	 * @return void
	 */
	private function assertRefused(array $task, string $userId): void {
		try {
			$this->rights->authorizeTaskMutation($task, $userId);
			$this->fail('Expected the mutation to be refused for "' . $userId . '".');
		} catch (OCSForbiddenException $e) {
			$this->assertSame('Not authorized', $e->getMessage());
		}

	}//end assertRefused()

	public function testTheCreatorIsTheSessionUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$this->session->method('getUser')->willReturn($user);

		$this->assertSame('anna', $this->rights->createdBy());

	}//end testTheCreatorIsTheSessionUser()

	public function testWithoutASessionTheCreatorIsSystem(): void {
		$this->session->method('getUser')->willReturn(null);

		$this->assertSame('system', $this->rights->createdBy());

	}//end testWithoutASessionTheCreatorIsSystem()

	public function testTheAssigneeMayChangeTheTask(): void {
		$this->groups->expects($this->never())->method('isAdmin');

		$this->rights->authorizeTaskMutation(['assigneeUserId' => 'anna'], 'anna');
		$this->addToAssertionCount(1);

	}//end testTheAssigneeMayChangeTheTask()

	public function testAMemberOfTheAssigneeGroupMayChangeTheTask(): void {
		$this->groups->expects($this->never())->method('isAdmin');

		$this->rights->authorizeTaskMutation(['assigneeUserId' => 'anna', 'assigneeGroupId' => 'sales'], 'bert');
		$this->addToAssertionCount(1);

	}//end testAMemberOfTheAssigneeGroupMayChangeTheTask()

	public function testAnAdministratorMayChangeAnyTask(): void {
		$this->rights->authorizeTaskMutation(['assigneeUserId' => 'anna', 'assigneeGroupId' => 'sales'], 'root');
		$this->rights->authorizeTaskMutation([], 'root');
		$this->addToAssertionCount(2);

	}//end testAnAdministratorMayChangeAnyTask()

	public function testSomeoneElseIsRefused(): void {
		$this->assertRefused(['assigneeUserId' => 'anna', 'assigneeGroupId' => 'sales'], 'carl');

	}//end testSomeoneElseIsRefused()

	public function testAMemberOfAnotherGroupIsRefused(): void {
		$this->assertRefused(['assigneeUserId' => 'anna', 'assigneeGroupId' => 'support'], 'bert');

	}//end testAMemberOfAnotherGroupIsRefused()

	public function testAnUnassignedTaskIsRefusedToANonAdministrator(): void {
		$this->groups->expects($this->never())->method('isInGroup');

		$this->assertRefused(['assigneeUserId' => '', 'assigneeGroupId' => ''], 'carl');

	}//end testAnUnassignedTaskIsRefusedToANonAdministrator()

	public function testAnAnonymousCallerIsRefusedBeforeAnyLookup(): void {
		$this->groups->expects($this->never())->method('isInGroup');
		$this->groups->expects($this->never())->method('isAdmin');

		// An empty assignee must not match an empty caller.
		$this->assertRefused(['assigneeUserId' => ''], '');

	}//end testAnAnonymousCallerIsRefusedBeforeAnyLookup()
}//end class
