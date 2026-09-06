<?php

/**
 * Pipelinq ExportDestinationService.
 *
 * CRUD + validation for export destinations. Each destination references an
 * OpenConnector source for credentials (ADR-005 — credentials are never stored
 * on or returned from the destination object). Creation/test attempts a
 * connectivity probe through the type-specific sink adapter and records the
 * validation status.
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
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Destination configuration + connectivity validation.
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
 */
class ExportDestinationService extends AbstractExportService {
	/**
	 * The exportDestination schema config key.
	 *
	 * @var string
	 */
	private const SCHEMA_KEY = 'exportDestination_schema';

	/**
	 * Supported destination types.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = [
		's3',
		'azure_data_lake',
		'gcs',
		'bigquery',
		'snowflake',
		'sftp',
		'postgres',
	];

	/**
	 * OpenConnector's own OpenRegister register slug. Source objects
	 * (formerly served by the now-removed `SourceService`) live here, not
	 * in pipelinq's own `register` app-config register.
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_REGISTER_SLUG = 'openconnector';

	/**
	 * OpenConnector's Source schema slug within {@see OPENCONNECTOR_REGISTER_SLUG}.
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_SOURCE_SCHEMA_SLUG = 'source';

	/**
	 * Legacy write-only credential fields still present on some Source
	 * objects (`configuration.authentication.credentialRef`-based sources
	 * resolve their secret through OpenConnector's own credential broker at
	 * call time and are not extractable here — ADR-005).
	 *
	 * @var array<int, string>
	 */
	private const OPENCONNECTOR_LEGACY_SECRET_FIELDS = ['apikey', 'secret', 'password', 'jwt'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param IAppConfig $appConfig The app config.
	 * @param ObjectServiceInterface $objectService The published OpenRegister contract.
	 * @param ExportSinkRegistry $sinks The sink adapter registry.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ContainerInterface $container,
		IAppConfig $appConfig,
		ObjectServiceInterface $objectService,
		private ExportSinkRegistry $sinks,
		private LoggerInterface $logger,
	) {
		parent::__construct(
			container: $container,
			appConfig: $appConfig,
			objectService: $objectService
		);
	}//end __construct()

	/**
	 * List all destinations (credentials never included — they live in OC).
	 *
	 * @return array<int, array<string, mixed>> The destinations.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function listDestinations(): array {
		return $this->findAllObjects(schemaKey: self::SCHEMA_KEY);
	}//end listDestinations()

	/**
	 * Get a destination by id.
	 *
	 * @param string $id The destination UUID.
	 *
	 * @return array<string, mixed> The destination.
	 *
	 * @throws OCSNotFoundException When absent.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function getDestination(string $id): array {
		$destination = $this->findObjectById(schemaKey: self::SCHEMA_KEY, id: $id);
		if ($destination === null) {
			throw new OCSNotFoundException('Export destination not found.');
		}

		return $destination;
	}//end getDestination()

	/**
	 * Create a destination, validate the OC source reference, and probe it.
	 *
	 * @param array<string, mixed> $data The destination config.
	 *
	 * @return array<string, mixed> The created destination.
	 *
	 * @throws OCSBadRequestException On invalid type or missing required fields.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function createDestination(array $data): array {
		$this->validate(data: $data);

		$data = $this->sanitize(data: $data);
		$data['validationStatus'] = 'untested';

		$created = $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $data, id: null);

		// Best-effort connectivity probe at creation; never blocks the save.
		$id = (string)($created['id'] ?? $created['uuid'] ?? '');
		if ($id !== '') {
			$this->testConnection(id: $id);
			return $this->getDestination(id: $id);
		}

		return $created;
	}//end createDestination()

	/**
	 * Update a destination (re-validates and re-probes).
	 *
	 * @param string $id The destination UUID.
	 * @param array<string, mixed> $data The new config.
	 *
	 * @return array<string, mixed> The updated destination.
	 *
	 * @throws OCSBadRequestException On invalid input.
	 * @throws OCSNotFoundException When absent.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function updateDestination(string $id, array $data): array {
		$existing = $this->getDestination(id: $id);
		$merged = array_merge($existing, $this->sanitize(data: $data));
		$this->validate(data: $merged);

		$merged['validationStatus'] = 'untested';
		$this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $merged, id: $id);

		$this->testConnection(id: $id);
		return $this->getDestination(id: $id);
	}//end updateDestination()

	/**
	 * Delete a destination.
	 *
	 * @param string $id The destination UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException When absent.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function deleteDestination(string $id): void {
		$this->getDestination(id: $id);
		$this->deleteObjectById(schemaKey: self::SCHEMA_KEY, id: $id);
	}//end deleteDestination()

	/**
	 * Test connectivity to a destination via its sink adapter.
	 *
	 * Resolves credentials from the referenced OC source, probes the sink, and
	 * records validationStatus + lastValidatedAt. Never throws on a failed
	 * probe (returns false); only a missing destination throws.
	 *
	 * @param string $id The destination UUID.
	 *
	 * @return bool True when the destination is reachable.
	 *
	 * @throws OCSNotFoundException When the destination is absent.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function testConnection(string $id): bool {
		$destination = $this->getDestination(id: $id);
		$type = (string)($destination['type'] ?? '');

		$valid = false;
		if ($this->sinks->supports(type: $type) === true) {
			$credentials = $this->resolveCredentials(destination: $destination);
			$valid = $this->sinks->get(type: $type)->testConnection($credentials, $destination);
		}

		$validationStatus = 'invalid';
		if ($valid === true) {
			$validationStatus = 'valid';
		}

		$destination['validationStatus'] = $validationStatus;
		$destination['lastValidatedAt'] = $this->now();
		$this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $destination, id: $id);

		return $valid;
	}//end testConnection()

	/**
	 * Whether a destination is currently valid (gate for job enablement).
	 *
	 * @param string $id The destination UUID.
	 *
	 * @return bool True when validationStatus is "valid".
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-001
	 */
	public function isValid(string $id): bool {
		try {
			$destination = $this->getDestination(id: $id);
		} catch (\Throwable $e) {
			return false;
		}

		return (string)($destination['validationStatus'] ?? '') === 'valid';
	}//end isValid()

	/**
	 * Validate destination input.
	 *
	 * @param array<string, mixed> $data The config.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException On invalid input.
	 */
	private function validate(array $data): void {
		if (trim((string)($data['name'] ?? '')) === '') {
			throw new OCSBadRequestException('Destination name is required.');
		}

		$type = (string)($data['type'] ?? '');
		if (in_array($type, self::TYPES, true) === false) {
			throw new OCSBadRequestException("Unsupported destination type '{$type}'.");
		}

		if (trim((string)($data['connectorSourceId'] ?? '')) === '') {
			throw new OCSBadRequestException('An OpenConnector source is required for credentials.');
		}

		if (trim((string)($data['pathTemplate'] ?? '')) === '') {
			throw new OCSBadRequestException('A path template is required.');
		}
	}//end validate()

	/**
	 * Strip never-trusted fields and normalise the destination payload.
	 *
	 * Defensively drops any client-supplied secret-bearing keys so credentials
	 * cannot be persisted onto the destination object (they belong in OC).
	 *
	 * @param array<string, mixed> $data The raw input.
	 *
	 * @return array<string, mixed> The sanitised payload.
	 */
	private function sanitize(array $data): array {
		// 🔴 AN ALLOWLIST, BECAUSE A DENYLIST CANNOT WIN THIS.
		//
		// This used to unset a fixed list of credential-shaped names
		// (password, secret, accessKey, secretKey, credentials, privateKey)
		// and pass everything else through. It therefore STORED, in plaintext
		// on the destination object, every credential whose name nobody had
		// thought of: `token`, `apiKey`, `connectionString`, `sasToken` and
		// `clientSecret` all reached the stored record.
		//
		// That is the losing shape for this problem. A destination is
		// administrator-supplied config posted straight from a form, the set of
		// names a connector might use for its secret is open-ended, and every
		// new integration is another chance to miss one. Naming what MAY be
		// stored inverts that: an unknown key is dropped rather than kept, and
		// the failure mode of forgetting to update this list is a missing
		// setting, not a leaked credential.
		//
		// The list is the `exportDestination` schema's own properties
		// (40-bi-export.json). Credentials belong in the connector Source that
		// `connectorSourceId` points at, which is where resolveCredentials()
		// below reads them from, and where a rendered read already strips them.
		$allowed = [
			'name',
			'type',
			'connectorSourceId',
			'pathTemplate',
			'compression',
			'encryptionEnabled',
			'namingConvention',
			'validationStatus',
			'lastValidatedAt',
		];

		return array_intersect_key($data, array_flip($allowed));
	}//end sanitize()

	/**
	 * Resolve OC credentials for a destination (never returned to clients).
	 *
	 * `OCA\OpenConnector\Service\SourceService` — the class this used to
	 * resolve credentials through — no longer exists; Source objects moved
	 * onto OpenRegister's generic object API (register `openconnector`,
	 * schema `source`). A RENDERED read strips every write-only secret field
	 * unconditionally (admins included); only `_render: false` survives that
	 * boundary — the same raw re-read OpenConnector's own CallService /
	 * RawSourceResolver use internally (ocon#242). `_rbac`/`_multitenancy`
	 * stay true, so this is access-neutral, not a widened read.
	 *
	 * @param array<string, mixed> $destination The destination config.
	 *
	 * @return array<string, mixed> The credentials (empty when unavailable).
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-010
	 */
	private function resolveCredentials(array $destination): array {
		$sourceId = (string)($destination['connectorSourceId'] ?? '');
		if ($sourceId === '') {
			return [];
		}

		try {
			$source = $this->getObjectService()->find(
				id: $sourceId,
				register: self::OPENCONNECTOR_REGISTER_SLUG,
				schema: self::OPENCONNECTOR_SOURCE_SCHEMA_SLUG,
				_rbac: true,
				_multitenancy: true,
				_render: false,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: export destination credential resolution failed',
				['sourceId' => $sourceId, 'error' => $e->getMessage()]
			);
			return [];
		}

		if ($source === null) {
			return [];
		}

		return $this->extractSourceCredentials(source: $this->toArray(object: $source));
	}//end resolveCredentials()

	/**
	 * Extract the legacy write-only credential fields (and any non-secret
	 * broker authentication config) from a raw OpenConnector Source.
	 *
	 * @param array<string, mixed> $source The raw (unrendered) Source object.
	 *
	 * @return array<string, mixed> The extracted credentials.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-010
	 */
	private function extractSourceCredentials(array $source): array {
		$credentials = [];
		foreach (self::OPENCONNECTOR_LEGACY_SECRET_FIELDS as $field) {
			$value = $source[$field] ?? null;
			if (is_string($value) === true && $value !== '') {
				$credentials[$field] = $value;
			}
		}

		$authentication = $source['configuration']['authentication'] ?? null;
		if (is_array($authentication) === true && $authentication !== []) {
			$credentials['authentication'] = $authentication;
		}

		return $credentials;
	}//end extractSourceCredentials()
}//end class
