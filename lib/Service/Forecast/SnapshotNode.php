<?php

/**
 * Pipelinq SnapshotNode.
 *
 * One owner in the forecast hierarchy that gets a snapshot.
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
 * The owner, its level, its four amounts and the children it is missing.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-005-01
 */
class SnapshotNode {
	/**
	 * Constructor.
	 *
	 * @param string               $ownerId The owner id.
	 * @param string               $level   `rep`, `team`, `division` or `company`.
	 * @param array<string, float> $totals  The four amounts.
	 * @param array<int, string>   $missing Child ids without totals; the snapshot is partial when any.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $ownerId,
		public readonly string $level,
		public readonly array $totals,
		public readonly array $missing = [],
	) {
	}//end __construct()
}//end class
