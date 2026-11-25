<?php

/**
 * Unit tests for TicketAssigneeGuard.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardContext;
use OCA\Pipelinq\Lifecycle\TicketAssigneeGuard;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The ticket `start` and `reject` transitions admit the stored ticket's
 * assignee, a sales member and an admin, and refuse everyone else.
 *
 * @covers \OCA\Pipelinq\Lifecycle\TicketAssigneeGuard
 */
class TicketAssigneeGuardTest extends TestCase {

	private const DENIAL = 'Alleen de behandelaar van dit ticket of een lid van de verkoopgroep mag deze stap uitvoeren.';

	/**
	 * A guard over a group manager with these admins and group memberships.
	 *
	 * @param array<int, string>                $admins Admin uids.
	 * @param array<string, array<int, string>> $groups Groups per uid.
	 *
	 * @return TicketAssigneeGuard
	 */
	private function guard(array $admins = [], array $groups = []): TicketAssigneeGuard {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, $admins, true)
		);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, ($groups[$uid] ?? []), true)
		);

		return new TicketAssigneeGuard(groupManager: $groupManager);
	}//end guard()

	/**
	 * Run the guard as OpenRegister does: with the stored ticket and the incoming data.
	 *
	 * @param TicketAssigneeGuard  $guard    The guard.
	 * @param array<string, mixed> $stored   The stored ticket data.
	 * @param string               $action   The transition.
	 * @param string               $userId   The caller.
	 * @param array<string, mixed> $incoming The incoming data.
	 *
	 * @return GuardResult
	 */
	private function judge(TicketAssigneeGuard $guard, array $stored, string $action, string $userId, array $incoming = []): GuardResult {
		$entity = new ObjectEntity();
		$entity->setObject($stored);

		return $guard->checkTransition(
			context: new LifecycleGuardContext(object: $incoming, stored: $entity, action: $action, userId: $userId)
		);
	}//end judge()

	/**
	 * The stored assignee picks up and rejects their own ticket without any group.
	 *
	 * @return void
	 */
	public function testTheAssigneeMayStartAndReject(): void {
		$guard = $this->guard(groups: ['carol' => ['crm']]);

		$this->assertTrue($this->judge($guard, ['assignee' => 'carol'], 'start', 'carol')->isAllowed());
		$this->assertTrue($this->judge($guard, ['assignee' => 'carol'], 'reject', 'carol')->isAllowed());
	}//end testTheAssigneeMayStartAndReject()

	/**
	 * Surrounding whitespace on the stored assignee does not lock them out.
	 *
	 * @return void
	 */
	public function testTheAssigneeIsComparedTrimmed(): void {
		$this->assertTrue($this->judge($this->guard(), ['assignee' => ' carol '], 'start', 'carol')->isAllowed());
	}//end testTheAssigneeIsComparedTrimmed()

	/**
	 * Naming oneself assignee in the same write does not admit the caller:
	 * only the stored assignee counts.
	 *
	 * @return void
	 */
	public function testAnIncomingAssigneeIsNotTrusted(): void {
		$guard  = $this->guard(groups: ['mallory' => ['crm']]);
		$result = $this->judge($guard, ['assignee' => 'carol'], 'start', 'mallory', ['assignee' => 'mallory']);

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::DENIAL, $result->getMessage());
		$this->assertFalse($this->judge($guard, [], 'reject', 'mallory', ['assignee' => 'mallory'])->isAllowed());
	}//end testAnIncomingAssigneeIsNotTrusted()

	/**
	 * A sales member and an admin may act on a ticket assigned to someone else.
	 *
	 * @return void
	 */
	public function testSalesAndAdminMayActOnAnyTicket(): void {
		$guard = $this->guard(admins: ['root'], groups: ['sam' => ['sales']]);

		$this->assertTrue($this->judge($guard, ['assignee' => 'carol'], 'start', 'sam')->isAllowed());
		$this->assertTrue($this->judge($guard, ['assignee' => 'carol'], 'reject', 'root')->isAllowed());
		$this->assertTrue($this->judge($guard, [], 'start', 'sam')->isAllowed());
	}//end testSalesAndAdminMayActOnAnyTicket()

	/**
	 * Another user, even one in a non-staff group, is refused with the message.
	 *
	 * @return void
	 */
	public function testSomeoneElseIsRefused(): void {
		$result = $this->judge($this->guard(groups: ['mallory' => ['crm']]), ['assignee' => 'carol'], 'start', 'mallory');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::DENIAL, $result->getMessage());
	}//end testSomeoneElseIsRefused()

	/**
	 * Fails closed: no stored assignee, a non-string assignee or an empty
	 * caller admits nobody outside the staff.
	 *
	 * @return void
	 */
	public function testItFailsClosed(): void {
		$guard = $this->guard();

		$this->assertFalse($this->judge($guard, [], 'start', 'carol')->isAllowed());
		$this->assertFalse($this->judge($guard, ['assignee' => ''], 'reject', 'carol')->isAllowed());
		$this->assertFalse($this->judge($guard, ['assignee' => ['carol']], 'start', 'carol')->isAllowed());
		$this->assertFalse($this->judge($guard, ['assignee' => ''], 'start', '')->isAllowed());
		$this->assertFalse($this->judge($guard, ['assignee' => ' '], 'start', ' ')->isAllowed());
	}//end testItFailsClosed()

	/**
	 * The context-free check() has no stored ticket, so it admits staff only
	 * and ignores the payload's assignee.
	 *
	 * @return void
	 */
	public function testTheContextFreeCheckAdmitsStaffOnly(): void {
		$guard = $this->guard(admins: ['root'], groups: ['sam' => ['sales']]);

		$this->assertFalse($guard->check(['assignee' => 'carol'], 'start', 'carol')->isAllowed());
		$this->assertTrue($guard->check(['assignee' => 'carol'], 'start', 'sam')->isAllowed());
		$this->assertTrue($guard->check([], 'reject', 'root')->isAllowed());
	}//end testTheContextFreeCheckAdmitsStaffOnly()
}//end class
