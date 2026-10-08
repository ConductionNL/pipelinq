<?php

/**
 * Pipelinq MigrateConsentToIntegriq repair step.
 *
 * Moves pipelinq's consent records into integriq's opt-out table on upgrade.
 * Without integriq it logs and does nothing; a later upgrade, or
 * `occ pipelinq:consent:migrate`, retries.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\ConsentMigrationService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the consent migration once per upgrade; it is idempotent.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
 */
class MigrateConsentToIntegriq implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ConsentMigrationService $migration The migration.
	 * @param LoggerInterface         $logger    Logs a failed run.
	 */
	public function __construct(
		private readonly ConsentMigrationService $migration,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function getName(): string {
		return 'Move pipelinq consent records into integriq (opt-out-before-send)';
	}//end getName()

	/**
	 * Run the migration and report the counts.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function run(IOutput $output): void {
		try {
			$counts = $this->migration->run();
		} catch (Throwable $e) {
			// Never fatal for the upgrade: the flag stays pipelinq and the next run retries.
			$this->logger->error('MigrateConsentToIntegriq failed: '.$e->getMessage(), ['exception' => $e]);
			$output->warning('Consent migration failed; consent.store stays pipelinq: '.$e->getMessage());
			return;
		}

		$output->info(
			sprintf(
				'Consent migration %s: %d migrated, %d bounces kept in pipelinq, %d without an address, %d refused; consent.store=%s',
				$counts['status'],
				$counts['migrated'],
				$counts['skippedBounce'],
				$counts['skippedNoAddress'],
				$counts['refused'],
				$counts['store']
			)
		);
	}//end run()
}//end class
