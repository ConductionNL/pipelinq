<?php

/**
 * Contract of CallReviewTotal
 *
 * The public surface of CallReviewTotal that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of CallReviewTotal.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-2.1
 */
interface CallReviewTotalInterface {

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
	public function totalOf(array $criteria, array $scores): ?float;

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
	public function statusOf(string $status, array $coachingPoints): string;
}//end interface
