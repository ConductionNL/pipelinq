<?php

/**
 * Pipelinq ZgwRegisterAccess.
 *
 * Thin facade over OpenRegister's `ObjectService` for the four ZGW bridge
 * schemas (`zgwEndpoint`, `zgwClient`, `nrcAbonnement`, `zgwResourceMapping`).
 *
 * Centralising the OR access lets the rest of the bridge stay decoupled
 * from OR's method names + container lookup; the tests can substitute a
 * trivial in-memory stub by injecting an object with the same shape.
 *
 * OR API references (per the project memory entry "OR ObjectService real API"):
 *   - find($id, $register, $schema)
 *   - findAll($filters, $register, $schema)
 *   - saveObject($object, $extend, $register, $schema, $uuid)
 *
 * Resolution is lazy: the container lookup runs on the first call so the
 * bridge gracefully no-ops when OR is not available (tests / staging
 * without OR installed). Callers MUST handle the empty-result case.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Zgw
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OR ObjectService facade for the four ZGW schemas.
 */
class ZgwRegisterAccess {
	/**
	 * The pipelinq register slug shared by all four ZGW schemas.
	 */
	public const REGISTER = 'pipelinq';

	public const SCHEMA_ENDPOINT = 'zgwEndpoint';
	public const SCHEMA_CLIENT = 'zgwClient';
	public const SCHEMA_ABONN = 'nrcAbonnement';
	public const SCHEMA_MAPPING = 'zgwResourceMapping';

	/**
	 * Cached ObjectService instance (or null when OR is unavailable).
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * Has resolution been attempted? (separate flag so null is a real cached value)
	 *
	 * @var boolean
	 */
	private bool $resolved = false;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Service container (lazy OR lookup).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look up a single object by UUID or slug.
	 *
	 * @param string $schema Schema slug (one of the SCHEMA_* constants).
	 * @param string $id UUID or slug.
	 *
	 * @return array<string, mixed>|null Object data (array form) or null when missing.
	 *
	 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md
	 */
	public function find(string $schema, string $id): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$result = $objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info('ZGW: find failed', ['schema' => $schema, 'id' => $id, 'err' => $e->getMessage()]);
			return null;
		}

		return $this->toArray(candidate: $result);
	}//end find()

	/**
	 * List objects matching a filter map.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $filters Filter map (passed straight through to OR).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md
	 */
	public function findAll(string $schema, array $filters = []): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			// OpenRegister's ObjectService::findAll() takes a single $config array;
			// register/schema travel INSIDE $config['filters'] (see prepareFindAllConfig()).
			// The old findAll(register:, schema:, filters:) named-argument form no longer
			// exists and threw "Unknown named parameter $register" at runtime. The caller's
			// data-property $filters are merged with the register/schema context here.
			$rows = $objectService->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						[
							'register' => self::REGISTER,
							'schema' => $schema,
						]
					),
				]
			);
		} catch (Throwable $e) {
			$this->logger->info('ZGW: findAll failed', ['schema' => $schema, 'err' => $e->getMessage()]);
			return [];
		}//end try

		$out = [];
		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = $rows;
		}

		foreach ($rowList as $row) {
			$arr = $this->toArray(candidate: $row);
			if ($arr !== null) {
				$out[] = $arr;
			}
		}

		return $out;
	}//end findAll()

	/**
	 * Persist (create or update) an object.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $data Object payload (without `@self`).
	 * @param string|null $uuid Existing UUID to update, or null to create.
	 *
	 * @return array<string, mixed>|null Saved object (or null on failure).
	 *
	 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md
	 */
	public function save(string $schema, array $data, ?string $uuid = null): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		// Strip any stray @self envelope; OR expects a flat payload + (register, schema) args.
		if (array_key_exists('@self', $data) === true) {
			unset($data['@self']);
		}

		try {
			$saved = $objectService->saveObject(
				object: $data,
				extend: [],
				register: self::REGISTER,
				schema: $schema,
				uuid: $uuid
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ZGW: saveObject failed',
				['schema' => $schema, 'uuid' => $uuid, 'err' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(candidate: $saved);
	}//end save()

	/**
	 * Resolve a `ZgwClient` record for a given `ZgwEndpoint` payload.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload (must include clientId).
	 *
	 * @return array<string, mixed>|null
	 */
	public function findClientForEndpoint(array $endpoint): ?array {
		$clientId = (string)($endpoint['clientId'] ?? '');
		if ($clientId === '') {
			return null;
		}

		return $this->find(schema: self::SCHEMA_CLIENT, id: $clientId);
	}//end findClientForEndpoint()

	/**
	 * Resolve the OR ObjectService once and cache it.
	 *
	 * @return object|null
	 */
	private function getObjectService(): ?object {
		if ($this->resolved === true) {
			return $this->objectService;
		}

		$this->resolved = true;
		try {
			$this->objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->info('ZGW: OR ObjectService unavailable', ['err' => $e->getMessage()]);
			$this->objectService = null;
		}

		return $this->objectService;
	}//end getObjectService()

	/**
	 * Normalise an OR record to an associative array.
	 *
	 * OR may hand back an entity, an array, or a JSON-able object. We accept
	 * all three and convert to a plain array so the rest of the bridge code
	 * can treat the payload uniformly.
	 *
	 * @param mixed $candidate The OR-returned value.
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $candidate): ?array {
		if (is_array($candidate) === true) {
			return $candidate;
		}

		if (is_object($candidate) === true) {
			if (method_exists($candidate, 'jsonSerialize') === true) {
				$serialised = $candidate->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($candidate, 'toArray') === true) {
				$arr = $candidate->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$candidate;
		}

		return null;
	}//end toArray()
}//end class
