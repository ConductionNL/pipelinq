<?php

/**
 * Edge-case tests for WorkBuckets: malformed records and dates, the waiting/newest sorts and the priority tie-break.
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the worklist does with rows the main tests do not reach.
 *
 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
 */
#[CoversClass(WorkBuckets::class)]
class WorkBucketsEdgeCasesTest extends TestCase {

	private const NOW = '2026-10-07T10:00:00+00:00';

	/**
	 * Decorate a row as of NOW.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function decorate(array $row): array {
		return (new WorkBuckets())->decorate(row: $row, now: new DateTimeImmutable(self::NOW));
	}//end decorate()

	/**
	 * A record that is not an array, or a missing one, reads as an empty ticket waiting on me.
	 *
	 * @return void
	 */
	public function testAMalformedRecordWaitsOnMeWithoutATerm(): void {
		foreach (['not a record', null] as $record) {
			$row = $this->decorate(['kind' => 'ticket', 'record' => $record, 'id' => 'x']);

			$this->assertNull($row['dueAt']);
			$this->assertSame('waiting-on-me', $row['bucket']);
			$this->assertSame('none', $row['dueGroup']);
			$this->assertSame('x', $row['id']);
		}
	}//end testAMalformedRecordWaitsOnMeWithoutATerm()

	/**
	 * An unreadable or blank due date keeps its text but gives no bucket or group by date.
	 *
	 * @return void
	 */
	public function testAnUnreadableDueDateIsKeptButNotDated(): void {
		$row = $this->decorate(['kind' => 'lead', 'record' => ['expectedCloseDate' => 'next sprint']]);

		$this->assertSame('next sprint', $row['dueAt']);
		$this->assertSame('waiting-on-me', $row['bucket']);
		$this->assertSame('none', $row['dueGroup']);
		$this->assertNull($this->decorate(['kind' => 'task', 'record' => ['deadline' => '   ']])['dueAt']);
		$this->assertNull($this->decorate(['kind' => 'task', 'record' => ['deadline' => 20261007]])['dueAt']);
	}//end testAnUnreadableDueDateIsKeptButNotDated()

	/**
	 * A ticket waiting on the customer stays there even when its deadline has passed.
	 *
	 * @return void
	 */
	public function testWaitingOnTheCustomerWinsOverALateDeadline(): void {
		$row = $this->decorate(['kind' => 'ticket', 'record' => ['status' => 'awaiting_customer', 'slaDeadline' => '2026-10-01T00:00:00+00:00']]);

		$this->assertSame('waiting-on-customer', $row['bucket']);
		$this->assertSame('late', $row['dueGroup']);
	}//end testWaitingOnTheCustomerWinsOverALateDeadline()

	/**
	 * Rows to sort by when they started.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function startedRows(): array {
		return [
			['id' => 'b', 'record' => ['occurredAt' => '2026-10-02T09:00:00Z', 'createdAt' => '2026-09-01T00:00:00Z']],
			['id' => 'c', 'record' => ['createdAt' => '2026-10-03T09:00:00Z']],
			['id' => 'a', 'record' => ['occurredAt' => '2026-10-01T09:00:00Z']],
			['id' => 'none', 'record' => []],
		];
	}//end startedRows()

	/**
	 * "waiting" sorts longest waiting first, by occurredAt before createdAt; "newest" the other way.
	 *
	 * @return void
	 */
	public function testWaitingAndNewestSortByWhenTheItemStarted(): void {
		$buckets = new WorkBuckets();

		$rows = $this->startedRows();
		usort($rows, $buckets->comparator(sort: 'waiting'));
		$this->assertSame(['none', 'a', 'b', 'c'], array_column($rows, 'id'));

		$rows = $this->startedRows();
		usort($rows, $buckets->comparator(sort: 'newest'));
		$this->assertSame(['c', 'b', 'a', 'none'], array_column($rows, 'id'));
	}//end testWaitingAndNewestSortByWhenTheItemStarted()

	/**
	 * Equal priorities fall back to the due date, undated last; an unknown priority ranks as normal.
	 *
	 * @return void
	 */
	public function testPriorityTiesSortByDueDate(): void {
		$rows = [
			['id' => 'normal-undated', 'priority' => 'normal', 'dueAt' => null],
			['id' => 'odd-early', 'priority' => 'whenever', 'dueAt' => '2026-10-01'],
			['id' => 'high-late', 'priority' => 'high', 'dueAt' => '2026-12-01'],
			['id' => 'high-early', 'priority' => 'high', 'dueAt' => '2026-10-08'],
			['id' => 'urgent', 'priority' => 'urgent', 'dueAt' => null],
		];

		usort($rows, (new WorkBuckets())->comparator(sort: 'priority'));

		$this->assertSame(['urgent', 'high-early', 'high-late', 'odd-early', 'normal-undated'], array_column($rows, 'id'));
	}//end testPriorityTiesSortByDueDate()

	/**
	 * Any other sort is by due date, undated last.
	 *
	 * @return void
	 */
	public function testAnUnknownSortIsByDueDate(): void {
		$rows = [['id' => 'u', 'dueAt' => null], ['id' => 'l', 'dueAt' => '2026-11-01'], ['id' => 'e', 'dueAt' => '2026-10-01']];

		usort($rows, (new WorkBuckets())->comparator(sort: 'alphabetical'));

		$this->assertSame(['e', 'l', 'u'], array_column($rows, 'id'));
	}//end testAnUnknownSortIsByDueDate()

	/**
	 * Counts per lens: every non-lead, non-task kind is a ticket; an unknown lens keeps everything.
	 *
	 * @return void
	 */
	public function testCountsByLensAndAnUnknownLens(): void {
		$buckets = new WorkBuckets();
		$items   = [['kind' => 'ticket'], ['kind' => 'contactmoment'], ['kind' => 'lead'], ['kind' => 'task'], ['kind' => 'task']];

		$this->assertSame(['tickets' => 2, 'leads' => 1, 'tasks' => 2], $buckets->countsByLens($items));
		$this->assertSame(['tickets' => 0, 'leads' => 0, 'tasks' => 0], $buckets->countsByLens([]));
		$this->assertTrue($buckets->inLens(kind: 'lead', lens: 'all'));
	}//end testCountsByLensAndAnUnknownLens()
}//end class
