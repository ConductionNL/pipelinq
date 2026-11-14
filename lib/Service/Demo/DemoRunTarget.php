<?php

/**
 * Pipelinq DemoRunTarget.
 *
 * The seeded journey a set of demo runs belongs to, with the seed's shared scope.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

/**
 * One seeded journey's id and the seed's run scope.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
final class DemoRunTarget {
	/**
	 * Constructor.
	 *
	 * @param string       $journeyId The seeded journey's id.
	 * @param DemoRunScope $scope     The seed's uuid map and run schema.
	 */
	public function __construct(
		public readonly string $journeyId,
		public readonly DemoRunScope $scope,
	) {
	}//end __construct()
}//end class
