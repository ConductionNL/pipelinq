<?php

/**
 * Pipelinq SlaAttainmentFigures.
 *
 * The arithmetic of an SLA attainment payload: the overall, per-target and
 * per-group attainment ratios and the shape of the payload around them.
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeInterface;

/**
 * Pure attainment figures; built by SlaAttainmentService.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class SlaAttainmentFigures {
	/**
	 * The attainment and the counts of the payload.
	 *
	 * @param array<string, mixed> $merged      The merged met/breached counts.
	 * @param array<string, mixed> $accumulated The breach-event accumulation.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function counts(array $merged, array $accumulated): array {
		$overallAttainment = $this->ratio(numerator: $merged['met'], denominator: $merged['total']);

		return [
			'attainment' => $overallAttainment,
			// Same value scaled to a literal percent (0–100) so a declarative
			// dashboard stat widget can render it with format.style "percent".
			'attainmentPercent' => round(($overallAttainment * 100), 1),
			'total' => $merged['total'],
			'met' => $merged['met'],
			'breached' => $accumulated['breached'],
			'inFlightBreached' => $accumulated['inFlight'],
			'closedBreached' => $accumulated['closed'],
		];
	}//end counts()

	/**
	 * The range of the payload, as ATOM timestamps.
	 *
	 * @param DateTimeInterface $start The range start.
	 * @param DateTimeInterface $end   The range end (exclusive).
	 *
	 * @return array{start: string, end: string}
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function range(DateTimeInterface $start, DateTimeInterface $end): array {
		return [
			'start' => $start->format(DateTimeInterface::ATOM),
			'end' => $end->format(DateTimeInterface::ATOM),
		];
	}//end range()

	/**
	 * The per-target and per-group details of the payload.
	 *
	 * @param array<string, mixed> $merged The merged met/breached counts.
	 *
	 * @return array{byTarget: array<string, mixed>, byGroup: array<int, mixed>}
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function details(array $merged): array {
		return [
			'byTarget' => $this->byTarget(byTarget: $merged['byTarget']),
			'byGroup' => $this->byGroup(groupAccum: $merged['groupAccum']),
		];
	}//end details()

	/**
	 * Build the `details.byTarget` output payload from the merged per-target counts.
	 *
	 * @param array<string, array<string, int>> $byTarget Merged per-target breached/met counts.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function byTarget(array $byTarget): array {
		$byTargetOut = [];
		foreach ($byTarget as $kind => $counts) {
			$withCount = (int)$counts['met'];
			$denom = ($counts['breached'] + $withCount);
			$attainment = $this->ratio(numerator: $withCount, denominator: $denom);

			$byTargetOut[$kind] = [
				'attainment' => $attainment,
				'breached' => $counts['breached'],
				'met' => $withCount,
			];
		}

		return $byTargetOut;
	}//end byTarget()


	/**
	 * Build the `details.byGroup` output payload from the merged per-group counts.
	 *
	 * @param array<string, array<string, mixed>> $groupAccum Merged per-group breached/met counts.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function byGroup(array $groupAccum): array {
		$byGroup = [];
		foreach ($groupAccum as $key => $entry) {
			$denom = (int)$entry['total'];
			$withE = (int)($entry['met'] ?? 0);
			$groupAttainment = $this->ratio(numerator: $withE, denominator: $denom);

			$byGroup[] = [
				'groupKey' => (string)$key,
				'groupName' => (string)($entry['name'] ?? $key),
				'attainment' => $groupAttainment,
				'total' => $denom,
				'met' => $withE,
				'breached' => (int)$entry['breached'],
			];
		}

		return $byGroup;
	}//end byGroup()


	/**
	 * Ratio rounded to 4 decimals, or 0.0 when the denominator is not positive.
	 *
	 * @param int $numerator The numerator.
	 * @param int $denominator The denominator.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function ratio(int $numerator, int $denominator): float {
		if ($denominator > 0) {
			return round(($numerator / $denominator), 4);
		}

		return 0.0;
	}//end ratio()
}//end class
