<?php

/**
 * Pipelinq DsarMigrationSummary.
 *
 * The outcome buckets of one AVG to DSAR migration run.
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
 * How many source objects ended in each outcome bucket, of how many seen.
 *
 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017-migration-of-existing-avgverzoek-data-to-or
 */
final class DsarMigrationSummary {
	/**
	 * Constructor.
	 *
	 * @param array<string, int> $counts Outcome buckets.
	 * @param int                $total  Source objects seen.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $counts,
		public readonly int $total,
	) {
	}//end __construct()

	/**
	 * The run summary line.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017-migration-of-existing-avgverzoek-data-to-or
	 */
	public function line(): string {
		return sprintf(
			'Pipelinq AVG→DSAR migration: %d migrated, %d skipped (already migrated or trashed),'
			. ' %d unmappable, %d failed (of %d source objects).',
			$this->counts['migrated'],
			$this->counts['skipped'],
			$this->counts['unmappable'],
			$this->counts['failed'],
			$this->total,
		);
	}//end line()
}//end class
