<?php

/**
 * Pipelinq CashShiftSummaryService.
 *
 * What the shifts list and the shift page show beside a shift that its record
 * does not hold: its drops, its newest cash difference and margin, an outcome
 * word, the sales booked in it and the Z report of its till on its day. Read
 * only; every record is read through CashShiftService, scoped to this app's
 * cash schemas.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pos-cash-management/spec.md#requirement-a-shift-summary-reads-its-drops-and-difference
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Reads the summary of cash shifts.
 */
class CashShiftSummaryService {
	/**
	 * Constructor.
	 *
	 * @param CashShiftService $shifts The cash shift service that reads the records.
	 */
	public function __construct(
		private CashShiftService $shifts,
	) {
	}//end __construct()

	/**
	 * Read what the shifts list and the shift page show beside a shift: its
	 * drops, its newest cash difference and the outcome word the list draws
	 * as a pill. A shift that cannot be read is left out.
	 *
	 * @param array<int, string> $shiftIds The shift UUIDs.
	 *
	 * @return array<string, array<string, mixed>> The summary per shift UUID.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md#requirement-a-shift-summary-reads-its-drops-and-difference
	 */
	public function summarise(array $shiftIds): array {
		$out = [];
		foreach (array_unique(array_filter(array_map('strval', $shiftIds))) as $shiftId) {
			try {
				$shift = $this->shifts->fetchShift(id: $shiftId);
			} catch (OCSNotFoundException $e) {
				continue;
			}

			$out[$shiftId] = $this->summaryOf(shift: $shift, shiftId: $shiftId);
		}

		return $out;
	}//end summarise()

	/**
	 * The summary of one shift for its page: the list summary plus the sales
	 * booked in the shift and the Z report of its till on its day.
	 *
	 * @param string $shiftId The shift UUID.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @throws OCSNotFoundException If the shift does not exist.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md#requirement-a-shift-page-names-its-sales-and-z-report
	 */
	public function shiftSummary(string $shiftId): array {
		$shift = $this->shifts->fetchShift(id: $shiftId);
		$summary = $this->summaryOf(shift: $shift, shiftId: $shiftId);
		$sales = count($this->shifts->fetchByShift(schemaKey: 'posTransaction_schema', shiftId: $shiftId));
		$summary['sales'] = [['id' => 'sales', 'count' => $sales]];
		$summary['zReports'] = $this->zReportsFor(
			drawer: (string)($shift['drawer'] ?? ''),
			day: substr((string)($shift['floatAt'] ?? ''), 0, 10)
		);

		return $summary;
	}//end shiftSummary()

	/**
	 * The summary of a shift record.
	 *
	 * @param array<string, mixed> $shift The shift.
	 * @param string $shiftId The shift UUID.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function summaryOf(array $shift, string $shiftId): array {
		$drops = $this->shifts->fetchByShift(schemaKey: 'cashDrop_schema', shiftId: $shiftId);
		$total = 0.0;
		foreach ($drops as $drop) {
			$total += (float)($drop['amount'] ?? 0);
		}

		// The newest difference: the pending one, else the last read.
		$diffs = $this->shifts->fetchByShift(schemaKey: 'cashDiff_schema', shiftId: $shiftId);
		$pending = array_values(array_filter($diffs, fn (array $row): bool => ($row['status'] ?? '') === 'pending'));
		$diff = $pending[0] ?? ($diffs[count($diffs) - 1] ?? []);

		$difference = null;
		$tolerance = null;
		if ($diff !== []) {
			$difference = (float)($diff['diffAmount'] ?? 0);
			$tolerance = (float)($diff['tolerancePercentage'] ?? 0);
		}

		return [
			'id' => $shiftId,
			'floatAmount' => (float)($shift['floatAmount'] ?? 0),
			'dropCount' => count($drops),
			'dropTotal' => round($total, 2),
			'difference' => $difference,
			'tolerancePercentage' => $tolerance,
			'outcome' => $this->outcomeOf(status: (string)($shift['status'] ?? ''), diff: $diff),
		];
	}//end summaryOf()

	/**
	 * The outcome word of a shift: open, counted, approved (an exact count),
	 * within the margin, outside the margin, or rejected.
	 *
	 * @param string $status The shift status.
	 * @param array<string, mixed> $diff The newest difference, or [].
	 *
	 * @return string The outcome word.
	 */
	private function outcomeOf(string $status, array $diff): string {
		if ($status === 'open') {
			return 'open';
		}

		if ($diff === []) {
			return 'counted';
		}

		if ((string)($diff['status'] ?? '') === 'rejected') {
			return 'rejected';
		}

		if (abs((float)($diff['diffAmount'] ?? 0)) < 0.005 && (string)($diff['status'] ?? '') === 'approved') {
			return 'approved';
		}

		if (($diff['withinTolerance'] ?? false) === true) {
			return 'within the margin';
		}

		return 'outside the margin';
	}//end outcomeOf()

	/**
	 * The Z reports of a till on a day: the till name starts the drawer's
	 * name ("Balie 3" for "Balie 3, stadskantoor").
	 *
	 * @param string $drawer The shift's drawer.
	 * @param string $day The shift's day, Y-m-d.
	 *
	 * @return array<int, array<string, mixed>> The Z reports: id, reference, reportDate, terminalId.
	 */
	private function zReportsFor(string $drawer, string $day): array {
		if ($drawer === '' || $day === '') {
			return [];
		}

		$results = $this->shifts->fetchWhere(schemaKey: 'posZReport_schema', filters: ['reportDate' => $day]);

		$reports = [];
		foreach ($results as $row) {
			$till = trim((string)($row['terminalId'] ?? ''));
			$sameDay = substr((string)($row['reportDate'] ?? ''), 0, 10) === $day;
			if ($till === '' || $sameDay === false || stripos($drawer, $till) !== 0) {
				continue;
			}

			$reports[] = [
				'id' => (string)($row['id'] ?? $row['uuid'] ?? ''),
				'reference' => (string)($row['reference'] ?? ''),
				'reportDate' => (string)($row['reportDate'] ?? ''),
				'terminalId' => $till,
			];
		}

		return $reports;
	}//end zReportsFor()
}//end class
