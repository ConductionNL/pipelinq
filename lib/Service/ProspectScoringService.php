<?php

/**
 * Pipelinq ProspectScoringService.
 *
 * Service for calculating ICP fit scores for prospect companies.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-prospect-fit-scoring
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Calculates fit scores based on ICP criteria.
 *
 * Score breakdown (max 100):
 * - SBI code match: 30 points
 * - Employee count match: 25 points
 * - Location (province) match: 20 points
 * - Legal form match: 15 points
 * - Active registration: 10 points
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */
class ProspectScoringService {
	/**
	 * Score a single prospect against ICP criteria.
	 *
	 * @param array $prospect The prospect data.
	 * @param array $criteria The ICP criteria.
	 *
	 * @return array The prospect with fitScore and fitBreakdown added.
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-prospect-fit-scoring
	 */
	public function score(array $prospect, array $criteria): array {
		$breakdown = $this->breakdown(prospect: $prospect, criteria: $criteria);

		$prospect['fitScore'] = array_sum(array: $breakdown);
		$prospect['fitBreakdown'] = $breakdown;

		return $prospect;
	}//end score()

	/**
	 * The points per rule: SBI code (30), employee count (25), location (20),
	 * legal form (15) and an active registration (10).
	 *
	 * @param array $prospect The prospect data.
	 * @param array $criteria The ICP criteria.
	 *
	 * @return array<string, int> Rule => points, in this order.
	 */
	private function breakdown(array $prospect, array $criteria): array {
		return [
			'sbiMatch' => $this->scoreSbi(sbiCode: $prospect['sbiCode'] ?? '', targetCodes: $criteria['sbiCodes'] ?? []),
			'employeeMatch' => $this->employeeMatch(prospect: $prospect, criteria: $criteria),
			'locationMatch' => $this->scoreLocation(province: $prospect['address']['province'] ?? '', targetProvinces: $criteria['provinces'] ?? []),
			'legalFormMatch' => $this->scoreLegalForm(legalForm: $prospect['legalForm'] ?? '', targetForms: $criteria['legalForms'] ?? []),
			'activeMatch' => $this->activeMatch(prospect: $prospect),
		];
	}//end breakdown()

	/**
	 * The employee count points for the prospect against the criteria's range.
	 *
	 * @param array $prospect The prospect data.
	 * @param array $criteria The ICP criteria.
	 *
	 * @return int The employee score (0 or 25).
	 */
	private function employeeMatch(array $prospect, array $criteria): int {
		return $this->scoreEmployeeCount(
			count: $prospect['employeeCount'] ?? null,
			min: $criteria['employeeCountMin'] ?? 0,
			max: $criteria['employeeCountMax'] ?? 0
		);
	}//end employeeMatch()

	/**
	 * The active registration points: 10 only for a literal `true`.
	 *
	 * @param array $prospect The prospect data.
	 *
	 * @return int The active score (0 or 10).
	 */
	private function activeMatch(array $prospect): int {
		if (($prospect['isActive'] ?? false) === true) {
			return 10;
		}

		return 0;
	}//end activeMatch()

	/**
	 * Score multiple prospects and sort by fit score descending.
	 *
	 * @param array $prospects The prospects to score.
	 * @param array $criteria The ICP criteria.
	 *
	 * @return array The scored and sorted prospects.
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-prospect-fit-scoring
	 */
	public function scoreAll(array $prospects, array $criteria): array {
		$scored = array_map(
			callback: fn (array $prospect): array => $this->score(
				prospect: $prospect,
				criteria: $criteria
			),
			array: $prospects
		);

		usort(
			array: $scored,
			callback: fn (array $a, array $b): int => ($b['fitScore'] ?? 0) <=> ($a['fitScore'] ?? 0)
		);

		return $scored;
	}//end scoreAll()

	/**
	 * Score SBI code match.
	 *
	 * @param string $sbiCode The prospect's SBI code.
	 * @param array $targetCodes The target SBI codes.
	 *
	 * @return int The SBI score (0 or 30).
	 */
	private function scoreSbi(string $sbiCode, array $targetCodes): int {
		if (count($targetCodes) === 0 || $sbiCode === '') {
			return 0;
		}

		foreach ($targetCodes as $target) {
			if (str_starts_with(haystack: $sbiCode, needle: (string)$target) === true) {
				return 30;
			}
		}

		return 0;
	}//end scoreSbi()

	/**
	 * Score employee count match.
	 *
	 * @param int|null $count The prospect's employee count.
	 * @param int $min The minimum desired count.
	 * @param int $max The maximum desired count.
	 *
	 * @return int The employee score (0 or 25).
	 */
	private function scoreEmployeeCount(?int $count, int $min, int $max): int {
		if ($count === null || ($min === 0 && $max === 0)) {
			return 0;
		}

		return $this->rangePoints(count: $count, range: [$min, $max]);
	}//end scoreEmployeeCount()

	/**
	 * The employee points for a count at or over the minimum and within the maximum.
	 *
	 * @param int                $count The prospect's employee count.
	 * @param array{0:int, 1:int} $range The minimum and the maximum (0 for none).
	 *
	 * @return int The employee score (0 or 25).
	 */
	private function rangePoints(int $count, array $range): int {
		[$min, $max] = $range;
		if ($count >= $min && $this->withinMax(count: $count, max: $max) === true) {
			return 25;
		}

		return 0;
	}//end rangePoints()

	/**
	 * Whether a count is under the maximum; no maximum (0) means no upper bound.
	 *
	 * @param int $count The prospect's employee count.
	 * @param int $max   The maximum desired count, 0 for none.
	 *
	 * @return bool
	 */
	private function withinMax(int $count, int $max): bool {
		if ($max === 0) {
			return true;
		}

		return $max > 0 && $count <= $max;
	}//end withinMax()

	/**
	 * Score location match.
	 *
	 * @param string $province The prospect's province.
	 * @param array $targetProvinces The target provinces.
	 *
	 * @return int The location score (0 or 20).
	 */
	private function scoreLocation(string $province, array $targetProvinces): int {
		if ($this->matchesAnyName(value: $province, targets: $targetProvinces) === true) {
			return 20;
		}

		return 0;
	}//end scoreLocation()

	/**
	 * Score legal form match.
	 *
	 * @param string $legalForm The prospect's legal form.
	 * @param array $targetForms The target legal forms.
	 *
	 * @return int The legal form score (0 or 15).
	 */
	private function scoreLegalForm(string $legalForm, array $targetForms): int {
		if ($this->matchesAnyName(value: $legalForm, targets: $targetForms) === true) {
			return 15;
		}

		return 0;
	}//end scoreLegalForm()

	/**
	 * Whether a non-empty value equals one of the targets, trimmed and
	 * case-insensitive; never with no targets.
	 *
	 * @param string $value   The prospect's value.
	 * @param array  $targets The target values.
	 *
	 * @return bool
	 */
	private function matchesAnyName(string $value, array $targets): bool {
		if (count($targets) === 0 || $value === '') {
			return false;
		}

		$normalised = strtolower(string: trim(string: $value));
		foreach ($targets as $target) {
			if (strtolower(string: trim(string: (string)$target)) === $normalised) {
				return true;
			}
		}

		return false;
	}//end matchesAnyName()
}//end class
