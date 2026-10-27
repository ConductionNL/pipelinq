<?php

/**
 * Tests for WorkBuckets.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Worklist;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Worklist\WorkBuckets;
use PHPUnit\Framework\TestCase;

/**
 * Board PqMijnWerk sorts every item of the user's work, ticket, lead or
 * follow-up, into "Vandaag beantwoorden", "Wacht op mij" or "Wacht op de
 * klant"; board PqMobiel groups the same items by when they are due.
 *
 * @spec openspec/changes/my-work-one-list-of-my-items/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
 */
class WorkBucketsTest extends TestCase {

	private const NOW = '2026-10-07T10:00:00+00:00';

	/**
	 * Decorate a row as of NOW.
	 *
	 * @param string               $kind   The row's kind.
	 * @param array<string, mixed> $record The raw record.
	 *
	 * @return array<string, mixed> The decorated row.
	 */
	private function row(string $kind, array $record): array {
		return (new WorkBuckets())->decorate(row: ['kind' => $kind, 'record' => $record], now: new DateTimeImmutable(self::NOW));
	}//end row()

	/**
	 * A ticket due today or earlier is answered today; one waiting on the customer waits on them.
	 *
	 * @return void
	 */
	public function testTicketsSortByTheirDeadlineAndStatus(): void {
		self::assertSame('answer-today', $this->row('request', ['status' => 'in_progress', 'slaDeadline' => '2026-10-06T09:00:00+00:00'])['bucket']);
		self::assertSame('waiting-on-me', $this->row('complaint', ['status' => 'new', 'slaDeadline' => '2026-10-09T09:00:00+00:00'])['bucket']);
		self::assertSame('waiting-on-me', $this->row('request', ['status' => 'new'])['bucket']);
		self::assertSame('waiting-on-customer', $this->row('request', ['status' => 'awaiting_customer', 'slaDeadline' => '2026-10-01'])['bucket']);
	}//end testTicketsSortByTheirDeadlineAndStatus()

	/**
	 * A lead and a follow-up are due by their own date fields.
	 *
	 * @return void
	 */
	public function testLeadsAndTasksAreDueByTheirOwnDate(): void {
		$lead = $this->row('lead', ['expectedCloseDate' => '2026-10-07']);
		self::assertSame('answer-today', $lead['bucket']);
		self::assertSame('today', $lead['dueGroup']);
		$task = $this->row('task', ['deadline' => '2026-10-05T12:00:00+00:00']);
		self::assertSame('answer-today', $task['bucket']);
		self::assertSame('late', $task['dueGroup']);
		self::assertSame('2026-10-05T12:00:00+00:00', $task['dueAt']);
	}//end testLeadsAndTasksAreDueByTheirOwnDate()

	/**
	 * The phone groups: late, today, this week, later, and none without a date.
	 *
	 * @return void
	 */
	public function testDueGroups(): void {
		self::assertSame('week', $this->row('lead', ['expectedCloseDate' => '2026-10-10'])['dueGroup']);
		self::assertSame('later', $this->row('lead', ['expectedCloseDate' => '2026-10-20'])['dueGroup']);
		self::assertSame('none', $this->row('lead', [])['dueGroup']);
	}//end testDueGroups()

	/**
	 * A lens keeps the kinds it names.
	 *
	 * @return void
	 */
	public function testLensKeepsItsKinds(): void {
		self::assertTrue((new WorkBuckets())->inLens(kind: 'complaint', lens: 'tickets'));
		self::assertFalse((new WorkBuckets())->inLens(kind: 'lead', lens: 'tickets'));
		self::assertTrue((new WorkBuckets())->inLens(kind: 'lead', lens: 'leads'));
		self::assertTrue((new WorkBuckets())->inLens(kind: 'task', lens: 'tasks'));
		self::assertTrue((new WorkBuckets())->inLens(kind: 'task', lens: 'all'));
		self::assertTrue((new WorkBuckets())->inLens(kind: 'task', lens: ''));
	}//end testLensKeepsItsKinds()

	/**
	 * The page's Sortering: by deadline (undated last) or priority first.
	 *
	 * @return void
	 */
	public function testSortsByDeadlineOrPriority(): void {
		$rows = [
			['id' => 'a', 'priority' => 'normal', 'dueAt' => '2026-10-05', 'record' => []],
			['id' => 'b', 'priority' => 'urgent', 'dueAt' => '2026-10-09', 'record' => []],
			['id' => 'c', 'priority' => 'high', 'dueAt' => null, 'record' => []],
		];
		$byDeadline = $rows;
		usort($byDeadline, (new WorkBuckets())->comparator(sort: 'deadline'));
		self::assertSame(['a', 'b', 'c'], array_column($byDeadline, 'id'));
		$byPriority = $rows;
		usort($byPriority, (new WorkBuckets())->comparator(sort: 'priority'));
		self::assertSame(['b', 'c', 'a'], array_column($byPriority, 'id'));
	}//end testSortsByDeadlineOrPriority()
}//end class
