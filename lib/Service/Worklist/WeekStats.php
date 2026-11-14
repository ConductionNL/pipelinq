<?php

/**
 * Pipelinq WeekStats.
 *
 * The figures of My work's "Your week so far" tiles, computed from tickets.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Worklist
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-kpi-summary-tiles-on-my-work-req-mw-090
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Worklist;

use DateTimeImmutable;
use Exception;

/**
 * Pure computation of a calendar week's answer figures over a set of tickets.
 *
 * A ticket counts in the week when its `resolvedAt` falls between Monday 00:00
 * of the current week and now. "Answered" is a resolved or completed ticket;
 * "within the deadline" is the share of answered tickets with an
 * `slaDeadline` that were resolved on or before it; the average answer time
 * runs from `occurredAt` to `resolvedAt`; "converted" counts tickets turned
 * into a case. A figure without data is null, so the page can show a dash
 * instead of a misleading 0.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-kpi-summary-tiles-on-my-work-req-mw-090
 */
final class WeekStats {

	/**
	 * Ticket statuses that mean the ticket was answered.
	 *
	 * @var array<int, string>
	 */
	public const ANSWERED_STATUSES = ['resolved', 'completed'];

	/**
	 * The ticket status of a ticket turned into a case.
	 *
	 * @var string
	 */
	public const CONVERTED_STATUS = 'converted';

	/**
	 * Seconds in a day.
	 *
	 * @var int
	 */
	private const DAY_SECONDS = 86400;

	/**
	 * The week's figures.
	 *
	 * @param array<int, array<string, mixed>> $tickets The tickets, as stored.
	 * @param DateTimeImmutable                $now     Now.
	 *
	 * @return array{
	 *   weekStart: string,
	 *   answered: int,
	 *   answeredToday: int,
	 *   withinDeadline: int|null,
	 *   averageAnswerDays: float|null,
	 *   converted: int
	 * }
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-kpi-summary-tiles-on-my-work-req-mw-090
	 */
	public function of(array $tickets, DateTimeImmutable $now): array {
		$today     = $now->setTime(0, 0);
		$weekStart = $today->modify('-' . ((int)$now->format('N') - 1) . ' days');
		$from      = $weekStart->getTimestamp();
		$todayTs   = $today->getTimestamp();
		$nowTs     = $now->getTimestamp();

		$tally = $this->tally(tickets: $tickets, from: $from, todayTs: $todayTs, nowTs: $nowTs);

		return $this->figures(weekStart: $weekStart, tally: $tally);
	}//end of()

	/**
	 * Count the week's tickets.
	 *
	 * @param array<int, array<string, mixed>> $tickets The tickets, as stored.
	 * @param int                              $from    Monday 00:00 of this week.
	 * @param int                              $todayTs Today 00:00.
	 * @param int                              $nowTs   Now.
	 *
	 * @return array{answered: int, todayCount: int, converted: int, withDue: int, inTime: int, answerTimes: array<int, int>}
	 */
	private function tally(array $tickets, int $from, int $todayTs, int $nowTs): array {
		$tally = ['answered' => 0, 'todayCount' => 0, 'converted' => 0, 'withDue' => 0, 'inTime' => 0, 'answerTimes' => []];
		foreach ($tickets as $ticket) {
			$resolved = $this->resolvedInWindow(ticket: $ticket, from: $from, nowTs: $nowTs);
			if ($resolved === null) {
				continue;
			}

			$tally = $this->countTicket(tally: $tally, ticket: $ticket, resolved: $resolved, todayTs: $todayTs);
		}

		return $tally;
	}//end tally()

	/**
	 * A ticket's resolve time when it falls between the week's start and now, otherwise null.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 * @param int                  $from   Monday 00:00 of this week.
	 * @param int                  $nowTs  Now.
	 *
	 * @return int|null
	 */
	private function resolvedInWindow(array $ticket, int $from, int $nowTs): int|null {
		$resolved = $this->timestamp(value: ($ticket['resolvedAt'] ?? null));
		if ($resolved === null || $resolved < $from || $resolved > $nowTs) {
			return null;
		}

		return $resolved;
	}//end resolvedInWindow()

	/**
	 * Count one ticket resolved this week.
	 *
	 * @param array<string, mixed> $tally    The counts so far.
	 * @param array<string, mixed> $ticket   The ticket.
	 * @param int                  $resolved When it was resolved.
	 * @param int                  $todayTs  Today 00:00.
	 *
	 * @return array<string, mixed> The counts.
	 */
	private function countTicket(array $tally, array $ticket, int $resolved, int $todayTs): array {
		$status = (string)($ticket['status'] ?? '');
		if ($status === self::CONVERTED_STATUS) {
			$tally['converted']++;
			return $tally;
		}

		if (in_array($status, self::ANSWERED_STATUSES, true) === false) {
			return $tally;
		}

		$tally = $this->countAnswered(tally: $tally, resolved: $resolved, todayTs: $todayTs);
		$tally = $this->countDeadline(tally: $tally, ticket: $ticket, resolved: $resolved);
		return $this->countAnswerTime(tally: $tally, ticket: $ticket, resolved: $resolved);
	}//end countTicket()

	/**
	 * Count an answered ticket, and whether it was answered today.
	 *
	 * @param array<string, mixed> $tally    The counts so far.
	 * @param int                  $resolved When it was resolved.
	 * @param int                  $todayTs  Today 00:00.
	 *
	 * @return array<string, mixed> The counts.
	 */
	private function countAnswered(array $tally, int $resolved, int $todayTs): array {
		$tally['answered']++;
		if ($resolved >= $todayTs) {
			$tally['todayCount']++;
		}

		return $tally;
	}//end countAnswered()

	/**
	 * Count an answered ticket against its deadline.
	 *
	 * @param array<string, mixed> $tally    The counts so far.
	 * @param array<string, mixed> $ticket   The ticket.
	 * @param int                  $resolved When it was resolved.
	 *
	 * @return array<string, mixed> The counts.
	 */
	private function countDeadline(array $tally, array $ticket, int $resolved): array {
		$deadline = $this->timestamp(value: ($ticket['slaDeadline'] ?? null));
		if ($deadline !== null) {
			$tally['withDue']++;
			if ($resolved <= $deadline) {
				$tally['inTime']++;
			}
		}

		return $tally;
	}//end countDeadline()

	/**
	 * Record an answered ticket's answer time.
	 *
	 * @param array<string, mixed> $tally    The counts so far.
	 * @param array<string, mixed> $ticket   The ticket.
	 * @param int                  $resolved When it was resolved.
	 *
	 * @return array<string, mixed> The counts.
	 */
	private function countAnswerTime(array $tally, array $ticket, int $resolved): array {
		$occurred = $this->timestamp(value: ($ticket['occurredAt'] ?? null));
		if ($occurred !== null && $occurred <= $resolved) {
			$tally['answerTimes'][] = ($resolved - $occurred);
		}

		return $tally;
	}//end countAnswerTime()

	/**
	 * The week's figures from its counts.
	 *
	 * @param DateTimeImmutable    $weekStart Monday 00:00 of this week.
	 * @param array<string, mixed> $tally     The counts.
	 *
	 * @return array{
	 *   weekStart: string,
	 *   answered: int,
	 *   answeredToday: int,
	 *   withinDeadline: int|null,
	 *   averageAnswerDays: float|null,
	 *   converted: int
	 * }
	 */
	private function figures(DateTimeImmutable $weekStart, array $tally): array {
		return [
			'weekStart'         => $weekStart->format('Y-m-d'),
			'answered'          => $tally['answered'],
			'answeredToday'     => $tally['todayCount'],
			'withinDeadline'    => $this->percentage(part: $tally['inTime'], whole: $tally['withDue']),
			'averageAnswerDays' => $this->averageDays(seconds: $tally['answerTimes']),
			'converted'         => $tally['converted'],
		];
	}//end figures()

	/**
	 * A rounded percentage, or null for an empty whole.
	 *
	 * @param int $part  The part.
	 * @param int $whole The whole.
	 *
	 * @return int|null The percentage.
	 */
	private function percentage(int $part, int $whole): ?int {
		if ($whole === 0) {
			return null;
		}

		return (int)round(($part * 100) / $whole);
	}//end percentage()

	/**
	 * The mean of durations in days, to one decimal, or null for none.
	 *
	 * @param array<int, int> $seconds The durations in seconds.
	 *
	 * @return float|null The mean in days.
	 */
	private function averageDays(array $seconds): ?float {
		if ($seconds === []) {
			return null;
		}

		return round((array_sum($seconds) / count($seconds)) / self::DAY_SECONDS, 1);
	}//end averageDays()

	/**
	 * A stored date as a timestamp, or null when absent or unreadable.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return int|null The timestamp.
	 */
	private function timestamp(mixed $value): ?int {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->getTimestamp();
		} catch (Exception $e) {
			return null;
		}
	}//end timestamp()
}//end class
