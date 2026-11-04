<?php

/**
 * Pipelinq SnapshotGenerationService.
 *
 * Generates immutable forecast snapshots for every rep, team, division and the
 * company for the open fiscal period, with hierarchical roll-up, currency
 * normalization, partial-failure tracking and admin notification.
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
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004, REQ-FRC-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Forecast\SnapshotLevel;
use OCA\Pipelinq\Service\Forecast\SnapshotNode;
use OCA\Pipelinq\Service\Forecast\SnapshotRun;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Snapshot generation orchestration.
 *
 * The hierarchy is derived from Nextcloud groups: a "team" is a configured
 * forecast group, its members are reps, and (for the MVP) all teams roll up to
 * one division and one company. The org may configure a team=>division map.
 *
 * The pure roll-up sequence ({@see self::buildSnapshots()}) is unit-tested
 * independently of OpenRegister and the group manager.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) rep/team/division/company
 *  roll-up plus persistence and notification in one cohesive orchestrator
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   orchestrates rollup, exchange
 *  rate, fiscal period, quota, notification and OpenRegister collaborators by design
 *
 * @spec exclude forecast snapshot generation; see the forecast gap above
 */
class SnapshotGenerationService {
	/**
	 * App-config key for the comma-separated list of forecast team group ids.
	 *
	 * @var string
	 */
	public const TEAMS_KEY = 'forecast_team_groups';

	/**
	 * App-config key for the JSON team=>division map.
	 *
	 * @var string
	 */
	public const DIVISION_MAP_KEY = 'forecast_division_map';

	/**
	 * The company-level owner identifier.
	 *
	 * @var string
	 */
	public const COMPANY_OWNER = 'company';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param IGroupManager $groupManager The NC group manager.
	 * @param ForecastRollupService $rollup The pure roll-up math service.
	 * @param ExchangeRateService $exchangeRate The currency normalization service.
	 * @param FiscalPeriodService $period The fiscal-period service.
	 * @param QuotaService $quotaService The quota service.
	 * @param NotificationService $notifier The notification service.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private ForecastRollupService $rollup,
		private ExchangeRateService $exchangeRate,
		private FiscalPeriodService $period,
		private QuotaService $quotaService,
		private NotificationService $notifier,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Generate and persist snapshots for the open period.
	 *
	 * @param DateTimeImmutable|null $asOf The snapshot date (defaults to today).
	 *
	 * @return array<string, mixed> A summary: period_id, counts and any errors.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-01
	 */
	public function generate(?DateTimeImmutable $asOf = null): array {
		$asOf = $asOf ?? new DateTimeImmutable();
		$periodId = $this->period->currentPeriodId($asOf);
		$asOfDate = $asOf->format('Y-m-d');

		$hierarchy = $this->resolveHierarchy();
		$dealsByRep = $this->fetchDealsByRep();

		$snapshots = $this->buildSnapshots(
			periodId: $periodId,
			asOfDate: $asOfDate,
			hierarchy: $hierarchy,
			dealsByRep: $dealsByRep
		);

		$errors = [];
		$persisted = 0;
		foreach ($snapshots as $snapshot) {
			try {
				$this->persistSnapshot(snapshot: $snapshot);
				$persisted++;
			} catch (\Throwable $e) {
				$errors[] = sprintf('%s/%s: %s', $snapshot['level'], $snapshot['owner_id'], $e->getMessage());
				$this->logger->error(
					'Pipelinq: failed to persist forecast snapshot',
					[
						'level' => $snapshot['level'],
						'owner_id' => $snapshot['owner_id'],
						'exception' => $e->getMessage(),
					]
				);
			}
		}

		if ($errors !== []) {
			$this->notifyAdmin(periodId: $periodId, errors: $errors);
		}

		$hierarchyReport = $this->quotaService->validateQuotaHierarchy(
			periodId: $periodId,
			teamMembers: ($hierarchy['teams'] ?? [])
		);
		foreach ($hierarchyReport as $row) {
			if (abs((float)($row['variance_percent'] ?? 0.0)) > 0.0) {
				$this->logger->info(
					'Pipelinq: forecast quota hierarchy variance',
					[
						'period_id' => $periodId,
						'team_id' => $row['team_id'],
						'team_quota' => $row['team_quota'],
						'rep_quotas_sum' => $row['rep_quotas_sum'],
						'variance_percent' => $row['variance_percent'],
					]
				);
			}
		}

		return [
			'period_id' => $periodId,
			'as_of' => $asOfDate,
			'generated' => $persisted,
			'errors' => $errors,
			'hierarchy_report' => $hierarchyReport,
		];
	}//end generate()

	/**
	 * Build the full snapshot set (rep -> team -> division -> company).
	 *
	 * Pure: takes the resolved hierarchy and the deals grouped by rep, and
	 * returns the snapshot records without touching OpenRegister.
	 *
	 * @param string $periodId The period id.
	 * @param string $asOfDate The ISO snapshot date.
	 * @param array<string, mixed> $hierarchy teams (team=>reps), divisions (division=>teams).
	 * @param array<string, array<int, array<string, mixed>>> $dealsByRep Deals keyed by rep id.
	 *
	 * @return array<int, array<string, mixed>> The snapshot records.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-005-01
	 */
	public function buildSnapshots(string $periodId, string $asOfDate, array $hierarchy, array $dealsByRep): array {
		$run = new SnapshotRun(periodId: $periodId, asOfDate: $asOfDate, currency: $this->exchangeRate->getReportingCurrency());
		$teams = $hierarchy['teams'] ?? [];
		$divisions = $hierarchy['divisions'] ?? [];

		$snapshots = [];
		$repTotals = [];
		$rates = [];

		// Rep level.
		foreach ($teams as $repIds) {
			foreach ($repIds as $repId) {
				$bucket = $this->rollup->bucketDeals($dealsByRep[$repId] ?? []);
				$repTotals[$repId] = $bucket['totals'];
				$rates = array_merge($rates, ($bucket['rates'] ?? []));
				$record = $this->snapshotRecord(run: $run, node: new SnapshotNode(ownerId: $repId, level: 'rep', totals: $bucket['totals']));
				$record['deal_snapshot_ids'] = $bucket['deal_ids'];
				$snapshots[] = $record;
			}
		}

		$team = $this->rollUpLevel(run: $run, level: new SnapshotLevel(level: 'team', groups: $teams, childTotals: $repTotals));
		$division = $this->rollUpLevel(run: $run, level: new SnapshotLevel(level: 'division', groups: $divisions, childTotals: $team['totals']));

		// Company level. When no divisions are configured, roll teams directly.
		$companyChildren = array_values($team['totals']);
		if ($division['totals'] !== []) {
			$companyChildren = array_values($division['totals']);
		}

		$snapshots = [...$snapshots, ...$team['snapshots'], ...$division['snapshots']];
		$company = new SnapshotNode(ownerId: self::COMPANY_OWNER, level: 'company', totals: $this->rollup->sumChildTotals($companyChildren));
		$snapshots[] = $this->snapshotRecord(run: $run, node: $company);

		return $this->withRates(snapshots: $snapshots, rates: $rates);
	}//end buildSnapshots()

	/**
	 * Snapshot every group of one level from the totals of the level below.
	 *
	 * @param SnapshotRun   $run   The run.
	 * @param SnapshotLevel $level The level, its groups and the child totals.
	 *
	 * @return array{snapshots: array<int, array<string, mixed>>, totals: array<int|string, array<string, float>>}
	 */
	private function rollUpLevel(SnapshotRun $run, SnapshotLevel $level): array {
		$snapshots = [];
		$totals = [];
		foreach ($level->groups as $groupId => $childIds) {
			$collected = $this->collectChildTotals(ids: $childIds, sourceTotals: $level->childTotals);
			$totals[$groupId] = $this->rollup->sumChildTotals($collected['children']);
			$node = new SnapshotNode(ownerId: (string)$groupId, level: $level->level, totals: $totals[$groupId], missing: $collected['missing']);
			$snapshots[] = $this->snapshotRecord(run: $run, node: $node);
		}

		return ['snapshots' => $snapshots, 'totals' => $totals];
	}//end rollUpLevel()

	/**
	 * Attach the rates the run converted at to every snapshot, so the
	 * forecast can say which rate, from where and of which day.
	 *
	 * @param array<int, array<string, mixed>> $snapshots The snapshot records.
	 * @param array<string, array<string, mixed>> $rates Currency => rate metadata.
	 *
	 * @return array<int, array<string, mixed>> The records, with `exchange_rates` when any deal was foreign.
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
	 */
	private function withRates(array $snapshots, array $rates): array {
		if ($rates === []) {
			return $snapshots;
		}

		ksort($rates);
		foreach (array_keys($snapshots) as $index) {
			$snapshots[$index]['exchange_rates'] = $rates;
		}

		return $snapshots;
	}//end withRates()

	/**
	 * Collect child totals for a set of ids, tracking any missing children.
	 *
	 * @param array<int, string> $ids Child ids to look up.
	 * @param array<string, array<string, float>> $sourceTotals Totals keyed by child id.
	 *
	 * @return array{children: array<int, array<string, float>>, missing: array<int, string>}
	 */
	private function collectChildTotals(array $ids, array $sourceTotals): array {
		$children = [];
		$missing = [];
		foreach ($ids as $id) {
			if (isset($sourceTotals[$id]) === false) {
				$missing[] = $id;
				continue;
			}

			$children[] = $sourceTotals[$id];
		}

		return ['children' => $children, 'missing' => $missing];
	}//end collectChildTotals()

	/**
	 * Assemble a single snapshot record, without deal ids.
	 *
	 * @param SnapshotRun  $run  The period, date and currency.
	 * @param SnapshotNode $node The owner, level, amounts and missing children.
	 *
	 * @return array<string, mixed> The snapshot record.
	 */
	private function snapshotRecord(SnapshotRun $run, SnapshotNode $node): array {
		$quota = $this->quotaFacts(run: $run, node: $node);
		$owner = ['period_id' => $run->periodId, 'as_of_date' => $run->asOfDate, 'owner_id' => $node->ownerId, 'level' => $node->level];
		$state = [
			'quota_amount' => $quota['quota_amount'],
			'currency' => $run->currency,
			'deal_snapshot_ids' => [],
			'partial' => ($node->missing !== []),
			'missing_reps' => $node->missing,
		];

		return array_merge($owner, $this->amounts(node: $node), $state, array_diff_key($quota, ['quota_amount' => true]));
	}//end snapshotRecord()

	/**
	 * The four forecast amounts of a node, in record order.
	 *
	 * @param SnapshotNode $node The owner, level and amounts.
	 *
	 * @return array<string, mixed>
	 */
	private function amounts(SnapshotNode $node): array {
		return [
			'commit_amount' => $node->totals['commit_amount'],
			'best_case_amount' => $node->totals['best_case_amount'],
			'pipeline_amount' => $node->totals['pipeline_amount'],
			'closed_won_amount' => $node->totals['closed_won_amount'],
		];
	}//end amounts()

	/**
	 * The quota, the days left, whether the period is closed and whether the owner is at risk.
	 *
	 * @param SnapshotRun  $run  The period and date.
	 * @param SnapshotNode $node The owner, level and amounts.
	 *
	 * @return array{quota_amount: float|null, days_remaining: int, period_closed: bool, at_risk: bool}
	 */
	private function quotaFacts(SnapshotRun $run, SnapshotNode $node): array {
		$quota = $this->quotaService->getQuotaAmount(ownerId: $node->ownerId, periodId: $run->periodId, level: $node->level);
		$reference = new DateTimeImmutable($run->asOfDate);
		$daysRemaining = $this->period->daysRemaining(periodId: $run->periodId, now: $reference);
		$periodClosed = $this->period->isClosed(periodId: $run->periodId, now: $reference);
		$atRisk = $this->atRisk(node: $node, limits: ['quota' => $quota, 'daysRemaining' => $daysRemaining]);

		return ['quota_amount' => $quota, 'days_remaining' => $daysRemaining, 'period_closed' => $periodClosed, 'at_risk' => $atRisk];
	}//end quotaFacts()

	/**
	 * Whether the owner's projected attainment puts its quota at risk; never
	 * without a positive quota.
	 *
	 * @param SnapshotNode                                     $node   The owner and amounts.
	 * @param array{quota: float|null, daysRemaining: int}     $limits The quota and the days left.
	 *
	 * @return bool
	 */
	private function atRisk(SnapshotNode $node, array $limits): bool {
		$projected = $this->quotaService->projectedAttainment(
			closedWon: (float)$node->totals['closed_won_amount'],
			commit: (float)$node->totals['commit_amount'],
			bestCase: (float)$node->totals['best_case_amount']
		);
		if ($limits['quota'] === null || $limits['quota'] <= 0) {
			return false;
		}

		return $this->quotaService->isAtRisk(projected: $projected, quota: $limits['quota'], daysRemaining: $limits['daysRemaining']);
	}//end atRisk()

	/**
	 * Resolve the rep/team/division hierarchy from NC groups + config.
	 *
	 * @return array<string, mixed> teams (team=>reps), divisions (division=>teams).
	 */
	private function resolveHierarchy(): array {
		$teamsRaw = $this->appConfig->getValueString(Application::APP_ID, self::TEAMS_KEY, '');
		$teamIds = array_values(array_filter(array_map('trim', explode(',', $teamsRaw)), static fn (string $t): bool => $t !== ''));

		$teams = [];
		foreach ($teamIds as $teamId) {
			$group = $this->groupManager->get($teamId);
			$members = [];
			if ($group !== null) {
				foreach ($group->getUsers() as $user) {
					$members[] = $user->getUID();
				}
			}

			$teams[$teamId] = $members;
		}

		$divisions = [];
		$mapRaw = $this->appConfig->getValueString(Application::APP_ID, self::DIVISION_MAP_KEY, '');
		if ($mapRaw !== '') {
			$decoded = json_decode($mapRaw, true);
			if (is_array($decoded) === true) {
				foreach ($decoded as $teamId => $divisionId) {
					$divisions[(string)$divisionId][] = (string)$teamId;
				}
			}
		}

		return ['teams' => $teams, 'divisions' => $divisions];
	}//end resolveHierarchy()

	/**
	 * Fetch all open deals grouped by rep (assignee).
	 *
	 * @return array<string, array<int, array<string, mixed>>> Deals keyed by rep id.
	 */
	private function fetchDealsByRep(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: snapshot deal fetch failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$byRep = [];
		foreach (($results ?? []) as $result) {
			$data = $this->toArray(object: $result);
			$rep = (string)($data['assignee'] ?? '');
			if ($rep === '') {
				continue;
			}

			$byRep[$rep][] = $data;
		}

		return $byRep;
	}//end fetchDealsByRep()

	/**
	 * Persist a snapshot record to OpenRegister.
	 *
	 * @param array<string, mixed> $snapshot The snapshot record.
	 *
	 * @return void
	 */
	private function persistSnapshot(array $snapshot): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'forecastSnapshot_schema', '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Forecast snapshot schema is not configured.');
		}

		$this->getObjectService()->saveObject(
			object: $snapshot,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: null
		);
	}//end persistSnapshot()

	/**
	 * Notify the pipelinq admin about partial snapshot failures.
	 *
	 * @param string $periodId The period id.
	 * @param array<int, string> $errors The error lines.
	 *
	 * @return void
	 */
	private function notifyAdmin(string $periodId, array $errors): void {
		try {
			$adminGroup = $this->groupManager->get('admin');
			if ($adminGroup === null) {
				return;
			}

			foreach ($adminGroup->getUsers() as $admin) {
				$this->notifier->sendNotification(
					userId: $admin->getUID(),
					subject: 'forecast_snapshot_partial_failure',
					parameters: [
						'period' => $periodId,
						'errors' => implode('; ', $errors),
					]
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to notify admin of snapshot failures',
				[
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end notifyAdmin()

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
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
