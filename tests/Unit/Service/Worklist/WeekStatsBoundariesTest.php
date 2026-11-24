<?php

/**
 * Boundary tests for the Pipelinq WeekStats.
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
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-kpi-summary-tiles-on-my-work-req-mw-090
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Worklist;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Worklist\WeekStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The edges of the week window, the deadline and the answer time, over a fixed
 * now of Wednesday 11 November 2026 15:00 UTC.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-kpi-summary-tiles-on-my-work-req-mw-090
 */
#[CoversClass(WeekStats::class)]
class WeekStatsBoundariesTest extends TestCase {

	private const NOW = '2026-11-11T15:00:00+00:00';

	/**
	 * The figures as of NOW.
	 *
	 * @param array<int, array<string, mixed>> $tickets The tickets.
	 *
	 * @return array<string, mixed>
	 */
	private function stats(array $tickets): array {
		return (new WeekStats())->of(tickets: $tickets, now: new DateTimeImmutable(self::NOW));
	}//end stats()

	/**
	 * A ticket resolved exactly at Monday 00:00 or exactly now counts; one second outside does not.
	 *
	 * @return void
	 */
	public function testTheWindowIncludesBothEnds(): void {
		$stats = $this->stats(
			[
				['status' => 'resolved', 'resolvedAt' => '2026-11-09T00:00:00+00:00'],
				['status' => 'resolved', 'resolvedAt' => self::NOW],
				['status' => 'resolved', 'resolvedAt' => '2026-11-11T15:00:01+00:00'],
				['status' => 'resolved', 'resolvedAt' => '2026-11-08T23:59:59+00:00'],
			]
		);

		$this->assertSame(2, $stats['answered']);
		$this->assertSame(1, $stats['answeredToday']);
	}//end testTheWindowIncludesBothEnds()

	/**
	 * Today starts at midnight: a ticket resolved at 00:00 today counts as today, one a second earlier does not.
	 *
	 * @return void
	 */
	public function testTodayStartsAtMidnight(): void {
		$stats = $this->stats(
			[
				['status' => 'completed', 'resolvedAt' => '2026-11-11T00:00:00+00:00'],
				['status' => 'completed', 'resolvedAt' => '2026-11-10T23:59:59+00:00'],
			]
		);

		$this->assertSame(2, $stats['answered']);
		$this->assertSame(1, $stats['answeredToday']);
	}//end testTodayStartsAtMidnight()

	/**
	 * Resolved exactly at the deadline is in time; the share is rounded to a whole percentage.
	 *
	 * @return void
	 */
	public function testTheDeadlineIsInclusiveAndTheShareRounds(): void {
		$stats = $this->stats(
			[
				['status' => 'resolved', 'resolvedAt' => '2026-11-10T12:00:00+00:00', 'slaDeadline' => '2026-11-10T12:00:00+00:00'],
				['status' => 'resolved', 'resolvedAt' => '2026-11-10T12:00:00+00:00', 'slaDeadline' => '2026-11-11T00:00:00+00:00'],
				['status' => 'resolved', 'resolvedAt' => '2026-11-10T12:00:01+00:00', 'slaDeadline' => '2026-11-10T12:00:00+00:00'],
				['status' => 'resolved', 'resolvedAt' => '2026-11-10T12:00:00+00:00', 'slaDeadline' => '   '],
			]
		);

		$this->assertSame(4, $stats['answered']);
		$this->assertSame(67, $stats['withinDeadline']);
	}//end testTheDeadlineIsInclusiveAndTheShareRounds()

	/**
	 * A converted ticket counts as converted only: not answered, not against a deadline, not in the average.
	 *
	 * @return void
	 */
	public function testAConvertedTicketCountsApart(): void {
		$stats = $this->stats(
			[
				[
					'status'      => 'converted',
					'occurredAt'  => '2026-11-09T00:00:00+00:00',
					'resolvedAt'  => '2026-11-10T00:00:00+00:00',
					'slaDeadline' => '2026-11-12T00:00:00+00:00',
				],
				['status' => 'converted', 'resolvedAt' => '2026-11-11T10:00:00+00:00'],
			]
		);

		$this->assertSame(
			[
				'weekStart'         => '2026-11-09',
				'answered'          => 0,
				'answeredToday'     => 0,
				'withinDeadline'    => null,
				'averageAnswerDays' => null,
				'converted'         => 2,
			],
			$stats
		);
	}//end testAConvertedTicketCountsApart()

	/**
	 * A ticket that occurred after it was resolved is answered but left out of the average;
	 * an occurrence equal to the resolve time adds a zero-length answer.
	 *
	 * @return void
	 */
	public function testAnAnswerTimeRunningBackwardsIsIgnored(): void {
		$stats = $this->stats(
			[
				['status' => 'resolved', 'occurredAt' => '2026-11-11T00:00:00+00:00', 'resolvedAt' => '2026-11-10T00:00:00+00:00'],
				['status' => 'resolved', 'occurredAt' => '2026-11-09T00:00:00+00:00', 'resolvedAt' => '2026-11-11T00:00:00+00:00'],
				['status' => 'resolved', 'occurredAt' => '2026-11-10T06:00:00+00:00', 'resolvedAt' => '2026-11-10T06:00:00+00:00'],
			]
		);

		$this->assertSame(3, $stats['answered']);
		$this->assertSame(1.0, $stats['averageAnswerDays']);
	}//end testAnAnswerTimeRunningBackwardsIsIgnored()

	/**
	 * Non-string dates read as absent rather than failing.
	 *
	 * @return void
	 */
	public function testNonStringDatesReadAsAbsent(): void {
		$stats = $this->stats(
			[
				['status' => 'resolved', 'resolvedAt' => 1762790400],
				['status' => 'resolved', 'resolvedAt' => '2026-11-10T00:00:00+00:00', 'occurredAt' => ['2026-11-09'], 'slaDeadline' => false],
			]
		);

		$this->assertSame(1, $stats['answered']);
		$this->assertNull($stats['withinDeadline']);
		$this->assertNull($stats['averageAnswerDays']);
	}//end testNonStringDatesReadAsAbsent()

	/**
	 * Dates in another offset are compared by instant, not by wall clock.
	 *
	 * @return void
	 */
	public function testOffsetsCompareByInstant(): void {
		$stats = $this->stats(
			[
				['status' => 'resolved', 'resolvedAt' => '2026-11-09T00:30:00+01:00'],
				['status' => 'resolved', 'resolvedAt' => '2026-11-11T16:30:00+02:00'],
			]
		);

		$this->assertSame(1, $stats['answered']);
		$this->assertSame(1, $stats['answeredToday']);
	}//end testOffsetsCompareByInstant()
}//end class
