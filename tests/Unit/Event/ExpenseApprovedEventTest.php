<?php

/**
 * Unit tests for the ExpenseApprovedEvent domain event.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Event;

use OCA\Pipelinq\Event\ExpenseApprovedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Event\ExpenseApprovedEvent
 */
class ExpenseApprovedEventTest extends TestCase {

	/**
	 * Each named argument lands on its own getter; approver and timestamp are not swapped.
	 *
	 * @return void
	 */
	public function testCarriesTheApprovalAsDispatched(): void {
		$event = new ExpenseApprovedEvent(
			expenseUuid: 'exp-1',
			expense: ['amount' => 42.5, 'status' => 'approved'],
			approvedBy: 'alice',
			approvedAt: '2026-11-16T09:30:00+00:00',
		);

		$this->assertSame(
			[
				'exp-1',
				['amount' => 42.5, 'status' => 'approved'],
				'alice',
				'2026-11-16T09:30:00+00:00',
			],
			[
				$event->getExpenseUuid(),
				$event->getExpense(),
				$event->getApprovedBy(),
				$event->getApprovedAt(),
			]
		);
	}//end testCarriesTheApprovalAsDispatched()

	/**
	 * The listener marks its own copy pending after dispatch; the event keeps the approved snapshot.
	 *
	 * @return void
	 */
	public function testExpenseIsASnapshotOfTheDispatchTime(): void {
		$data  = ['status' => 'approved'];
		$event = new ExpenseApprovedEvent(
			expenseUuid: 'exp-2',
			expense: $data,
			approvedBy: 'bob',
			approvedAt: '2026-11-16T10:00:00+00:00',
		);

		$data['apSyncStatus'] = 'pending';

		$this->assertSame(['status' => 'approved'], $event->getExpense());
		$this->assertArrayNotHasKey('apSyncStatus', $event->getExpense());
	}//end testExpenseIsASnapshotOfTheDispatchTime()

	/**
	 * The event is a typed Nextcloud event whose propagation a listener can stop.
	 *
	 * @return void
	 */
	public function testIsAStoppableNextcloudEvent(): void {
		$event = new ExpenseApprovedEvent(
			expenseUuid: 'exp-3',
			expense: [],
			approvedBy: '',
			approvedAt: '',
		);

		$this->assertInstanceOf(Event::class, $event);
		$this->assertFalse($event->isPropagationStopped());
		$event->stopPropagation();
		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame([], $event->getExpense());
		$this->assertSame('', $event->getApprovedBy());
	}//end testIsAStoppableNextcloudEvent()
}//end class
