<?php

/**
 * Pipelinq SnapshotLevel.
 *
 * One group level of the forecast roll-up (team or division).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Forecast
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
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-005-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Forecast;

/**
 * The level name, its groups and the totals of the level below.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-005-01
 */
class SnapshotLevel {
	/**
	 * Constructor.
	 *
	 * @param string                              $level       `team` or `division`.
	 * @param array<int|string, array<int, string>> $groups      Group id => child ids.
	 * @param array<string, array<string, float>> $childTotals Totals keyed by child id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $level,
		public readonly array $groups,
		public readonly array $childTotals,
	) {
	}//end __construct()
}//end class
