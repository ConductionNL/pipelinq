<?php

/**
 * Pipelinq PointsGrant.
 *
 * The points a matched rule grants, after the tier multiplier and the period maximum.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Loyalty
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
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Loyalty;

/**
 * The points a matched rule grants, after the tier multiplier and the period maximum.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */
class PointsGrant {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $rule       The matched PointsRule.
	 * @param int                  $points     Points to credit.
	 * @param float                $multiplier The applied tier multiplier.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $rule,
		public readonly int $points,
		public readonly float $multiplier,
	) {
	}//end __construct()
}//end class
