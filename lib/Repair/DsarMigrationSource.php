<?php

/**
 * Pipelinq DsarMigrationSource.
 *
 * Where the AVG to DSAR migration reads its avgVerzoek objects from.
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
 * The ObjectService, the pipelinq register and the avgVerzoek schema one
 * migration run reads from.
 *
 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017-migration-of-existing-avgverzoek-data-to-or
 */
final class DsarMigrationSource {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $registerId    The pipelinq register id.
	 * @param string $requestSchema The avgVerzoek schema id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $registerId,
		public readonly string $requestSchema,
	) {
	}//end __construct()
}//end class
