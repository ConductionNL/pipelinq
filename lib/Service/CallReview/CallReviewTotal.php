<?php

/**
 * Pipelinq CallReviewTotal.
 *
 * The weighted total of a call review and whether its coaching points are agreed.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\CallReview
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\CallReview;

/**
 * Pure rules of a call review (design D3): the total is the weighted mean of
 * the scores on the scorecard's criteria, and a shared review whose coaching
 * points all carry an agreed date is agreed.
 */
final class CallReviewTotal {

	/**
	 * The weighted mean of the scores, rounded to one decimal, or null when no
	 * criterion is scored. A score counts only for a criterion of the scorecard
	 * and only within that criterion's scale; a criterion without a weight
	 * weighs 1.
	 *
	 * @param array<int, mixed> $criteria The scorecard's criteria: `{key, weight, scale}`.
	 * @param array<int, mixed> $scores   The review's scores: `{key, score}`.
	 *
	 * @return float|null The total.
	 *
	 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-a-reviewer-scores-a-call-and-writes-coaching-points-req-crv-002
	 */
	public function totalOf(array $criteria, array $scores): ?float {
		$byKey = $this->scoresByKey(scores: $scores);
		$weighted = 0.0;
		$weights = 0.0;
		foreach ($criteria as $criterion) {
			$criterion = (array)$criterion;
			$key = (string)($criterion['key'] ?? '');
			if (isset($byKey[$key]) === false) {
				continue;
			}

			$score = $byKey[$key];
			$scale = (int)($criterion['scale'] ?? 5);
			if ($score < 1 || $score > $scale) {
				continue;
			}

			$weight = (float)($criterion['weight'] ?? 1);
			$weighted += ($weight * $score);
			$weights += $weight;
		}

		if ($weights <= 0.0) {
			return null;
		}

		return round($weighted / $weights, 1);
	}//end totalOf()

	/**
	 * The status a review should have: a shared review whose coaching points
	 * are all agreed becomes agreed; any other status stays.
	 *
	 * @param string            $status         The review's status.
	 * @param array<int, mixed> $coachingPoints The review's coaching points.
	 *
	 * @return string The status.
	 *
	 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
	 */
	public function statusOf(string $status, array $coachingPoints): string {
		if ($status !== 'shared' || $coachingPoints === []) {
			return $status;
		}

		foreach ($coachingPoints as $point) {
			if (trim((string)(((array)$point)['agreedAt'] ?? '')) === '') {
				return $status;
			}
		}

		return 'agreed';
	}//end statusOf()

	/**
	 * The integer score per criterion key; a later score for a key wins.
	 *
	 * @param array<int, mixed> $scores The review's scores.
	 *
	 * @return array<string, int>
	 */
	private function scoresByKey(array $scores): array {
		$byKey = [];
		foreach ($scores as $score) {
			$score = (array)$score;
			$key = (string)($score['key'] ?? '');
			if ($key === '' || is_numeric($score['score'] ?? null) === false) {
				continue;
			}

			$byKey[$key] = (int)$score['score'];
		}

		return $byKey;
	}//end scoresByKey()
}//end class
