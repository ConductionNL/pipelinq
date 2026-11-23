<?php

/**
 * Boundary tests for the Pipelinq WorkBuckets.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Worklist;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Worklist\WorkBuckets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The exact edges of the due groups and lists, as of Wednesday 7 October 2026 10:00 UTC.
 *
 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
 */
#[CoversClass(WorkBuckets::class)]
class WorkBucketsBoundariesTest extends TestCase {

	private const NOW = '2026-10-07T10:00:00+00:00';

	/**
	 * Decorate a row as of NOW.
	 *
	 * @param string               $kind   The kind.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array<string, mixed>
	 */
	private function decorate(string $kind, array $record): array {
		return (new WorkBuckets())->decorate(row: ['kind' => $kind, 'record' => $record], now: new DateTimeImmutable(self::NOW));
	}//end decorate()

	/**
	 * The due group at each edge: midnight today is today, midnight tomorrow is the week,
	 * six days later is still the week, seven days later is later.
	 *
	 * @return void
	 */
	public function testDueGroupEdges(): void {
		$cases = [
			'2026-10-06T23:59:59+00:00' => ['late', 'answer-today'],
			'2026-10-07T00:00:00+00:00' => ['today', 'answer-today'],
			'2026-10-07T23:59:59+00:00' => ['today', 'answer-today'],
			'2026-10-08T00:00:00+00:00' => ['week', 'waiting-on-me'],
			'2026-10-13T23:59:59+00:00' => ['week', 'waiting-on-me'],
			'2026-10-14T00:00:00+00:00' => ['later', 'waiting-on-me'],
		];

		foreach ($cases as $due => [$group, $bucket]) {
			$row = $this->decorate(kind: 'ticket', record: ['slaDeadline' => $due]);

			$this->assertSame($due, $row['dueAt'], $due);
			$this->assertSame($group, $row['dueGroup'], $due);
			$this->assertSame($bucket, $row['bucket'], $due);
		}
	}//end testDueGroupEdges()

	/**
	 * Any kind that is not a lead or a task is due by its slaDeadline, and only the
	 * ticket's own due field counts.
	 *
	 * @return void
	 */
	public function testEachKindReadsOnlyItsOwnDueField(): void {
		$record = ['slaDeadline' => '2026-10-01', 'expectedCloseDate' => '2026-10-20', 'deadline' => '2026-10-10'];

		$this->assertSame('2026-10-01', $this->decorate(kind: 'complaint', record: $record)['dueAt']);
		$this->assertSame('2026-10-20', $this->decorate(kind: 'lead', record: $record)['dueAt']);
		$this->assertSame('2026-10-10', $this->decorate(kind: 'task', record: $record)['dueAt']);
		$this->assertNull($this->decorate(kind: 'lead', record: ['slaDeadline' => '2026-10-01'])['dueAt']);
	}//end testEachKindReadsOnlyItsOwnDueField()

	/**
	 * Only a ticket waits on the customer; a lead or task with that status stays in the due-date lists.
	 *
	 * @return void
	 */
	public function testOnlyTicketsWaitOnTheCustomer(): void {
		$ticket = $this->decorate(kind: 'contactmoment', record: ['status' => 'awaiting_customer']);
		$lead   = $this->decorate(kind: 'lead', record: ['status' => 'awaiting_customer', 'expectedCloseDate' => '2026-10-01']);
		$task   = $this->decorate(kind: 'task', record: ['status' => 'awaiting_customer']);

		$this->assertSame('waiting-on-customer', $ticket['bucket']);
		$this->assertSame('answer-today', $lead['bucket']);
		$this->assertSame('late', $lead['dueGroup']);
		$this->assertSame('waiting-on-me', $task['bucket']);
		$this->assertSame('none', $task['dueGroup']);
	}//end testOnlyTicketsWaitOnTheCustomer()

	/**
	 * A blank or non-string due value reads as no due date; the row keeps its other fields.
	 *
	 * @return void
	 */
	public function testBlankOrNonStringDueValuesAreUndated(): void {
		foreach (['   ', '', 20261001, ['2026-10-01'], null] as $value) {
			$row = (new WorkBuckets())->decorate(
				row: ['kind' => 'task', 'record' => ['deadline' => $value], 'id' => 't-1'],
				now: new DateTimeImmutable(self::NOW)
			);

			$this->assertNull($row['dueAt']);
			$this->assertSame('none', $row['dueGroup']);
			$this->assertSame('waiting-on-me', $row['bucket']);
			$this->assertSame('t-1', $row['id']);
		}
	}//end testBlankOrNonStringDueValuesAreUndated()

	/**
	 * A row without a kind is treated as a ticket.
	 *
	 * @return void
	 */
	public function testARowWithoutAKindIsATicket(): void {
		$row = (new WorkBuckets())->decorate(row: ['record' => ['slaDeadline' => '2026-10-07T12:00:00+00:00']], now: new DateTimeImmutable(self::NOW));

		$this->assertSame('2026-10-07T12:00:00+00:00', $row['dueAt']);
		$this->assertSame('answer-today', $row['bucket']);
		$this->assertTrue((new WorkBuckets())->inLens(kind: '', lens: 'tickets'));
		$this->assertFalse((new WorkBuckets())->inLens(kind: 'lead', lens: 'tasks'));
		$this->assertFalse((new WorkBuckets())->inLens(kind: 'task', lens: 'leads'));
	}//end testARowWithoutAKindIsATicket()

	/**
	 * The deadline sort compares dates as strings and puts undated rows last, in a stable pairwise order.
	 *
	 * @return void
	 */
	public function testTheDeadlineComparatorPairwise(): void {
		$compare = (new WorkBuckets())->comparator(sort: 'deadline');

		$this->assertSame(-1, $compare(['dueAt' => '2026-10-01'], ['dueAt' => '2026-10-02']));
		$this->assertSame(1, $compare(['dueAt' => null], ['dueAt' => '2026-10-02']));
		$this->assertSame(-1, $compare(['dueAt' => '2099-01-01'], ['dueAt' => null]));
		$this->assertSame(0, $compare(['dueAt' => null], ['dueAt' => null]));
	}//end testTheDeadlineComparatorPairwise()
}//end class
