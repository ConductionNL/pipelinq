<?php

/**
 * Pipelinq Adapter Registrar
 *
 * @category  Adapter
 * @package   OCA\Pipelinq\Adapter
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Adapter;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the export sink registry with every sink adapter.
 *
 * @category Adapter
 * @package  OCA\Pipelinq\Adapter
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.nl
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class AdapterRegistrar {
	/**
	 * Register the BI-export sink registry.
	 *
	 * The {@see ExportSinkRegistry} is wired with every concrete sink adapter
	 * so {@see \OCA\Pipelinq\Service\Export\ExportUploadService} stays decoupled
	 * from the warehouse transports.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			ExportSinkRegistry::class,
			static function ($c): ExportSinkRegistry {
				return new ExportSinkRegistry(
					sinks: [
						$c->get(S3ExportAdapter::class),
						$c->get(BigQueryExportAdapter::class),
						$c->get(SnowflakeExportAdapter::class),
						$c->get(PostgresExportAdapter::class),
						$c->get(AzureDataLakeExportAdapter::class),
						$c->get(GcsExportAdapter::class),
						$c->get(SftpExportAdapter::class),
					]
				);
			}
		);
	}//end register()
}//end class
