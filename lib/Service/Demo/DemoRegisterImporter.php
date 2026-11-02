<?php

/**
 * Pipelinq DemoRegisterImporter.
 *
 * Imports `lib/Settings/pipelinq_example_register.json` on request and removes
 * it again. These are the example records (point of sale, bookings, marketing,
 * ZGW bridge, forecasts and the original sample clients, leads and tickets)
 * that used to sit in the register descriptor itself, where OpenRegister
 * imported them on every install, upgrade and provisioning run whatever the
 * operator picked in the setup wizard.
 *
 * 🔴 ON DEMAND ONLY, NEVER ON INSTALL. The descriptor is `x-openregister.type:
 * mock`, which OpenRegister never imports by itself, and it is imported under
 * its own configuration identity (`pipelinq.demo`) so its version gate cannot
 * mask, or be masked by, the real register import.
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
 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\OpenRegister\Service\ConfigurationService;
use OCA\Pipelinq\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Imports and removes the example records descriptor.
 *
 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoRegisterImporter {
	/**
	 * Configuration identity for the example import, never the app id.
	 *
	 * @var string
	 */
	public const CONFIG_APP_ID = Application::APP_ID . '.demo';

	/**
	 * Constructor.
	 *
	 * OpenRegister's services are injected, not looked up (ADR-083): pipelinq
	 * requires OpenRegister, and only the setup and occ paths construct this class.
	 *
	 * @param DemoRecordsDescriptor $descriptor           Reads the example records descriptor.
	 * @param ConfigurationService  $configurationService Imports the descriptor and removes recorded imports.
	 * @param DemoRecordSweeper     $sweeper              Removes stored records that still match the descriptor.
	 * @param LoggerInterface       $logger               Records what was imported or removed.
	 */
	public function __construct(
		private readonly DemoRecordsDescriptor $descriptor,
		private readonly ConfigurationService $configurationService,
		private readonly DemoRecordSweeper $sweeper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Import the example records descriptor.
	 *
	 * @return array{imported: int, skipped: int} How many records arrived and how many did not.
	 *
	 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function import(): array {
		$data = $this->descriptor->forImport();
		if ($data === null) {
			return ['imported' => 0, 'skipped' => 0];
		}

		$version = $this->descriptor->appVersion();
		$result  = $this->configurationService->importFromApp(appId: self::CONFIG_APP_ID, data: $data, version: $version, force: true);
		$skipped = (array) ($result['skipped'] ?? []);
		$summary = [
			'imported' => count((array) ($result['objects'] ?? [])),
			'skipped'  => (int) ($skipped['objects'] ?? 0) + (int) ($skipped['seedObjects'] ?? 0),
		];

		$this->logger->info('Pipelinq example records imported', $summary);
		return $summary;
	}//end import()

	/**
	 * Remove every record the descriptor describes, wherever it was imported from.
	 *
	 * Two sources are covered. Imports made through {@see self::import()} are
	 * recorded by OpenRegister and soft-deleted by job. Installs that received
	 * the records from the old register descriptor have no job, so those are
	 * found by schema and slug, and only removed when their name still matches
	 * the descriptor: a record somebody has since renamed is theirs now.
	 *
	 * @return array{removed: int, retained: int}
	 *
	 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function remove(): array {
		$summary = ['removed' => $this->softDeleteImports(), 'retained' => 0];
		$data    = $this->descriptor->load();
		if ($data === null) {
			return $summary;
		}

		$found = $this->sweeper->removeMatching(objects: $data['components']['objects']);
		if ($found === null) {
			return $summary;
		}

		$summary = ['removed' => $summary['removed'] + $found['removed'], 'retained' => $found['retained']];
		$this->logger->info('Pipelinq example records removed', $summary);
		return $summary;
	}//end remove()

	/**
	 * Soft-delete the imports OpenRegister recorded, on an OpenRegister that can.
	 *
	 * @return int How many records were soft-deleted.
	 */
	private function softDeleteImports(): int {
		if (method_exists($this->configurationService, 'softDeleteAppImports') === false) {
			return 0;
		}

		$jobs = $this->configurationService->softDeleteAppImports(self::CONFIG_APP_ID);
		return (int) ($jobs['softDeleted'] ?? 0);
	}//end softDeleteImports()
}//end class
