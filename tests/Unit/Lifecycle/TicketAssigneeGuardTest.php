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

use OCA\Pipelinq\Lifecycle\TicketAssigneeGuard;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The ticket `start` and `reject` transitions admit the ticket's assignee,
 * a sales member and an admin, and refuse everyone else.
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
	 * The assignee picks up and rejects their own ticket without any group.
	 *
	 * @return void
	 */
	public function testTheAssigneeMayStartAndReject(): void {
		$guard = $this->guard(groups: ['carol' => ['crm']]);

		$this->assertTrue($guard->check(['assignee' => 'carol'], 'start', 'carol')->isAllowed());
		$this->assertTrue($guard->check(['assignee' => 'carol'], 'reject', 'carol')->isAllowed());
	}//end testTheAssigneeMayStartAndReject()

	/**
	 * Surrounding whitespace on the stored assignee does not lock them out.
	 *
	 * @return void
	 */
	public function testTheAssigneeIsComparedTrimmed(): void {
		$this->assertTrue($this->guard()->check(['assignee' => ' carol '], 'start', 'carol')->isAllowed());
	}//end testTheAssigneeIsComparedTrimmed()

	/**
	 * A sales member and an admin may act on a ticket assigned to someone else.
	 *
	 * @return void
	 */
	public function testSalesAndAdminMayActOnAnyTicket(): void {
		$guard = $this->guard(admins: ['root'], groups: ['sam' => ['sales']]);

		$this->assertTrue($guard->check(['assignee' => 'carol'], 'start', 'sam')->isAllowed());
		$this->assertTrue($guard->check(['assignee' => 'carol'], 'reject', 'root')->isAllowed());
		$this->assertTrue($guard->check([], 'start', 'sam')->isAllowed());
	}//end testSalesAndAdminMayActOnAnyTicket()

	/**
	 * Another user, even one in a non-staff group, is refused with the message.
	 *
	 * @return void
	 */
	public function testSomeoneElseIsRefused(): void {
		$result = $this->guard(groups: ['mallory' => ['crm']])->check(['assignee' => 'carol'], 'start', 'mallory');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::DENIAL, $result->getMessage());
	}//end testSomeoneElseIsRefused()

	/**
	 * Fails closed: no assignee, a non-string assignee or an empty caller
	 * admits nobody outside the staff.
	 *
	 * @return void
	 */
	public function testItFailsClosed(): void {
		$guard = $this->guard();

		$this->assertFalse($guard->check([], 'start', 'carol')->isAllowed());
		$this->assertFalse($guard->check(['assignee' => ''], 'reject', 'carol')->isAllowed());
		$this->assertFalse($guard->check(['assignee' => ['carol']], 'start', 'carol')->isAllowed());
		$this->assertFalse($guard->check(['assignee' => ''], 'start', '')->isAllowed());
		$this->assertFalse($guard->check(['assignee' => ' '], 'start', ' ')->isAllowed());
	}//end testItFailsClosed()
}//end class
