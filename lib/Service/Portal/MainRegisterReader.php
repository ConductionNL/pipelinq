<?php

/**
 * Pipelinq MainRegisterReader.
 *
 * Read-only access to the *main* pipelinq register (invoices/POS transactions,
 * requests, clients, contacts) for the portal's cross-domain read facades. The
 * portal never writes business data here except a portal-submitted request; all
 * reads inject the resolved register + schema and are filtered server-side by
 * the customer's own contact/client ids, so a portal user can never read an
 * object that is not theirs (ADR-005, ADR-022 — real OR API only).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Reads objects from the main pipelinq register.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Minimal collaborator set
 *  (OR container, app config, logger) a register reader needs.
 */
class MainRegisterReader {
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
	 * Whether a given main-register schema is configured on this instance.
	 *
	 * @param string $schemaKey The app-config schema key (e.g. posTransaction_schema).
	 *
	 * @return bool True when both register and schema are configured.
	 */
	public function hasSchema(string $schemaKey): bool {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		return $register !== '' && $schema !== '';
	}//end hasSchema()

	/**
	 * Find all objects of a main-register schema matching equality filters.
	 *
	 * The register and schema are always injected here, never from the caller's
	 * filters, so the read is constrained to the intended schema.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param array<string, mixed> $filters Extra equality filters.
	 *
	 * @return array<int, array<string, mixed>> The objects as arrays.
	 */
	public function findAll(string $schemaKey, array $filters = []): array {
		if ($this->hasSchema(schemaKey: $schemaKey) === false) {
			return [];
		}

		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$results = $this->objectService()->findAll(
				config: ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters)]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq portal: main-register findAll failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
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
	 * Find a single main-register object by id, or null.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	public function find(string $schemaKey, string $id): ?array {
		if ($id === '' || $this->hasSchema(schemaKey: $schemaKey) === false) {
			return null;
		}

		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$object = $this->objectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end find()

	/**
	 * Persist an object to a main-register schema. The portal only writes here
	 * for a customer-submitted request; the register and schema are injected so
	 * a caller can never redirect the write to another schema.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param array<string, mixed> $data The object payload.
	 * @param string|null $id The id for an update, or null.
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @throws RuntimeException When persistence fails.
	 */
	public function save(string $schemaKey, array $data, ?string $id = null): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);
		unset($data['@self']);

		try {
			$saved = $this->objectService()->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Pipelinq portal: main-register save failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist object.');
		}

		return $this->toArray(object: $saved);
	}//end save()

	/**
	 * Resolve [register, schema] for a schema key.
	 *
	 * @param string $schemaKey The app-config schema key.
	 *
	 * @return array{0: string, 1: string} The register and schema ids.
	 *
	 * @throws RuntimeException When unconfigured.
	 */
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException("Main register schema '{$schemaKey}' is not configured.");
		}

		return [$register, $schema];
	}//end config()

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
