<?php

/**
 * Pipelinq SnapshotRun.
 *
 * What every snapshot of one generation run shares.
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
 * The period, the snapshot date and the reporting currency of one run.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-005-01
 */
class SnapshotRun {
	/**
	 * Constructor.
	 *
	 * @param string $periodId The period id.
	 * @param string $asOfDate The ISO snapshot date.
	 * @param string $currency The reporting currency.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $periodId,
		public readonly string $asOfDate,
		public readonly string $currency,
	) {
	}//end __construct()
}//end class
