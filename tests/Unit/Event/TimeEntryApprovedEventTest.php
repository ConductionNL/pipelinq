<?php

/**
 * Unit tests for the TimeEntryApprovedEvent domain event.
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

use OCA\Pipelinq\Event\TimeEntryApprovedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Event\TimeEntryApprovedEvent
 */
class TimeEntryApprovedEventTest extends TestCase {

	/**
	 * Each named argument lands on its own getter; approver and timestamp are not swapped.
	 *
	 * @return void
	 */
	public function testCarriesTheApprovalAsDispatched(): void {
		$event = new TimeEntryApprovedEvent(
			timeEntryUuid: 'te-1',
			timeEntry: ['hours' => 7.5, 'status' => 'approved'],
			approvedBy: 'alice',
			approvedAt: '2026-11-16T09:30:00+00:00',
		);

		$this->assertSame(
			[
				'te-1',
				['hours' => 7.5, 'status' => 'approved'],
				'alice',
				'2026-11-16T09:30:00+00:00',
			],
			[
				$event->getTimeEntryUuid(),
				$event->getTimeEntry(),
				$event->getApprovedBy(),
				$event->getApprovedAt(),
			]
		);
	}//end testCarriesTheApprovalAsDispatched()

	/**
	 * The listener changes its own copy after dispatch; the event keeps the approved snapshot.
	 *
	 * @return void
	 */
	public function testTimeEntryIsASnapshotOfTheDispatchTime(): void {
		$data  = ['status' => 'approved'];
		$event = new TimeEntryApprovedEvent(
			timeEntryUuid: 'te-2',
			timeEntry: $data,
			approvedBy: 'bob',
			approvedAt: '2026-11-16T10:00:00+00:00',
		);

		$data['wipSyncStatus'] = 'pending';

		$this->assertSame(['status' => 'approved'], $event->getTimeEntry());
		$this->assertArrayNotHasKey('wipSyncStatus', $event->getTimeEntry());
	}//end testTimeEntryIsASnapshotOfTheDispatchTime()

	/**
	 * The event is a typed Nextcloud event whose propagation a listener can stop.
	 *
	 * @return void
	 */
	public function testIsAStoppableNextcloudEvent(): void {
		$event = new TimeEntryApprovedEvent(
			timeEntryUuid: 'te-3',
			timeEntry: [],
			approvedBy: '',
			approvedAt: '',
		);

		$this->assertInstanceOf(Event::class, $event);
		$this->assertFalse($event->isPropagationStopped());
		$event->stopPropagation();
		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame([], $event->getTimeEntry());
		$this->assertSame('', $event->getApprovedBy());
	}//end testIsAStoppableNextcloudEvent()
}//end class
