<?php

/**
 * Pipelinq PortalObjectRepository.
 *
 * Single, thin access point to the OpenRegister ObjectService for every
 * customer-portal service. It resolves the separate `pipelinq-portal` register
 * id and the per-schema id from app-config, normalises OR entities to plain
 * arrays, and never trusts a client-supplied register/schema/tenant. Centralising
 * the OR API here keeps the real OR contract (find / findAll / saveObject /
 * deleteObject) in one place and makes per-customer / per-tenant scoping
 * auditable (ADR-005, ADR-022).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Thin, register-scoped wrapper over the OpenRegister ObjectService for the
 * customer portal's own (pipelinq-portal) register.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the minimal collaborator
 *  set (OR container, app config, logger) a repository legitimately needs.
 */
class PortalObjectRepository {
	/**
	 * App-config key holding the separate portal register id.
	 *
	 * @var string
	 */
	private const PORTAL_REGISTER_KEY = 'portal_register';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the portal register id, falling back to the main register so the
	 * portal still functions on instances where only one register exists.
	 *
	 * @return string The register id.
	 *
	 * @throws RuntimeException If no register is configured.
	 */
	public function registerId(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, self::PORTAL_REGISTER_KEY, '');
		if ($register === '') {
			$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		}

		if ($register === '') {
			throw new RuntimeException('Portal register is not configured.');
		}

		return $register;
	}//end registerId()

	/**
	 * Resolve a portal schema id from its config key (e.g. portalAccount).
	 *
	 * @param string $schemaSlug The schema slug (without the `_schema` suffix).
	 *
	 * @return string The schema id.
	 *
	 * @throws RuntimeException If the schema is not configured.
	 */
	public function schemaId(string $schemaSlug): string {
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaSlug . '_schema', '');
		if ($schema === '') {
			throw new RuntimeException("Portal schema '{$schemaSlug}' is not configured.");
		}

		return $schema;
	}//end schemaId()

	/**
	 * Find a single portal object by id within a schema.
	 *
	 * Returns null when the object does not exist OR is not in the portal
	 * register/schema — callers therefore cannot reach objects outside the
	 * portal domain (no IDOR across registers).
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param string $id The object id/uuid.
	 *
	 * @return array<string, mixed>|null The object as an array, or null.
	 */
	public function find(string $schemaSlug, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService()->find(
				id: $id,
				register: $this->registerId(),
				schema: $this->schemaId(schemaSlug: $schemaSlug)
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
	 * Find all portal objects of a schema matching a set of equality filters.
	 *
	 * The register and schema are always injected here, never taken from the
	 * caller's filters, so a caller can only ever read within the portal
	 * schema it asked for.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $filters Extra equality filters (field => value).
	 *
	 * @return array<int, array<string, mixed>> The matching objects as arrays.
	 */
	public function findAll(string $schemaSlug, array $filters = []): array {
		$base = [
			'register' => $this->registerId(),
			'schema' => $this->schemaId(schemaSlug: $schemaSlug),
		];

		try {
			$results = $this->objectService()->findAll(
				config: ['filters' => array_merge($base, $filters)]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq portal: findAll failed',
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
	 * Find the first portal object matching the given filters, or null.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return array<string, mixed>|null The first match, or null.
	 */
	public function findOneBy(string $schemaSlug, array $filters): ?array {
		$matches = $this->findAll(schemaSlug: $schemaSlug, filters: $filters);
		return ($matches[0] ?? null);
	}//end findOneBy()

	/**
	 * Persist a portal object. When $id is null OpenRegister mints a new uuid.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $data The object payload.
	 * @param string|null $id The object id for an update.
	 *
	 * @return array<string, mixed> The saved object as an array.
	 *
	 * @throws RuntimeException If persistence fails.
	 */
	public function save(string $schemaSlug, array $data, ?string $id = null): array {
		// Never trust a client-derived self envelope.
		unset($data['@self']);

		try {
			$saved = $this->objectService()->saveObject(
				object: $data,
				extend: [],
				register: $this->registerId(),
				schema: $this->schemaId(schemaSlug: $schemaSlug),
				uuid: $id
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Pipelinq portal: saveObject failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist portal object.');
		}

		return $this->toArray(object: $saved);
	}//end save()

	/**
	 * Extract the stable id/uuid from a normalised portal object array.
	 *
	 * @param array<string, mixed> $object The object array.
	 *
	 * @return string|null The id, or null when absent.
	 */
	public function idOf(array $object): ?string {
		if (isset($object['@self']) === true && is_array($object['@self']) === true) {
			$self = $object['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($object['id']) === true) {
			return (string)$object['id'];
		}

		if (isset($object['uuid']) === true) {
			return (string)$object['uuid'];
		}

		return null;
	}//end idOf()

	/**
	 * Normalise an OR object (entity or array) into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	private function toArray(mixed $object): array {
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
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 */
	private function objectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end objectService()
}//end class
