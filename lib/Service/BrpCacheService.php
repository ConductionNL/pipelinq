<?php

/**
 * Pipelinq BrpCacheService.
 *
 * Manages cached BrpPersoon records with TTL + webhook-driven invalidation. The cache lives
 * directly in OpenRegister (the brpPersoon schema) so retention / encryption / search are
 * uniform with the rest of the platform.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Cache wrapper around the brpPersoon schema.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-004
 */
class BrpCacheService {
	/**
	 * Default TTL in hours (24h per design.md).
	 */
	private const DEFAULT_TTL_HOURS = 24;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config (cache_ttl_hours setting).
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return the most recent unexpired BrpPersoon for a raw BSN, or null on cache-miss.
	 *
	 * @param string $rawBsn Raw BSN — hashed in-process only.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-004-01
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-004-02
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash is a pure stateless helper.
	 */
	public function get(string $rawBsn): ?array {
		try {
			[$register, $schema] = $this->config();
			$hash = BsnValidationService::hash($rawBsn);
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'bsnHash' => $hash,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);

			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$latest = null;
			foreach (($results ?? []) as $object) {
				$arr = self::toArray(object: $object);
				$retentionTo = (string)($arr['retentionTo'] ?? '');
				if ($retentionTo === '') {
					continue;
				}

				try {
					$retentionDt = new DateTimeImmutable($retentionTo, new DateTimeZone('UTC'));
				} catch (Throwable $e) {
					continue;
				}

				if ($retentionDt <= $now) {
					continue;
				}

				if ($latest === null) {
					$latest = $arr;
					continue;
				}

				$latestFetched = (string)($latest['fetchedOn'] ?? '');
				$currentFetched = (string)($arr['fetchedOn'] ?? '');
				if ($currentFetched > $latestFetched) {
					$latest = $arr;
				}
			}//end foreach

			return $latest;
		} catch (Throwable $e) {
			$this->logger->error(
				'BRP cache lookup failed',
				['error' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end get()

	/**
	 * Persist a BrpPersoon and stamp it with retentieTot = now + ttlHours.
	 *
	 * @param array<string,mixed> $person Normalised BrpPersoon array (without retentieTot).
	 * @param int|null $ttlHours Override TTL — defaults to configured / 24h.
	 *
	 * @return array<string,mixed> The saved object as array (with assigned UUID).
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-004-04
	 */
	public function set(array $person, ?int $ttlHours = null): array {
		[$register, $schema] = $this->config();

		$ttl = $ttlHours ?? $this->getConfiguredTtlHours();
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$retention = $now->modify('+' . max(1, $ttl) . ' hours');

		$person['fetchedOn'] = $person['fetchedOn'] ?? $now->format(DATE_ATOM);
		$person['retentionTo'] = $retention->format(DATE_ATOM);

		$saved = $this->getObjectService()->saveObject(
			object: $person,
			extend: [],
			register: $register,
			schema: $schema,
		);
		return self::toArray(object: $saved);
	}//end set()

	/**
	 * Mark cache entries for a BSN as expired (retentieTot = now-1s).
	 *
	 * Called by the BRP mutation webhook listener.
	 *
	 * @param string $rawBsn Raw BSN.
	 *
	 * @return int Number of cache entries invalidated.
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-004-03
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash is a pure stateless helper.
	 */
	public function invalidate(string $rawBsn): int {
		try {
			[$register, $schema] = $this->config();
			$hash = BsnValidationService::hash($rawBsn);
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'bsnHash' => $hash,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);

			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$expired = $now->modify('-1 second')->format(DATE_ATOM);
			$count = 0;
			foreach (($results ?? []) as $object) {
				$arr = self::toArray(object: $object);
				$uuid = (string)($arr['@self']['id'] ?? $arr['id'] ?? '');
				if ($uuid === '') {
					continue;
				}

				$arr['retentionTo'] = $expired;
				$this->getObjectService()->saveObject(
					object: $arr,
					extend: [],
					register: $register,
					schema: $schema,
					uuid: $uuid,
				);
				$count++;
			}

			return $count;
		} catch (Throwable $e) {
			$this->logger->error(
				'BRP cache invalidate failed',
				['error' => $e->getMessage()]
			);
			return 0;
		}//end try
	}//end invalidate()

	/**
	 * Get the configured cache TTL (hours).
	 *
	 * @return int Hours (>= 1).
	 */
	public function getConfiguredTtlHours(): int {
		$value = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'brp.cache_ttl_hours',
			(string)self::DEFAULT_TTL_HOURS,
		);
		if ($value >= 1) {
			return $value;
		}

		return self::DEFAULT_TTL_HOURS;
	}//end getConfiguredTtlHours()

	/**
	 * Normalise an OR object (entity or array) to an array.
	 *
	 * @param mixed $object OR object (entity or array) to normalise.
	 *
	 * @return array<string,mixed>
	 */
	private static function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serial = $object->jsonSerialize();
			if (is_array($serial) === true) {
				return $serial;
			}

			return [];
		}

		return [];
	}//end toArray()

	/**
	 * Resolve [register, schema].
	 *
	 * @return array{0:string, 1:string}
	 *
	 * @throws RuntimeException If misconfigured.
	 */
	private function config(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'brpPersoon_schema', '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('brpPersoon register/schema not configured.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Lazy OR ObjectService.
	 *
	 * @return object
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
