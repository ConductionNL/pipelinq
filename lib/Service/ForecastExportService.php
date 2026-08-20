<?php

/**
 * Pipelinq ForecastExportService.
 *
 * Builds the paginated snapshot export payload (with per-snapshot calculation
 * audit) and the injection-safe CSV rendering for board reporting.
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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Snapshot export builder.
 *
 * The calculation audit for a non-rep snapshot lists each child owner's
 * override-applied commit so a board reviewer can trace the roll-up. Child
 * resolution reuses {@see ForecastService::computeRollUp()} so override
 * application is consistent with the live view.
 */
class ForecastExportService {
	/**
	 * The CSV header row.
	 *
	 * @var array<int, string>
	 */
	private const CSV_HEADER = ['as_of_date', 'owner_id', 'level', 'commit', 'best_case', 'pipeline', 'closed_won', 'quota'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ForecastService $forecastService The forecast computation service.
	 * @param ReportingService $reportingService The CSV rendering service.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ForecastService $forecastService,
		private ReportingService $reportingService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Build the paginated snapshot export payload.
	 *
	 * @param string $periodId The fiscal period id.
	 * @param string $level The hierarchy level.
	 * @param string|null $ownerId Optional owner filter.
	 * @param int $limit The page size.
	 * @param int $offset The page offset.
	 *
	 * @return array{snapshots: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-010-01, REQ-FRC-010-05
	 */
	public function exportSnapshots(string $periodId, string $level, ?string $ownerId, int $limit, int $offset): array {
		$filters = ['period_id' => $periodId, 'level' => $level];
		if ($ownerId !== null && $ownerId !== '') {
			$filters['owner_id'] = $ownerId;
		}

		// Push the ascending sort and the page window down into OpenRegister,
		// and count the full matching set separately, instead of fetching every
		// snapshot and sorting/slicing in PHP.
		$total = $this->countObjects(schemaKey: 'forecastSnapshot_schema', filters: $filters);
		$page = $this->findObjects(
			schemaKey: 'forecastSnapshot_schema',
			filters: $filters,
			sort: ['as_of_date' => 'ASC'],
			limit: $limit,
			offset: $offset
		);

		$snapshots = [];
		foreach ($page as $row) {
			$snapshots[] = $this->presentSnapshot(row: $row, periodId: $periodId);
		}

		return [
			'snapshots' => $snapshots,
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
		];
	}//end exportSnapshots()

	/**
	 * Render snapshots as injection-safe CSV.
	 *
	 * @param array<int, array<string, mixed>> $snapshots The presented snapshots.
	 *
	 * @return string The CSV body.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-010-02
	 */
	public function toCsv(array $snapshots): string {
		$rows = [];
		foreach ($snapshots as $snapshot) {
			$rows[] = [
				(string)($snapshot['as_of_date'] ?? ''),
				(string)($snapshot['owner_id'] ?? ''),
				(string)($snapshot['level'] ?? ''),
				(string)($snapshot['commit'] ?? 0),
				(string)($snapshot['best_case'] ?? 0),
				(string)($snapshot['pipeline'] ?? 0),
				(string)($snapshot['closed_won'] ?? 0),
				(string)($snapshot['quota'] ?? ''),
			];
		}

		return $this->reportingService->generateCsv(headers: self::CSV_HEADER, rows: $rows);
	}//end toCsv()

	/**
	 * Present a raw snapshot with normalized fields and a calculation audit.
	 *
	 * @param array<string, mixed> $row The raw snapshot.
	 * @param string $periodId The period id.
	 *
	 * @return array<string, mixed> The presented snapshot.
	 */
	private function presentSnapshot(array $row, string $periodId): array {
		$self = $row['@self'] ?? [];
		$id = null;
		if (is_array($self) === true) {
			$id = ($self['uuid'] ?? $self['id'] ?? null);
		}

		$level = (string)($row['level'] ?? '');

		$quota = null;
		if (isset($row['quota_amount']) === true) {
			$quota = (float)$row['quota_amount'];
		}

		return [
			'id' => $id,
			'owner_id' => (string)($row['owner_id'] ?? ''),
			'as_of_date' => (string)($row['as_of_date'] ?? ''),
			'level' => $level,
			'commit' => (float)($row['commit_amount'] ?? 0),
			'best_case' => (float)($row['best_case_amount'] ?? 0),
			'pipeline' => (float)($row['pipeline_amount'] ?? 0),
			'closed_won' => (float)($row['closed_won_amount'] ?? 0),
			'quota' => $quota,
			'currency' => (string)($row['currency'] ?? 'EUR'),
			'partial' => (bool)($row['partial'] ?? false),
			'calculation_audit' => $this->buildAudit(row: $row, periodId: $periodId, level: $level),
		];
	}//end presentSnapshot()

	/**
	 * Build the calculation audit (per-child override-applied commit).
	 *
	 * Only meaningful above rep level; a rep snapshot returns an empty audit
	 * (its contribution is the deal-level commit, traced via deal_snapshot_ids).
	 *
	 * @param array<string, mixed> $row The raw snapshot.
	 * @param string $periodId The period id.
	 * @param string $level The snapshot level.
	 *
	 * @return array<int, array<string, mixed>> The audit rows.
	 */
	private function buildAudit(array $row, string $periodId, string $level): array {
		$childLevel = $this->childLevel(level: $level);
		if ($childLevel === null) {
			return [];
		}

		$audit = [];
		$children = $this->childOwners(row: $row, level: $level, periodId: $periodId, childLevel: $childLevel);
		foreach ($children as $childId) {
			$rollUp = $this->forecastService->computeRollUp(ownerId: $childId, periodId: $periodId, level: $childLevel);
			$overrideAmount = null;
			if ($rollUp['commit_override_id'] !== null) {
				$overrideAmount = (float)($rollUp['commit'] ?? 0);
			}

			$audit[] = [
				'source_id' => $childId,
				'commit' => (float)($rollUp['commit'] ?? 0),
				'override_amount' => $overrideAmount,
				'override_reason' => $rollUp['commit_override_reason'],
			];
		}

		return $audit;
	}//end buildAudit()

	/**
	 * Resolve the immediate child level of a given level.
	 *
	 * @param string $level The parent level.
	 *
	 * @return string|null The child level, or null when none (rep).
	 */
	private function childLevel(string $level): ?string {
		return match ($level) {
			'company' => 'division',
			'division' => 'team',
			'team' => 'rep',
			default => null,
		};
	}//end childLevel()

	/**
	 * Resolve the child owner ids contributing to a snapshot.
	 *
	 * The child owners are read from sibling snapshots at the child level for
	 * the same period (the snapshot job already materialized them).
	 *
	 * @param array<string, mixed> $row The parent snapshot.
	 * @param string $level The parent level.
	 * @param string $periodId The period id.
	 * @param string $childLevel The child level.
	 *
	 * @return array<int, string> The child owner ids.
	 */
	private function childOwners(array $row, string $level, string $periodId, string $childLevel): array {
		$childRows = $this->findObjects(
			schemaKey: 'forecastSnapshot_schema',
			filters: [
				'period_id' => $periodId,
				'level' => $childLevel,
			]
		);

		$owners = [];
		foreach ($childRows as $childRow) {
			$owner = (string)($childRow['owner_id'] ?? '');
			if ($owner !== '') {
				$owners[$owner] = true;
			}
		}

		unset($row, $level);
		return array_keys($owners);
	}//end childOwners()

	/**
	 * Query objects of a configured schema with equality filters.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param array<string, mixed> $filters Additional equality filters.
	 * @param array<string, string> $sort Optional sort map (field => ASC|DESC) pushed to OR.
	 * @param int|null $limit Optional page size pushed to OR.
	 * @param int|null $offset Optional offset pushed to OR.
	 *
	 * @return array<int, array<string, mixed>> The matching objects as arrays.
	 */
	private function findObjects(string $schemaKey, array $filters, array $sort = [], ?int $limit = null, ?int $offset = null): array {
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

		if ($offset !== null) {
			$config['offset'] = $offset;
		}

		try {
			$results = $this->getObjectService()->findAll(config: $config);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: forecast export query failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}//end findObjects()

	/**
	 * Count objects of a configured schema with equality filters via OpenRegister.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param array<string, mixed> $filters Additional equality filters.
	 *
	 * @return int The matching row count, or 0 when OR is unavailable.
	 *
	 * @spec openspec/changes/pipelinq-query-pushdown-batch-1/tasks.md#task-8
	 */
	private function countObjects(string $schemaKey, array $filters): int {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return 0;
		}

		$allFilters = array_merge(['register' => $register, 'schema' => $schema], $filters);

		try {
			return $this->getObjectService()->count(config: ['filters' => $allFilters]);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: forecast export count failed', ['exception' => $e->getMessage()]);
			return 0;
		}
	}//end countObjects()

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
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
