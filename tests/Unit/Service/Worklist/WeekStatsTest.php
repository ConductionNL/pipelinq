<?php

/**
 * Unit tests for the Pipelinq WeekStats.
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
use PHPUnit\Framework\TestCase;

/**
 * The "Your week so far" figures over a fixed now, Wednesday 11 November 2026 15:00 UTC.
 *
 * @covers \OCA\Pipelinq\Service\Worklist\WeekStats
 */
class WeekStatsTest extends TestCase {

	/**
	 * The fixed now.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-11T15:00:00+00:00');
	}//end now()

	/**
	 * An answered ticket.
	 *
	 * @param string      $resolvedAt  When it was resolved.
	 * @param string|null $slaDeadline Its deadline.
	 * @param string      $status      Its status.
	 *
	 * @return array<string, mixed>
	 */
	private static function ticket(string $resolvedAt, ?string $slaDeadline=null, string $status='resolved'): array {
		return [
			'status'      => $status,
			'occurredAt'  => '2026-11-09T09:00:00+00:00',
			'resolvedAt'  => $resolvedAt,
			'slaDeadline' => $slaDeadline,
		];
	}//end ticket()

	/**
	 * Fourteen answered this week, four of them today, thirteen within the deadline.
	 *
	 * @return void
	 */
	public function testTheUsersOwnWeek(): void {
		$tickets = [];
		for ($i = 0; $i < 10; $i++) {
			$tickets[] = self::ticket(resolvedAt: '2026-11-10T10:00:00+00:00', slaDeadline: '2026-11-12T00:00:00+00:00');
		}

		for ($i = 0; $i < 3; $i++) {
			$tickets[] = self::ticket(resolvedAt: '2026-11-11T09:00:00+00:00', slaDeadline: '2026-11-11T09:00:00+00:00', status: 'completed');
		}

		$tickets[] = self::ticket(resolvedAt: '2026-11-11T14:00:00+00:00', slaDeadline: '2026-11-10T00:00:00+00:00');

		$stats = (new WeekStats())->of(tickets: $tickets, now: self::now());

		$this->assertSame(14, $stats['answered']);
		$this->assertSame(4, $stats['answeredToday']);
		$this->assertSame(93, $stats['withinDeadline']);
		$this->assertSame('2026-11-09', $stats['weekStart']);
		$this->assertSame(0, $stats['converted']);
	}//end testTheUsersOwnWeek()

	/**
	 * A Monday morning with nothing answered: zero, and no average or share.
	 *
	 * @return void
	 */
	public function testAWeekWithNothingAnswered(): void {
		$stats = (new WeekStats())->of(
			tickets: [['status' => 'in_progress', 'occurredAt' => '2026-11-08T09:00:00+00:00']],
			now: new DateTimeImmutable('2026-11-09T08:00:00+00:00')
		);

		$this->assertSame(
			[
				'weekStart'         => '2026-11-09',
				'answered'          => 0,
				'answeredToday'     => 0,
				'withinDeadline'    => null,
				'averageAnswerDays' => null,
				'converted'         => 0,
			],
			$stats
		);
	}//end testAWeekWithNothingAnswered()

	/**
	 * The average answer time runs from occurredAt to resolvedAt, in days to one decimal.
	 *
	 * @return void
	 */
	public function testTheAverageAnswerTime(): void {
		$stats = (new WeekStats())->of(
			tickets: [
				['status' => 'resolved', 'occurredAt' => '2026-11-09T00:00:00+00:00', 'resolvedAt' => '2026-11-10T00:00:00+00:00'],
				['status' => 'resolved', 'occurredAt' => '2026-11-08T00:00:00+00:00', 'resolvedAt' => '2026-11-10T12:00:00+00:00'],
				['status' => 'resolved', 'resolvedAt' => '2026-11-10T12:00:00+00:00'],
			],
			now: self::now()
		);

		$this->assertSame(3, $stats['answered']);
		$this->assertSame(1.8, $stats['averageAnswerDays']);
		$this->assertNull($stats['withinDeadline']);
	}//end testTheAverageAnswerTime()

	/**
	 * Last week, the future, open tickets and unreadable dates do not count; converted ones count apart.
	 *
	 * @return void
	 */
	public function testOnlyThisWeeksAnswersCount(): void {
		$stats = (new WeekStats())->of(
			tickets: [
				self::ticket(resolvedAt: '2026-11-08T23:59:59+00:00'),
				self::ticket(resolvedAt: '2026-11-11T16:00:00+00:00'),
				self::ticket(resolvedAt: '2026-11-10T10:00:00+00:00', status: 'in_progress'),
				self::ticket(resolvedAt: 'not a date'),
				self::ticket(resolvedAt: ''),
				['status' => 'resolved'],
				self::ticket(resolvedAt: '2026-11-10T10:00:00+00:00', status: 'converted'),
				self::ticket(resolvedAt: '2026-11-09T00:00:00+00:00', slaDeadline: 'soon'),
			],
			now: self::now()
		);

		$this->assertSame(1, $stats['answered']);
		$this->assertSame(0, $stats['answeredToday']);
		$this->assertSame(1, $stats['converted']);
		$this->assertNull($stats['withinDeadline']);
	}//end testOnlyThisWeeksAnswersCount()

	/**
	 * On a Sunday the week still started on the Monday before.
	 *
	 * @return void
	 */
	public function testTheWeekStartsOnMonday(): void {
		$stats = (new WeekStats())->of(
			tickets: [self::ticket(resolvedAt: '2026-11-09T00:00:00+00:00')],
			now: new DateTimeImmutable('2026-11-15T23:00:00+00:00')
		);

		$this->assertSame('2026-11-09', $stats['weekStart']);
		$this->assertSame(1, $stats['answered']);
	}//end testTheWeekStartsOnMonday()
}//end class
