<?php

/**
 * Pipelinq AbstractExportService.
 *
 * Shared OpenRegister access + config-resolution helpers for the BI export
 * services. Centralises register/schema resolution, the real OR ObjectService
 * API (find / findAll / saveObject / deleteObject), and small value helpers
 * (uuid / now / toArray) so each export service stays focused on its own
 * concern.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
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
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Base class for the BI export services.
 *
 * Resolves the pipelinq register and the export schema IDs from app config
 * (populated by the register import) and exposes the real OpenRegister
 * ObjectService — never the invented findObject/createFromArray helpers
 * (ADR-022). All reads/writes are scoped to this app's own register + schema,
 * so an id belonging to another app resolves to a 404 (IDOR-safe).
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
 */
abstract class AbstractExportService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param IAppConfig $appConfig The app config.
	 */
	public function __construct(
		protected ContainerInterface $container,
		protected IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the register + a schema config key into their stored IDs.
	 *
	 * @param string $schemaKey The app-config schema key (e.g. exportJob_schema).
	 *
	 * @return array{0: string, 1: string} The [register, schema] IDs.
	 *
	 * @throws OCSNotFoundException If the register or schema is not configured.
	 */
	protected function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Export register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Find a single object in this app's register/schema, or null.
	 *
	 * @param string $schemaKey The schema config key.
	 * @param string $id The object UUID.
	 *
	 * @return array<string, mixed>|null The object as an array, or null when absent.
	 */
	protected function findObjectById(string $schemaKey, string $id): ?array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end findObjectById()

	/**
	 * Find all objects in this app's register/schema matching optional filters.
	 *
	 * @param string $schemaKey The schema config key.
	 * @param array<string, mixed> $filters Extra field filters (merged with register/schema).
	 *
	 * @return array<int, array<string, mixed>> The matching objects as arrays.
	 */
	protected function findAllObjects(string $schemaKey, array $filters = []): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		$allFilters = array_merge(
			['register' => $register, 'schema' => $schema],
			$filters
		);

		try {
			$results = $this->getObjectService()->findAll(config: ['filters' => $allFilters]);
		} catch (\Throwable $e) {
			return [];
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}//end findAllObjects()

	/**
	 * Persist an object via the OR ObjectService (create or update by uuid).
	 *
	 * @param string $schemaKey The schema config key.
	 * @param array<string, mixed> $data The object data.
	 * @param string|null $id The UUID (null to create a new object).
	 *
	 * @return array<string, mixed> The saved object as an array.
	 */
	protected function saveObjectData(string $schemaKey, array $data, ?string $id = null): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		unset($data['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $data,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}//end saveObjectData()

	/**
	 * Delete an object by UUID from this app's register/schema.
	 *
	 * @param string $schemaKey The schema config key.
	 * @param string $id The object UUID.
	 *
	 * @return void
	 */
	protected function deleteObjectById(string $schemaKey, string $id): void {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		$this->getObjectService()->deleteObject(register: $register, schema: $schema, uuid: $id);
	}//end deleteObjectById()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 */
	protected function getObjectService(): object {
		try {
			return $this->objectService;
		} catch (\Throwable $e) {
			throw new RuntimeException('OpenRegister service is not available.');
		}
	}//end getObjectService()

	/**
	 * Normalise an OR object (entity or array) into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	protected function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}//end toArray()

	/**
	 * Current time as an ISO 8601 string.
	 *
	 * @return string The current timestamp.
	 */
	protected function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end now()

	/**
	 * Generate a v4 UUID.
	 *
	 * @return string The UUID.
	 */
	protected function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end uuid()
}//end class
