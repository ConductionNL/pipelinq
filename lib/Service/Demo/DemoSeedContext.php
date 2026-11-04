<?php

/**
 * Pipelinq DemoSeedContext.
 *
 * Resolves what a demo seed or removal needs: register, schemas, store and definitions.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The OpenRegister ObjectService, the register id, the schema ids and the
 * shipped seed definitions (lib/Settings/demo_seed_data.json). Null when the
 * register or a schema is not provisioned, the seed file is unreadable, or
 * OpenRegister is unavailable.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoSeedContext {
	/**
	 * App-config key holding the unified ticket schema id.
	 *
	 * @var string
	 */
	public const TICKET_SCHEMA_KEY = 'ticket_schema';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig     App config (register/schema ids).
	 * @param ContainerInterface $container     Container for the OpenRegister ObjectService.
	 * @param TicketService      $ticketService Resolves the unified ticket schema.
	 * @param LoggerInterface    $logger        Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly TicketService $ticketService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the store, register, schema ids and raw seed definitions.
	 *
	 * @param array<int, string> $schemaKeys The schema config keys, in seed order.
	 *
	 * @return array{0: object, 1: string, 2: array<string, string>, 3: array<string, mixed>}|null
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function resolve(array $schemaKeys): ?array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaIds  = null;
		if ($registerId !== '') {
			$schemaIds = $this->schemaIds(schemaKeys: $schemaKeys);
		}

		if ($schemaIds === null) {
			return null;
		}

		return $this->withStore(ids: [$registerId, $schemaIds]);
	}//end resolve()

	/**
	 * The resolved ids with the seed definitions and the store, or null when
	 * either is unavailable.
	 *
	 * @param array{0: string, 1: array<string, string>} $ids The register id and the schema ids.
	 *
	 * @return array{0: object, 1: string, 2: array<string, string>, 3: array<string, mixed>}|null
	 */
	private function withStore(array $ids): ?array {
		$definitions = $this->loadDefinitions();
		if ($definitions === null) {
			return null;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		return [$objectService, $ids[0], $ids[1], $definitions];
	}//end withStore()

	/**
	 * The schema id per config key, or null when any of them is not provisioned.
	 *
	 * @param array<int, string> $schemaKeys The schema config keys.
	 *
	 * @return array<string, string>|null
	 */
	private function schemaIds(array $schemaKeys): ?array {
		$schemaIds = [];
		foreach ($schemaKeys as $schemaKey) {
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
			if ($schemaKey === self::TICKET_SCHEMA_KEY) {
				// The ticket schema has one resolution point (TicketService).
				$schemaId = $this->ticketService->getSchemaId();
			}

			if ($schemaId === '') {
				return null;
			}

			$schemaIds[$schemaKey] = $schemaId;
		}

		return $schemaIds;
	}//end schemaIds()

	/**
	 * Load and decode lib/Settings/demo_seed_data.json.
	 *
	 * @return array<string, mixed>|null Null when missing or invalid. Most sections are
	 *                                   lists of definitions; `social` and `search` are maps.
	 */
	private function loadDefinitions(): ?array {
		$path = dirname(__DIR__, 2) . '/Settings/demo_seed_data.json';
		if (is_readable($path) === false) {
			$this->logger->error('Pipelinq demo seed: demo_seed_data.json missing', ['path' => $path]);
			return null;
		}

		$decoded = json_decode((string) file_get_contents($path), true);
		if (is_array($decoded) === false || $decoded === []) {
			$this->logger->error('Pipelinq demo seed: demo_seed_data.json invalid JSON', ['path' => $path]);
			return null;
		}

		return $decoded;
	}//end loadDefinitions()

	/**
	 * The OpenRegister ObjectService, or null (logged) when it is unavailable.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq demo seed: OpenRegister ObjectService unavailable', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end objectService()
}//end class
