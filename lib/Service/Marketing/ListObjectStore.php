<?php

/**
 * Pipelinq ListObjectStore.
 *
 * The register-scoped object plumbing the mailing-list services share:
 * resolve the register and the schema, call OpenRegister with the right
 * access flags, normalise whatever comes back to a plain array, and pull the
 * canonical id out of it.
 *
 * It exists for one reason. Every call from this feature runs with
 * `_rbac: false` and `_multitenancy: false`, because the subscribe, confirm,
 * unsubscribe and preference endpoints have no session at all, and a call
 * left on the defaults resolves to Anonymous and returns nothing rather than
 * failing. That is a silent no-op: an empty result looks exactly like a list
 * with no members. Writing the flags in one place is what stops a later
 * method from quietly omitting them.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ListObjectStore — register-scoped, session-free object access.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
 */
class ListObjectStore {
	/**
	 * Default register slug, matching the sibling marketing services.
	 *
	 * @var string
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy ObjectService resolve).
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a schema slug from app config, falling back to a default.
	 *
	 * @param string $configKey App-config key holding an override.
	 * @param string $default The slug the register fragment declares.
	 *
	 * @return string Schema slug.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function schemaSlug(string $configKey, string $default): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, $configKey, '');
		if ($slug !== '') {
			return $slug;
		}

		return $default;
	}//end schemaSlug()

	/**
	 * Fetch one object by UUID or slug.
	 *
	 * @param string $schemaSlug The schema to read from.
	 * @param string $id Object UUID or slug.
	 *
	 * @return array<string, mixed>|null The payload, or null when it does not
	 *                                   exist or OpenRegister is unreachable.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function find(string $schemaSlug, string $id): ?array {
		$context = $this->context(schemaSlug: $schemaSlug);
		if ($context === null || $id === '') {
			return null;
		}

		try {
			$entity = $context['service']->find(
				id: $id,
				register: $context['register'],
				schema: $context['schema'],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'ListObjectStore.find: not found',
				['schema' => $schemaSlug, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end find()

	/**
	 * Fetch every object matching a filter set.
	 *
	 * The filters are re-applied in PHP after the query. OpenRegister's
	 * filter DSL ignores a key it does not recognise, and an ignored filter
	 * returns rows that were never asked for while looking exactly like a
	 * correct result.
	 *
	 * @param string $schemaSlug The schema to read from.
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> Plain payloads.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function findAll(string $schemaSlug, array $filters = []): array {
		$context = $this->context(schemaSlug: $schemaSlug);
		if ($context === null) {
			return [];
		}

		try {
			$rows = $context['service']->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						['register' => $context['register'], 'schema' => $context['schema']],
					),
				],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ListObjectStore.findAll: query failed',
				['schema' => $schemaSlug, 'filters' => array_keys($filters), 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$payload = $this->toArray(value: $row);
			if ($payload === [] || $this->matches(payload: $payload, filters: $filters) === false) {
				continue;
			}

			$out[] = $payload;
		}

		return $out;
	}//end findAll()

	/**
	 * Persist an object, creating it when no id is given.
	 *
	 * @param string $schemaSlug The schema to write to.
	 * @param array<string, mixed> $payload The payload to store.
	 * @param string|null $id Existing id when updating.
	 *
	 * @return array<string, mixed>|null Saved row, or null on failure.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
		$context = $this->context(schemaSlug: $schemaSlug);
		if ($context === null) {
			return null;
		}

		$uuid = $id;
		if ($uuid === '') {
			$uuid = null;
		}

		try {
			$saved = $context['service']->saveObject(
				object: $payload,
				register: $context['register'],
				schema: $context['schema'],
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ListObjectStore.save: write failed',
				['schema' => $schemaSlug, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end save()

	/**
	 * Extract the canonical id from an entity payload.
	 *
	 * @param array<string, mixed>|null $payload Entity payload.
	 *
	 * @return string Identifier, or an empty string.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ordered fallback lookup over id keys; extraction adds no clarity.
	 */
	public function idOf(?array $payload): string {
		if ($payload === null) {
			return '';
		}

		foreach (['uuid', 'id', 'slug'] as $key) {
			$value = ($payload[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end idOf()

	/**
	 * Resolve the register, the schema and the ObjectService for one call.
	 *
	 * @param string $schemaSlug The schema to act on.
	 *
	 * @return array{register: string, schema: string, service: object}|null
	 *         Null when anything needed is missing.
	 */
	private function context(string $schemaSlug): ?array {
		if ($schemaSlug === '') {
			return null;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			$register = self::DEFAULT_REGISTER_SLUG;
		}

		try {
			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ListObjectStore.context: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return ['register' => $register, 'schema' => $schemaSlug, 'service' => $service];
	}//end context()

	/**
	 * Re-check a row against the filters that were asked for.
	 *
	 * @param array<string, mixed> $payload The row.
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return bool True when every filter matches.
	 */
	private function matches(array $payload, array $filters): bool {
		foreach ($filters as $field => $value) {
			if ((string)($payload[$field] ?? '') !== (string)$value) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * Normalise an OpenRegister entity, or an array, to a plain array.
	 *
	 * @param mixed $value Entity object or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()
}//end class
