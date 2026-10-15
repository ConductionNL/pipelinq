<?php

/**
 * Pipelinq SystemObjectStore.
 *
 * Register-scoped object access for an unattended read or write on a public
 * path, such as a website form or a landing page submission. Every call runs
 * as pipelinq's system account (SystemServiceAccount::runAsSystem) with
 * OpenRegister's access checks ON: the register's authorization block decides
 * what the account may read and write, never a flag in this class.
 *
 * It is the access-checked sibling of Marketing\ListObjectStore, which runs
 * with `_rbac: false` for the mailing-list endpoints. A new unattended write
 * belongs here.
 *
 * `_multitenancy` is off: the system account belongs to no organisation, and
 * with organisation scoping on it would find none of the contacts the
 * submission must update. The authorization block is the boundary.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-touches-are-written-as-pipelinqs-system-account-req-moc-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SystemObjectStore: register-scoped object access as the system account, access checks on.
 *
 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-touches-are-written-as-pipelinqs-system-account-req-moc-004
 */
class SystemObjectStore {

	/**
	 * The register slug when app config names none.
	 *
	 * @var string
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * OpenRegister's object service, resolved by name: pipelinq boots without OpenRegister.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface   $container Reaches OpenRegister's object service.
	 * @param IAppConfig           $appConfig Pipelinq app config (the register).
	 * @param SystemServiceAccount $account   The account every call runs as.
	 * @param LoggerInterface      $logger    Logger. Object contents MUST NOT be written here.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly SystemServiceAccount $account,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One object, or null when it is absent, not permitted or OpenRegister is unavailable.
	 *
	 * @param string $schemaSlug The schema.
	 * @param string $id         The object id.
	 *
	 * @return array<string, mixed>|null The object.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-touches-are-written-as-pipelinqs-system-account-req-moc-004
	 */
	public function find(string $schemaSlug, string $id): ?array {
		if (trim($schemaSlug) === '' || trim($id) === '') {
			return null;
		}

		return $this->call(
			what: 'find',
			schemaSlug: $schemaSlug,
			operation: function (object $service, string $register) use ($schemaSlug, $id): ?array {
				$entity = $service->find(
					id: $id,
					register: $register,
					schema: $schemaSlug,
					_multitenancy: false,
				);
				if ($entity === null) {
					return null;
				}

				return $this->toArray(value: $entity);
			},
			fallback: null,
		);
	}//end find()

	/**
	 * Every object of a schema whose fields equal the filters.
	 *
	 * @param string                $schemaSlug The schema.
	 * @param array<string, string> $filters    Field-value pairs, re-checked on every row.
	 *
	 * @return array<int, array<string, mixed>> The matching objects.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-touches-are-written-as-pipelinqs-system-account-req-moc-004
	 */
	public function findAll(string $schemaSlug, array $filters): array {
		if (trim($schemaSlug) === '') {
			return [];
		}

		return $this->call(
			what: 'findAll',
			schemaSlug: $schemaSlug,
			operation: function (object $service, string $register) use ($schemaSlug, $filters): array {
				$rows = $service->findAll(
					config: ['filters' => array_merge($filters, ['register' => $register, 'schema' => $schemaSlug])],
					_multitenancy: false,
				);

				$out = [];
				foreach ((array)$rows as $row) {
					$payload = $this->toArray(value: $row);
					if ($payload !== [] && $this->matches(payload: $payload, filters: $filters) === true) {
						$out[] = $payload;
					}
				}

				return $out;
			},
			fallback: [],
		);
	}//end findAll()

	/**
	 * Create an object, or replace the one with this id.
	 *
	 * @param string               $schemaSlug The schema.
	 * @param array<string, mixed> $payload    The object, without `@self`.
	 * @param string|null          $id         The id to replace, or null to create.
	 *
	 * @return array<string, mixed>|null The stored object, or null when the write was refused or failed.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-touches-are-written-as-pipelinqs-system-account-req-moc-004
	 */
	public function save(string $schemaSlug, array $payload, ?string $id=null): ?array {
		if (trim($schemaSlug) === '') {
			return null;
		}

		unset($payload['@self']);
		$uuid = $id;
		if ($uuid === '') {
			$uuid = null;
		}

		return $this->call(
			what: 'save',
			schemaSlug: $schemaSlug,
			operation: function (object $service, string $register) use ($schemaSlug, $payload, $uuid): array {
				return $this->toArray(
					value: $service->saveObject(
						object: $payload,
						register: $register,
						schema: $schemaSlug,
						uuid: $uuid,
						_multitenancy: false,
					)
				);
			},
			fallback: null,
		);
	}//end save()

	/**
	 * The canonical id of an object.
	 *
	 * @param array<string, mixed>|null $payload The object.
	 *
	 * @return string The id, or an empty string.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-touches-are-written-as-pipelinqs-system-account-req-moc-004
	 */
	public function idOf(?array $payload): string {
		foreach ([($payload ?? []), (array)($payload['@self'] ?? [])] as $source) {
			foreach (['id', 'uuid'] as $key) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end idOf()

	/**
	 * Run one OpenRegister call as the system account; a failure is logged and answered with the fallback.
	 *
	 * @param string   $what       The operation, for the log.
	 * @param string   $schemaSlug The schema, for the log.
	 * @param callable $operation  Receives the object service and the register.
	 * @param mixed    $fallback   What a failure returns.
	 *
	 * @return mixed What the operation returns, or the fallback.
	 */
	private function call(string $what, string $schemaSlug, callable $operation, mixed $fallback): mixed {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			$register = self::DEFAULT_REGISTER_SLUG;
		}

		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
			return $this->account->runAsSystem(fn () => $operation($service, $register));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: a call as the system account failed',
				['operation' => $what, 'schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return $fallback;
		}
	}//end call()

	/**
	 * Re-check a row against the filters asked for.
	 *
	 * @param array<string, mixed>  $payload The row.
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
	 * @param mixed $value Entity or array.
	 *
	 * @return array<string, mixed> The payload.
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

		return [];
	}//end toArray()
}//end class
