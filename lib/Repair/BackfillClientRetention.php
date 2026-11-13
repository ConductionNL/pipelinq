<?php

/**
 * Pipelinq BackfillClientRetention.
 *
 * Repair step that gives existing clients and contact persons the retention
 * metadata OpenRegister writes on create.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\RetentionMetadataBackfillService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Repair step: retention metadata for clients and contact persons made before
 * their schemas declared a retention term.
 *
 * Idempotent: a record that already has a nomination is not written, so a
 * second run writes nothing. Without OpenRegister's retention service it
 * writes nothing and says so.
 *
 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
 */
class BackfillClientRetention implements IRepairStep {

	/**
	 * App config keys of the schemas whose records are back-filled.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMA_KEYS = ['client_schema', 'contact_schema'];

	/**
	 * Constructor.
	 *
	 * @param RetentionMetadataBackfillService $service Does the work.
	 */
	public function __construct(
		private readonly RetentionMetadataBackfillService $service,
	) {
	}//end __construct()

	/**
	 * Name of the repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
	 */
	public function getName(): string {
		return 'Give existing clients and contact persons their retention term';
	}//end getName()

	/**
	 * Run the back-fill and report what it did.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
	 */
	public function run(IOutput $output): void {
		$result = $this->service->backfill(schemaKeys: self::SCHEMA_KEYS);

		if ($result['status'] === 'not-configured') {
			$output->warning('BackfillClientRetention: nothing back-filled, because the client and contact schemas are not configured yet.');
			return;
		}

		if ($result['status'] === 'openregister-unavailable') {
			$output->warning('BackfillClientRetention: nothing back-filled, because OpenRegister\'s retention service is not available.');
			return;
		}

		$output->info(
			sprintf(
				'BackfillClientRetention: %d record(s) given retention metadata, %d left as they were, %d could not be written.',
				$result['applied'],
				$result['skipped'],
				$result['failed']
			)
		);
	}//end run()
}//end class
