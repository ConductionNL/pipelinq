<?php

/**
 * Pipelinq DsarMigrationRun.
 *
 * One run of the AVG to DSAR migration: its source, satellites and already-migrated ids.
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
 * What every migrated avgVerzoek of one run shares.
 *
 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017-migration-of-existing-avgverzoek-data-to-or
 */
final class DsarMigrationRun {
	/**
	 * Constructor.
	 *
	 * @param DsarMigrationSource  $source     Where the run reads from.
	 * @param array<string, mixed> $satellites The indexed satellite store.
	 * @param array<string, bool>  $migrated   Source uuids that already have a case.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly DsarMigrationSource $source,
		public readonly array $satellites,
		public readonly array $migrated,
	) {
	}//end __construct()
}//end class
