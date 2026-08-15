<?php

/**
 * Pipelinq ForecastOverrideService.
 *
 * Validates and persists manager forecast overrides. The original (calculated)
 * amount and the creator are set server-side so a client can never forge them.
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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Manager-override lifecycle service.
 *
 * Overrides never touch the underlying deals; they are independent records the
 * {@see ForecastService::computeRollUp()} reads when applying overrides.
 */
class ForecastOverrideService {
	/**
	 * The categories that may be overridden.
	 *
	 * @var array<int, string>
	 */
	public const OVERRIDE_CATEGORIES = ['commit', 'best_case'];

	/**
	 * Minimum characters required in an override reason.
	 *
	 * @var int
	 */
	public const REASON_MIN_LENGTH = 5;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (OpenRegister lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ForecastService $forecastService The forecast computation service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ForecastService $forecastService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Validate an override request payload.
	 *
	 * @param array<string, mixed> $payload The request payload.
	 *
	 * @return string|null An error message, or null when valid.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-006-01
	 */
	public function validatePayload(array $payload): ?string {
		if (trim((string)($payload['period_id'] ?? '')) === '') {
			return 'period_id is required.';
		}

		if (trim((string)($payload['override_owner_id'] ?? '')) === '') {
			return 'override_owner_id is required.';
		}

		if (in_array((string)($payload['level'] ?? ''), ['rep', 'team', 'division'], true) === false) {
			return 'A valid level (rep, team, division) is required.';
		}

		if (in_array((string)($payload['category'] ?? ''), self::OVERRIDE_CATEGORIES, true) === false) {
			return 'A valid category (commit, best_case) is required.';
		}

		$amount = $payload['override_amount'] ?? null;
		if (is_numeric($amount) === false || (float)$amount < 0) {
			return 'override_amount must be a non-negative number.';
		}

		if (mb_strlen(trim((string)($payload['reason'] ?? ''))) < self::REASON_MIN_LENGTH) {
			return 'A reason of at least 5 characters is required.';
		}

		return null;
	}//end validatePayload()

	/**
	 * Create and persist an override.
	 *
	 * The original (calculated) amount is resolved server-side from the current
	 * roll-up; created_by and created_at are server-set.
	 *
	 * @param array<string, mixed> $payload The validated request payload.
	 * @param string $managerId The acting manager UID.
	 *
	 * @return array<string, mixed> The created override data.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-006-01
	 */
	public function createOverride(array $payload, string $managerId): array {
		$periodId = (string)$payload['period_id'];
		$ownerId = (string)$payload['override_owner_id'];
		$level = (string)$payload['level'];
		$category = (string)$payload['category'];

		$rollUp = $this->forecastService->computeRollUp(ownerId: $ownerId, periodId: $periodId, level: $level);
		$originalKey = 'original_best_case';
		if ($category === 'commit') {
			$originalKey = 'original_commit';
		}

		$original = (float)($rollUp[$originalKey] ?? 0);

		$override = [
			'period_id' => $periodId,
			'owner_id' => $managerId,
			'override_owner_id' => $ownerId,
			'level' => $level,
			'category' => $category,
			'override_amount' => (float)$payload['override_amount'],
			'original_amount' => $original,
			'reason' => trim((string)$payload['reason']),
			'created_by' => $managerId,
			'created_at' => (new DateTimeImmutable())->format('c'),
		];

		return $this->persist(override: $override);
	}//end createOverride()

	/**
	 * Fetch an override by id.
	 *
	 * @param string $id The override UUID.
	 *
	 * @return array<string, mixed>|null The override data, or null.
	 */
	public function getOverride(string $id): ?array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $id === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end getOverride()

	/**
	 * Delete an override.
	 *
	 * @param string $id The override UUID.
	 *
	 * @return bool True when deleted.
	 */
	public function deleteOverride(string $id): bool {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $id === '') {
			return false;
		}

		try {
			return (bool)$this->getObjectService()->deleteObject(uuid: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: failed to delete forecast override', ['exception' => $e->getMessage(), 'id' => $id]);
			return false;
		}
	}//end deleteOverride()

	/**
	 * Persist an override record.
	 *
	 * @param array<string, mixed> $override The override data.
	 *
	 * @return array<string, mixed> The saved override as an array.
	 */
	private function persist(array $override): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Forecast override schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $override,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: null
		);

		return $this->toArray(object: $saved);
	}//end persist()

	/**
	 * Resolve the register + override schema ids.
	 *
	 * Fails closed: '' on either id means "unconfigured", and every caller
	 * refuses the OpenRegister call on it. An empty id must never be handed to
	 * OpenRegister — ObjectService skips setRegister()/setSchema() for an empty
	 * value, so the query silently inherits whatever context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return array{0: string, 1: string} The [register, schema] ids, each ''
	 *                                     when unconfigured.
	 */
	private function config(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'forecastOverride_schema', '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/forecastOverride_schema not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return [$registerId, $schemaId];
	}//end config()

	/**
	 * Normalize an OpenRegister entity (or array) to a plain array.
	 *
	 * @param mixed $object The entity or array.
	 *
	 * @return array<string, mixed> The object data.
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

		return [];
	}//end toArray()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 */
	private function getObjectService(): object {
		try {
			return $this->objectService;
		} catch (\Throwable $e) {
			throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
		}
	}//end getObjectService()
}//end class
