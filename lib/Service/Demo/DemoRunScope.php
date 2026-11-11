<?php

/**
 * Pipelinq DemoRunScope.
 *
 * What every seeded journey run of one seed shares: the seeded uuids its references resolve against and the run schema.
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
 * The uuid map and run schema of one demo journey seed.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
final class DemoRunScope {
	/**
	 * Constructor.
	 *
	 * @param array<string, string> $uuids     Section-local keys mapped to seeded uuids.
	 * @param string                $runSchema The journey run schema.
	 */
	public function __construct(
		public readonly array $uuids,
		public readonly string $runSchema,
	) {
	}//end __construct()
}//end class
