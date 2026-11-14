<?php

/**
 * Pipelinq ConfiguredSchemaRows.
 *
 * Every object of one of this app's configured schemas, for the POS jobs.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Reads every object of the schema an app-config key names, in this app's register.
 *
 * Built by the job that reads; a failure is thrown to that job, which logs it
 * in its own words and carries on with nothing.
 *
 * @spec openspec/changes/archive/2026-06-14-pos-end-of-day-bookkeeping-post/tasks.md#3.1
 */
final class ConfiguredSchemaRows {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (for OR ObjectService).
	 * @param IAppConfig         $appConfig The app config.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every object of the configured schema, or [] when the register or schema is not configured.
	 *
	 * @param string $schemaKey The app-config key holding the schema id.
	 *
	 * @return mixed The rows as OpenRegister returns them.
	 *
	 * @throws \Throwable When OpenRegister cannot be reached or read.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-end-of-day-bookkeeping-post/tasks.md#3.1
	 */
	public function rows(string $schemaKey): mixed {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return [];
		}

		return $objectService->findAll(
			config: [
				'filters' => [
					'register' => $register,
					'schema' => $schema,
				],
			]
		);
	}//end rows()
}//end class
