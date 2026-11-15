<?php

/**
 * Pipelinq WinChanceWeightedValue.
 *
 * A lead's value weighted by its qualification score.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

/**
 * The lead value times its qualification score clamped to 0-100 percent; a
 * lead without a numeric score weighs 0.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait WinChanceWeightedValue {

	/**
	 * A lead's value weighted by its win chance.
	 *
	 * The win chance is the lead's qualification score read as a percentage,
	 * clamped to 0-100; a lead without a score counts as 0 (decided by Ruben,
	 * 2026-10-06). The `probability` field is hidden on the lead form and
	 * empty on real installs, so it never feeds a forecast. RapportageService
	 * applies the same rule; keep the two in step.
	 *
	 * @param array<string, mixed> $lead The lead row.
	 *
	 * @return float The weighted value.
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function winChanceWeightedValue(array $lead): float {
		$score = ($lead['qualificationScore'] ?? null);
		if (is_numeric($score) === false) {
			return 0.0;
		}

		return ((float)($lead['value'] ?? 0) * (max(0.0, min(100.0, (float)$score)) / 100.0));
	}//end winChanceWeightedValue()
}//end trait
