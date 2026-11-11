<?php

/**
 * Pipelinq DsarSatelliteSchema.
 *
 * A satellite schema of avgVerzoek and the field that holds its parent.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
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
 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017-migration-of-existing-avgverzoek-data-to-or
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

/**
 * The schema config key of a satellite and the field holding its parent id.
 *
 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017-migration-of-existing-avgverzoek-data-to-or
 */
final class DsarSatelliteSchema {
	/**
	 * Constructor.
	 *
	 * @param string $schemaKey   The satellite schema config key.
	 * @param string $parentField The field holding the parent id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $schemaKey,
		public readonly string $parentField,
	) {
	}//end __construct()
}//end class
