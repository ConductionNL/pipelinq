<?php

/**
 * Pipelinq MdmObjectRepository.
 *
 * Thin, request-scoped access layer over the OpenRegister ObjectService for the
 * Master Data Management (MDM) schemas. Centralises register/schema id
 * resolution, the real OR API calls (find / findAll / saveObject /
 * deleteObject) and the UUID / timestamp helpers so the MDM services stay
 * focused on golden-record, dedup, merge and sync logic.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Repository helper for MDM OpenRegister objects.
 *
 * Only the real OpenRegister ObjectService API is used: `find`, `findAll`,
 * `saveObject`, `deleteObject` (ADR-022). No invented `findObject` /
 * `createFromArray` helpers. This is a deliberately cohesive OR-access facade —
 * register/schema resolution, the four ObjectService CRUD wrappers, the re-homed
 * masterEntity/sourceRecord read helpers and the uuid/now/toArray utilities are
 * one thin persistence surface, hence the TooManyPublicMethods suppression.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-001
 */
class MdmObjectRepository {
	/**
	 * App-config key holding the Pipelinq register id.
	 *
	 * @var string
	 */
	private const REGISTER_KEY = 'register';

	/**
	 * The masterEntity schema slug (re-homed from the retired MasterEntityService).
	 *
	 * @var string
	 */
	public const SCHEMA_MASTER_ENTITY = 'masterEntity';

	/**
	 * The sourceRecord schema slug (re-homed from the retired MasterEntityService).
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE_RECORD = 'sourceRecord';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config (register/schema ids).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the configured register id.
	 *
	 * @return string The register id.
	 *
	 * @throws RuntimeException If the register is not configured.
	 *
	 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-001
	 */
	public function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, self::REGISTER_KEY, '');
		if ($register === '') {
			throw new RuntimeException('Pipelinq register is not configured.');
		}

		return $register;
	}//end register()

	/**
	 * Resolve a schema id from its config key (e.g. `masterEntity_schema`).
	 *
	 * @param string $schemaSlug The schema slug (e.g. `masterEntity`).
	 *
	 * @return string The schema id.
	 *
	 * @throws RuntimeException If the schema is not configured.
	 */
	public function schema(string $schemaSlug): string {
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaSlug . '_schema', '');
		if ($schema === '') {
			throw new RuntimeException("MDM schema '{$schemaSlug}' is not configured.");
		}

		return $schema;
	}//end schema()

	/**
	 * Find a single object by uuid within a schema.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param string $id The object uuid.
	 *
	 * @return array<string, mixed>|null The object as array, or null if absent.
	 */
	public function find(string $schemaSlug, string $id): ?array {
		try {
			$object = $this->objectService()->find(
				id: $id,
				register: $this->register(),
				schema: $this->schema(schemaSlug: $schemaSlug)
			);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end find()

	/**
	 * Find all objects in a schema, optionally filtered.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $filters Extra equality filters.
	 *
	 * @return array<int, array<string, mixed>> The matching objects.
	 */
	public function findAll(string $schemaSlug, array $filters = []): array {
		$baseFilters = [
			'register' => $this->register(),
			'schema' => $this->schema(schemaSlug: $schemaSlug),
		];

		try {
			$results = $this->objectService()->findAll(
				config: ['filters' => array_merge($baseFilters, $filters)]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq MDM: findAll failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}//end findAll()

	/**
	 * Persist an object via the OR ObjectService.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $object The object data.
	 * @param string|null $uuid Optional uuid to write to.
	 *
	 * @return array<string, mixed> The saved object as array.
	 */
	public function save(string $schemaSlug, array $object, ?string $uuid = null): array {
		unset($object['@self']);

		$saved = $this->objectService()->saveObject(
			object: $object,
			extend: [],
			register: $this->register(),
			schema: $this->schema(schemaSlug: $schemaSlug),
			uuid: $uuid
		);

		return $this->toArray(object: $saved);
	}//end save()

	/**
	 * Delete an object by uuid.
	 *
	 * @param string $schemaSlug The schema slug (resolved for consistency).
	 * @param string $id The object uuid.
	 *
	 * @return void
	 */
	public function delete(string $schemaSlug, string $id): void {
		// Resolve to validate the schema is configured before mutating.
		$this->schema(schemaSlug: $schemaSlug);
		$this->objectService()->deleteObject($id);
	}//end delete()

	/**
	 * Fetch a Master Entity by uuid, reading OpenRegister's materialised object.
	 *
	 * Re-homed from the retired MasterEntityService: OpenRegister's
	 * SurvivorshipRecomputeListener now materialises goldenRecord /
	 * attributeProvenance on save, so consumers (AVG, the downstream read API,
	 * the OR-mirror sync) read the golden record straight off the object.
	 *
	 * @param string $masterId The master entity uuid.
	 *
	 * @return array<string, mixed>|null The entity, or null if absent.
	 *
	 * @spec openspec/specs/master-data-management/spec.md
	 */
	public function findMasterEntity(string $masterId): ?array {
		return $this->find(schemaSlug: self::SCHEMA_MASTER_ENTITY, id: $masterId);
	}//end findMasterEntity()

	/**
	 * List Master Entities, optionally filtered by entityType and status.
	 *
	 * @param string|null $entityType Optional entity-type filter.
	 * @param string|null $status Optional status filter.
	 *
	 * @return array<int, array<string, mixed>> The matching entities.
	 *
	 * @spec openspec/specs/master-data-management/spec.md
	 */
	public function findMasterEntities(?string $entityType = null, ?string $status = null): array {
		$filters = [];
		if ($entityType !== null && $entityType !== '') {
			$filters['entityType'] = $entityType;
		}

		if ($status !== null && $status !== '') {
			$filters['status'] = $status;
		}

		return $this->findAll(schemaSlug: self::SCHEMA_MASTER_ENTITY, filters: $filters);
	}//end findMasterEntities()

	/**
	 * Fetch all source records currently linked to a Master Entity.
	 *
	 * @param string $masterId The master entity uuid.
	 *
	 * @return array<int, array<string, mixed>> The linked source records.
	 *
	 * @spec openspec/specs/master-data-management/spec.md
	 */
	public function linkedSourceRecords(string $masterId): array {
		return $this->findAll(
			schemaSlug: self::SCHEMA_SOURCE_RECORD,
			filters: ['currentMasterEntity' => $masterId]
		);
	}//end linkedSourceRecords()

	/**
	 * Generate an RFC-4122 v4 UUID.
	 *
	 * @return string The UUID.
	 */
	public function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end uuid()

	/**
	 * Current UTC timestamp in ISO 8601.
	 *
	 * @return string The timestamp.
	 */
	public function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}//end now()

	/**
	 * Normalise an OR object (entity or array) to a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The array form.
	 */
	public function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}//end toArray()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 */
	private function objectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end objectService()
}//end class
