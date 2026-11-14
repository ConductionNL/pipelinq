<?php

/**
 * Pipelinq CoachingPointAgreement.
 *
 * The rule for an agent agreeing one coaching point of a review of their call.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\CallReview
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
 *
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\CallReview;

/**
 * Pure rule (design D3, task 2.4): only the agent whose call was reviewed agrees
 * a coaching point, only on a review shared with them, and an agreed point keeps
 * the date it was first agreed. The status follows on save (CallReviewTotal).
 *
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
 */
final class CoachingPointAgreement {

	/**
	 * The caller is not the agent whose call the review is about.
	 */
	public const REFUSED_NOT_THE_AGENT = 'not-the-agent';

	/**
	 * The review has not been shared with the agent.
	 */
	public const REFUSED_NOT_SHARED = 'not-shared';

	/**
	 * The review has no coaching point at that position.
	 */
	public const REFUSED_NO_SUCH_POINT = 'no-such-point';

	/**
	 * Why the user may not agree this point, or null when they may.
	 *
	 * An agreed review still accepts the call (it changes nothing), so a second
	 * click on a stale page is not an error.
	 *
	 * @param array<string, mixed> $review The review's data.
	 * @param int                  $index  The coaching point's position, from 0.
	 * @param string               $userId The caller.
	 *
	 * @return string|null One of the REFUSED_* codes, or null.
	 *
	 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
	 */
	public function refusal(array $review, int $index, string $userId): ?string {
		$agent = (string)($review['agent'] ?? '');
		if ($userId === '' || $agent !== $userId) {
			return self::REFUSED_NOT_THE_AGENT;
		}

		if (in_array((string)($review['status'] ?? 'draft'), ['shared', 'agreed'], true) === false) {
			return self::REFUSED_NOT_SHARED;
		}

		$points = $review['coachingPoints'] ?? [];
		if (is_array($points) === false || array_is_list($points) === false
			|| $index < 0 || isset($points[$index]) === false || is_array($points[$index]) === false
		) {
			return self::REFUSED_NO_SUCH_POINT;
		}

		return null;
	}//end refusal()

	/**
	 * The coaching points with the one at `$index` agreed at `$now`. A point
	 * already agreed keeps its first date; the others are left as they are.
	 *
	 * @param array<int, mixed> $points The review's coaching points.
	 * @param int               $index  The point's position, from 0.
	 * @param string            $now    The moment of agreement, ISO 8601.
	 *
	 * @return array<int, mixed> The coaching points to store.
	 *
	 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-the-agent-agrees-the-coaching-points-req-crv-003
	 */
	public function agree(array $points, int $index, string $now): array {
		if (isset($points[$index]) === false || is_array($points[$index]) === false) {
			return $points;
		}

		$agreedAt = (string)($points[$index]['agreedAt'] ?? '');
		if ($agreedAt === '') {
			$points[$index]['agreedAt'] = $now;
		}

		return $points;
	}//end agree()
}//end class
