<?php

/**
 * Pipelinq ForecastService.
 *
 * Computes effective forecast roll-ups (applying manager overrides), accuracy
 * scores and trailing-four-quarter averages from immutable snapshots.
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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-005, REQ-FRC-006, REQ-FRC-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Forecast computation engine.
 *
 * Reads immutable forecast_snapshot and forecast_override objects and exposes
 * the override-applied roll-up plus accuracy scoring. All math is
 * server-authoritative; callers never supply amounts.
 *
 * @spec exclude forecast has no owning requirement. pipeline-insights defers its config
 *   scenario to admin-settings, which never grew one
 */
class ForecastService {
	/**
	 * App-config key for the accuracy green band threshold.
	 *
	 * @var string
	 */
	public const ACCURACY_GREEN_KEY = 'forecast_accuracy_green';

	/**
	 * App-config key for the accuracy amber band threshold.
	 *
	 * @var string
	 */
	public const ACCURACY_AMBER_KEY = 'forecast_accuracy_amber';

	/**
	 * Default accuracy green band threshold (0.90).
	 *
	 * @var float
	 */
	public const ACCURACY_GREEN_DEFAULT = 0.90;

	/**
	 * Default accuracy amber band threshold (0.75).
	 *
	 * @var float
	 */
	public const ACCURACY_AMBER_DEFAULT = 0.75;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param QuotaService $quotaService The quota service.
	 * @param ForecastRollupService $rollup The pure roll-up math service.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private QuotaService $quotaService,
		private ForecastRollupService $rollup,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Compute the effective forecast for an owner/period/level after overrides.
	 *
	 * @param string $ownerId The owner identifier.
	 * @param string $periodId The fiscal period identifier.
	 * @param string $level The hierarchy level.
	 *
	 * @return array<string, mixed> The override-applied roll-up.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-006-03
	 */
	public function computeRollUp(string $ownerId, string $periodId, string $level): array {
		$snapshot = $this->latestSnapshot(ownerId: $ownerId, periodId: $periodId, level: $level);

		$commit = (float)($snapshot['commit_amount'] ?? 0);
		$bestCase = (float)($snapshot['best_case_amount'] ?? 0);
		$pipeline = (float)($snapshot['pipeline_amount'] ?? 0);
		$closedWon = (float)($snapshot['closed_won_amount'] ?? 0);
		$originalCommit = $commit;
		$originalBest = $bestCase;

		$result = [
			'owner_id' => $ownerId,
			'period_id' => $periodId,
			'level' => $level,
			'commit' => $commit,
			'original_commit' => $originalCommit,
			'commit_override_id' => null,
			'commit_override_reason' => null,
			'best_case' => $bestCase,
			'original_best_case' => $originalBest,
			'best_case_override_id' => null,
			'best_case_override_reason' => null,
			'pipeline' => $pipeline,
			'closed_won' => $closedWon,
			'quota' => $this->quotaService->getQuotaAmount(ownerId: $ownerId, periodId: $periodId, level: $level),
			'deal_snapshot_ids' => ($snapshot['deal_snapshot_ids'] ?? []),
			'partial' => (bool)($snapshot['partial'] ?? false),
		];

		$commitOverride = $this->latestOverride(ownerId: $ownerId, periodId: $periodId, level: $level, category: 'commit');
		if ($commitOverride !== null) {
			$result['commit'] = (float)($commitOverride['override_amount'] ?? $commit);
			$result['commit_override_id'] = $this->objectId(object: $commitOverride);
			$result['commit_override_reason'] = (string)($commitOverride['reason'] ?? '');
		}

		$bestOverride = $this->latestOverride(ownerId: $ownerId, periodId: $periodId, level: $level, category: 'best_case');
		if ($bestOverride !== null) {
			$result['best_case'] = (float)($bestOverride['override_amount'] ?? $bestCase);
			$result['best_case_override_id'] = $this->objectId(object: $bestOverride);
			$result['best_case_override_reason'] = (string)($bestOverride['reason'] ?? '');
		}

		return $result;
	}//end computeRollUp()

	/**
	 * Compute the forecast accuracy score for a closed period.
	 *
	 * Accuracy = 1 - abs(commit - actual) / actual. Returns null when the period
	 * is not closed or there is no actual closed_won to compare against.
	 *
	 * @param float|null $weekOneCommit The week-1 commit snapshot (null when missing).
	 * @param float|null $actualClosedWon The actual closed_won total (null/0 when missing).
	 * @param bool $periodClosed Whether the period has closed.
	 *
	 * @return float|null The accuracy score (0.0+), or null.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-007-01, REQ-FRC-007-02
	 */
	public function computeAccuracyScore(?float $weekOneCommit, ?float $actualClosedWon, bool $periodClosed): ?float {
		if ($periodClosed === false || $weekOneCommit === null || $actualClosedWon === null || $actualClosedWon === 0.0) {
			return null;
		}

		$score = 1.0 - (abs($weekOneCommit - $actualClosedWon) / $actualClosedWon);
		return round($score, 4);
	}//end computeAccuracyScore()

	/**
	 * Average a set of accuracy scores (e.g. team = average of rep accuracies).
	 *
	 * @param array<int, float> $scores The accuracy scores.
	 *
	 * @return float|null The average, or null when no scores.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-007-04
	 */
	public function averageAccuracy(array $scores): ?float {
		$values = array_values(array_filter($scores, static fn ($value): bool => is_numeric($value)));
		if ($values === []) {
			return null;
		}

		return round(array_sum($values) / count($values), 4);
	}//end averageAccuracy()

	/**
	 * Compute the trailing-four-quarters accuracy average.
	 *
	 * Uses the most recent four scores (newest first). Returns 0.0 when fewer
	 * than four quarters of data exist.
	 *
	 * @param array<int, float> $orderedScores Accuracy scores, newest first.
	 *
	 * @return float The trailing-4Q average (0.0 when insufficient data).
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-007-05
	 */
	public function computeTrailingQuartersAccuracy(array $orderedScores): float {
		$values = array_values(array_filter($orderedScores, static fn ($value): bool => is_numeric($value)));
		if (count($values) < 4) {
			return 0.0;
		}

		$window = array_slice($values, 0, 4);
		return round(array_sum($window) / 4, 4);
	}//end computeTrailingQuartersAccuracy()

	/**
	 * Resolve the colour band for an accuracy score.
	 *
	 * @param float $score The accuracy score.
	 *
	 * @return string One of green, amber, red.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-007-04
	 */
	public function accuracyBand(float $score): string {
		$green = $this->appConfig->getValueString(Application::APP_ID, self::ACCURACY_GREEN_KEY, (string)self::ACCURACY_GREEN_DEFAULT);
		$amber = $this->appConfig->getValueString(Application::APP_ID, self::ACCURACY_AMBER_KEY, (string)self::ACCURACY_AMBER_DEFAULT);

		$greenThreshold = self::ACCURACY_GREEN_DEFAULT;
		if ($green !== '') {
			$greenThreshold = (float)$green;
		}

		$amberThreshold = self::ACCURACY_AMBER_DEFAULT;
		if ($amber !== '') {
			$amberThreshold = (float)$amber;
		}

		if ($score > $greenThreshold) {
			return 'green';
		}

		if ($score >= $amberThreshold) {
			return 'amber';
		}

		return 'red';
	}//end accuracyBand()

	/**
	 * Fetch the most recent snapshot for an owner/period/level.
	 *
	 * @param string $ownerId The owner identifier.
	 * @param string $periodId The fiscal period identifier.
	 * @param string $level The hierarchy level.
	 *
	 * @return array<string, mixed> The latest snapshot, or an empty totals array.
	 */
	private function latestSnapshot(string $ownerId, string $periodId, string $level): array {
		// Push the "most recent" selection down into OpenRegister: sort by
		// as_of_date descending and take the first row, instead of fetching
		// every matching snapshot and sorting in PHP.
		$rows = $this->findObjects(
			schemaKey: 'forecastSnapshot_schema',
			filters: [
				'owner_id' => $ownerId,
				'period_id' => $periodId,
				'level' => $level,
			],
			sort: ['as_of_date' => 'DESC'],
			limit: 1
		);

		if ($rows === []) {
			return $this->rollup->emptyTotals();
		}

		return $rows[0];
	}//end latestSnapshot()

	/**
	 * Fetch the most recent override for an owner/period/level/category.
	 *
	 * @param string $ownerId The override_owner_id being overridden.
	 * @param string $periodId The fiscal period identifier.
	 * @param string $level The hierarchy level.
	 * @param string $category The overridden category.
	 *
	 * @return array<string, mixed>|null The latest override, or null.
	 */
	private function latestOverride(string $ownerId, string $periodId, string $level, string $category): ?array {
		// Push the "most recent" selection down into OpenRegister: sort by
		// created_at descending and take the first row.
		$rows = $this->findObjects(
			schemaKey: 'forecastOverride_schema',
			filters: [
				'override_owner_id' => $ownerId,
				'period_id' => $periodId,
				'level' => $level,
				'category' => $category,
			],
			sort: ['created_at' => 'DESC'],
			limit: 1
		);

		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end latestOverride()

	/**
	 * Query objects of a configured schema with equality filters.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param array<string, mixed> $filters Additional equality filters.
	 * @param array<string, string> $sort Optional sort map (field => ASC|DESC) pushed to OR.
	 * @param int|null $limit Optional row limit pushed to OR.
	 *
	 * @return array<int, array<string, mixed>> The matching objects as arrays.
	 */
	private function findObjects(string $schemaKey, array $filters, array $sort = [], ?int $limit = null): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$allFilters = array_merge(['register' => $register, 'schema' => $schema], $filters);

		$config = ['filters' => $allFilters];
		if ($sort !== []) {
			$config['sort'] = $sort;
		}

		if ($limit !== null) {
			$config['limit'] = $limit;
		}

		try {
			$results = $this->getObjectService()->findAll(config: $config);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: forecast query failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}//end findObjects()

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
	 * Resolve an object's identifier.
	 *
	 * @param array<string, mixed> $object The object data.
	 *
	 * @return string|null The id/uuid, or null.
	 */
	private function objectId(array $object): ?string {
		$self = $object['@self'] ?? [];
		if (is_array($self) === true) {
			$candidate = $self['uuid'] ?? $self['id'] ?? null;
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		$candidate = $object['uuid'] ?? $object['id'] ?? null;
		if (is_string($candidate) === true) {
			return $candidate;
		}

		return null;
	}//end objectId()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
