<?php

/**
 * Pipelinq ForecastSummaryService.
 *
 * The forecast page's figures for the current quarter, worked out on the
 * server: the latest snapshot per owner, the quota, won, committed and
 * still-to-get totals, the progress line, the at-risk warning and the trend.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Works out the forecast page's figures from the snapshots of a period.
 *
 * The rules are the ones the page applied in the browser (forecastMath.js):
 * projected = won + committed + half of best case; still to get = quota minus
 * projected, never below zero; at risk below 90% of quota with under 30 days
 * left in the quarter.
 *
 * @spec exclude forecast has no owning requirement. pipeline-insights defers its config
 *   scenario to admin-settings, which never grew one
 */
class ForecastSummaryService {
	/**
	 * The level a level's rows are about: a team's rows are its sellers.
	 *
	 * @var array<string, string>
	 */
	public const CHILD_LEVEL = ['company' => 'division', 'division' => 'team', 'team' => 'rep', 'rep' => 'rep'];

	/**
	 * Constructor.
	 *
	 * @param ForecastExportService $exportService Reads the snapshots.
	 * @param IUserManager          $userManager   Names a seller.
	 * @param IGroupManager         $groupManager  Names a team, division or company.
	 * @param IL10N                 $l10n          Words the sentences.
	 */
	public function __construct(
		private ForecastExportService $exportService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The current quarter: its id (`Q4-2026`), number, year and the days left in it.
	 *
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return array{periodId: string, quarter: int, year: int, daysRemaining: int}
	 */
	public function currentPeriod(DateTimeImmutable $now): array {
		$quarter = intdiv(((int)$now->format('n') - 1), 3) + 1;
		$year = (int)$now->format('Y');
		$end = $now->setDate($year, ($quarter * 3), 1)->modify('last day of this month')->setTime(23, 59, 59);

		return [
			'periodId' => 'Q' . $quarter . '-' . $year,
			'quarter' => $quarter,
			'year' => $year,
			'daysRemaining' => (int)$now->diff($end)->days,
		];
	}//end currentPeriod()

	/**
	 * Every snapshot of a period at a level, read a page at a time.
	 *
	 * @param string      $periodId The period.
	 * @param string      $level    The level.
	 * @param string|null $ownerId  One owner, or null for every owner.
	 *
	 * @return array<int, array<string, mixed>> The snapshots, oldest first.
	 */
	public function snapshots(string $periodId, string $level, ?string $ownerId = null): array {
		$all = [];
		$offset = 0;
		do {
			$page = $this->exportService->exportSnapshots(periodId: $periodId, level: $level, ownerId: $ownerId, limit: 200, offset: $offset);
			$rows = $page['snapshots'];
			$all = array_merge($all, $rows);
			$offset += 200;
		} while (count($rows) === 200 && $offset < (int)$page['total'] && $offset < 5000);

		return $all;
	}//end snapshots()

	/**
	 * The page's figures for one level of the current quarter.
	 *
	 * @param string            $level   The level the page shows (its rows are one level down).
	 * @param DateTimeImmutable $now     The clock.
	 * @param string|null       $ownerId One seller's own figures instead of a level's.
	 *
	 * @return array<string, mixed> period, summary, rows and trend.
	 */
	public function summary(string $level, DateTimeImmutable $now, ?string $ownerId = null): array {
		$period = $this->currentPeriod(now: $now);
		$childLevel = self::CHILD_LEVEL[$level];
		if ($ownerId !== null && $ownerId !== '') {
			$childLevel = 'rep';
		}

		$snapshots = $this->snapshots(periodId: $period['periodId'], level: $childLevel, ownerId: $ownerId);
		$result = $this->summarize(snapshots: $snapshots, period: $period);
		foreach (array_keys($result['rows']) as $index) {
			$result['rows'][$index]['level'] = $childLevel;
		}

		return $result;
	}//end summary()

	/**
	 * The figures of a set of snapshots.
	 *
	 * @param array<int, array<string, mixed>> $snapshots The snapshots.
	 * @param array<string, mixed>             $period    The period, as currentPeriod() answers it.
	 *
	 * @return array<string, mixed> period, summary, rows and trend.
	 */
	public function summarize(array $snapshots, array $period): array {
		$latest = [];
		foreach ($snapshots as $row) {
			$owner = (string)($row['owner_id'] ?? '');
			if (isset($latest[$owner]) === false || (string)($row['as_of_date'] ?? '') > (string)($latest[$owner]['as_of_date'] ?? '')) {
				$latest[$owner] = $row;
			}
		}

		$rows = [];
		foreach ($latest as $row) {
			$rows[] = ['periodId' => $period['periodId'], 'level' => (string)($row['level'] ?? '')] + $this->ownerRow(row: $row);
		}

		$sum = static fn (string $key): float => array_sum(array_column($rows, $key));

		$quota = $sum('quota');
		$won = $sum('closedWon');
		$commit = $sum('commit');
		$projected = ($won + $commit + (0.5 * $sum('bestCase')));
		$onTrack = ($won + $commit);
		$gapPercent = 0;
		if ($quota > 0) {
			$gapPercent = max(0, (100 - (int)round(($projected / $quota) * 100)));
		}

		$atRisk = ($quota > 0 && (($projected / $quota) * 100) < 90 && $period['daysRemaining'] < 30);

		return [
			'period' => [
				'id' => $period['periodId'],
				'label' => $this->l10n->t('Quarter %1$s of %2$s', [$period['quarter'], $period['year']]),
				'daysRemaining' => $period['daysRemaining'],
			],
			'summary' => [
				'quota' => $quota,
				'closedWon' => $won,
				'commit' => $commit,
				'projected' => $projected,
				'gap' => max(0.0, ($quota - $projected)),
				'gapPercent' => $gapPercent,
				'onTrack' => $onTrack,
				'onTrackPercent' => $this->percent(value: $onTrack, quota: $quota),
				'atRisk' => $atRisk,
				'atRiskText' => $this->atRiskText(atRisk: $atRisk, gapPercent: $gapPercent, daysRemaining: $period['daysRemaining']),
			],
			'rows' => $rows,
			'trend' => $this->trend(snapshots: $snapshots),
		];
	}//end summarize()

	/**
	 * One owner's row: the latest snapshot's figures, the owner named.
	 *
	 * @param array<string, mixed> $row The snapshot.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function ownerRow(array $row): array {
		$owner = (string)($row['owner_id'] ?? '');
		$quota = null;
		if (($row['quota'] ?? null) !== null) {
			$quota = (float)$row['quota'];
		}

		return [
			'ownerId' => $owner,
			'ownerName' => $this->ownerName(id: $owner),
			'commit' => (float)($row['commit'] ?? 0),
			'bestCase' => (float)($row['best_case'] ?? 0),
			'pipeline' => (float)($row['pipeline'] ?? 0),
			'closedWon' => (float)($row['closed_won'] ?? 0),
			'quota' => $quota,
			'overridden' => trim((string)($row['commit_override_reason'] ?? '')) !== '',
			'originalCommit' => (float)($row['original_commit'] ?? 0),
			'overrideReason' => (string)($row['commit_override_reason'] ?? ''),
		];
	}//end ownerRow()

	/**
	 * A seller's display name, else a group's, else the id itself.
	 *
	 * @param string $id The owner id.
	 *
	 * @return string The name.
	 */
	private function ownerName(string $id): string {
		if ($id === '') {
			return '';
		}

		$name = $this->userManager->getDisplayName($id);
		if ($name !== null && $name !== '') {
			return $name;
		}

		$group = $this->groupManager->get($id);
		if ($group !== null && $group->getDisplayName() !== '') {
			return $group->getDisplayName();
		}

		return $id;
	}//end ownerName()

	/**
	 * Commit, best case and pipeline summed per snapshot date, and what moved
	 * into or out of commit since the snapshot before the last.
	 *
	 * @param array<int, array<string, mixed>> $snapshots The snapshots.
	 *
	 * @return array<string, mixed> labels, the three series and the commit move.
	 */
	private function trend(array $snapshots): array {
		$byDate = [];
		foreach ($snapshots as $row) {
			$date = (string)($row['as_of_date'] ?? '');
			$byDate[$date] = ($byDate[$date] ?? ['commit' => 0.0, 'bestCase' => 0.0, 'pipeline' => 0.0]);
			$byDate[$date]['commit'] += (float)($row['commit'] ?? 0);
			$byDate[$date]['bestCase'] += (float)($row['best_case'] ?? 0);
			$byDate[$date]['pipeline'] += (float)($row['pipeline'] ?? 0);
		}

		ksort($byDate);
		$commit = array_column(array_values($byDate), 'commit');
		$move = 0.0;
		if (count($commit) > 1) {
			$move = ($commit[(count($commit) - 1)] - $commit[(count($commit) - 2)]);
		}

		return [
			'labels' => array_keys($byDate),
			'commit' => $commit,
			'bestCase' => array_column(array_values($byDate), 'bestCase'),
			'pipeline' => array_column(array_values($byDate), 'pipeline'),
			'intoCommit' => max(0.0, $move),
			'outOfCommit' => max(0.0, -$move),
		];
	}//end trend()

	/**
	 * The warning for a quarter at risk, '' when it is not.
	 *
	 * @param bool $atRisk        Whether the quarter is at risk.
	 * @param int  $gapPercent    How far below quota it is, in percent.
	 * @param int  $daysRemaining The days left.
	 *
	 * @return string The sentence.
	 */
	private function atRiskText(bool $atRisk, int $gapPercent, int $daysRemaining): string {
		if ($atRisk === false) {
			return '';
		}

		return $this->l10n->t(
			'This team is %1$s%% below quota with %2$s days to close. Action recommended.',
			[$gapPercent, $daysRemaining]
		);
	}//end atRiskText()

	/**
	 * A value as a whole percentage of the quota, 0 without a quota.
	 *
	 * @param float $value The value.
	 * @param float $quota The quota.
	 *
	 * @return int The percentage.
	 */
	private function percent(float $value, float $quota): int {
		if ($quota <= 0) {
			return 0;
		}

		return (int)round(($value / $quota) * 100);
	}//end percent()
}//end class
