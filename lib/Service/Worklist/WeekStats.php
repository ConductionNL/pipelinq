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

		$answered    = 0;
		$todayCount  = 0;
		$converted   = 0;
		$withDue     = 0;
		$inTime      = 0;
		$answerTimes = [];
		foreach ($tickets as $ticket) {
			$resolved = $this->timestamp(value: ($ticket['resolvedAt'] ?? null));
			if ($resolved === null || $resolved < $from || $resolved > $nowTs) {
				continue;
			}

			$status = (string)($ticket['status'] ?? '');
			if ($status === self::CONVERTED_STATUS) {
				$converted++;
				continue;
			}

			if (in_array($status, self::ANSWERED_STATUSES, true) === false) {
				continue;
			}

			$answered++;
			if ($resolved >= $todayTs) {
				$todayCount++;
			}

			$deadline = $this->timestamp(value: ($ticket['slaDeadline'] ?? null));
			if ($deadline !== null) {
				$withDue++;
				if ($resolved <= $deadline) {
					$inTime++;
				}
			}

			$occurred = $this->timestamp(value: ($ticket['occurredAt'] ?? null));
			if ($occurred !== null && $occurred <= $resolved) {
				$answerTimes[] = ($resolved - $occurred);
			}
		}//end foreach

		return [
			'weekStart'         => $weekStart->format('Y-m-d'),
			'answered'          => $answered,
			'answeredToday'     => $todayCount,
			'withinDeadline'    => $this->percentage(part: $inTime, whole: $withDue),
			'averageAnswerDays' => $this->averageDays(seconds: $answerTimes),
			'converted'         => $converted,
		];
	}//end of()

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
