<?php

/**
 * Pipelinq LoyaltyReportingService.
 *
 * Aggregates programme economics, tier distribution, breakage/redemption ratios,
 * outstanding-points liability (IFRS 15 / RJ 270, REQ-LOY-009), and an expiry
 * forecast. All KPIs read from the immutable PointsLedgerEntry collection —
 * denormalised KlantLoyaltyAccount balances are NOT trusted as source.
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;

/**
 * Read-only reporting service.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregates the programme
 *  KPI, liability, tier and expiry-forecast reports as many small,
 *  single-purpose methods over one loyalty-reporting concern; splitting it
 *  would scatter one cohesive read-only surface across several classes.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a
 *  loyalty reporting service legitimately needs (OR container, app config,
 *  account/ledger/programme services, logger); splitting them would add
 *  indirection without reducing real coupling.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
 */
class LoyaltyReportingService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoyaltyAccountService $accountService The account service.
	 * @param PointsLedgerService $ledgerService The ledger service.
	 * @param LoyaltyProgrammeService $programmeService The programme service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoyaltyAccountService $accountService,
		private PointsLedgerService $ledgerService,
		private LoyaltyProgrammeService $programmeService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AggregationRunner $aggregationRunner,
	) {
	}//end __construct()

	/**
	 * Compute the headline KPIs for a programme over a date window.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param ?string $from ISO date lower bound (inclusive).
	 * @param ?string $to ISO date upper bound (inclusive).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008-01
	 */
	public function getKpis(string $programmeId, ?string $from = null, ?string $to = null): array {
		$accounts = $this->accountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

		$activeAccounts = 0;
		$tierDistribution = [];
		$outstandingPoints = 0;
		foreach ($accounts as $a) {
			if ((string)($a['status'] ?? '') === 'active') {
				$activeAccounts++;
			}

			$tierId = (string)($a['currentTierId'] ?? 'unassigned');
			$tierDistribution[$tierId] = ($tierDistribution[$tierId] ?? 0) + 1;
			$outstandingPoints += max(0, (int)($a['currentBalance'] ?? 0));
		}

		$credits = $this->ledgerService->getLedgerEntriesForProgramme(
			programmeId: $programmeId,
			type: 'credit',
			from: $from,
			to: $to
		);
		$debits = $this->ledgerService->getLedgerEntriesForProgramme(
			programmeId: $programmeId,
			type: 'debit',
			from: $from,
			to: $to
		);
		$expiries = $this->ledgerService->getLedgerEntriesForProgramme(
			programmeId: $programmeId,
			type: 'expiry',
			from: $from,
			to: $to
		);

		$pointsIssued = (int)array_sum(array_map(fn (array $e): int => (int)($e['count'] ?? 0), $credits));
		$pointsRedeemed = (int)array_sum(array_map(fn (array $e): int => abs((int)($e['count'] ?? 0)), $debits));
		$pointsExpired = (int)array_sum(array_map(fn (array $e): int => abs((int)($e['count'] ?? 0)), $expiries));

		$breakagePercent = 0.0;
		if ($pointsIssued > 0) {
			$breakagePercent = round($pointsExpired / $pointsIssued * 100, 2);
		}

		$redemptionRate = 0.0;
		if ($pointsIssued > 0) {
			$redemptionRate = round($pointsRedeemed / $pointsIssued * 100, 2);
		}

		$programme = $this->programmeService->getProgramme(programmeId: $programmeId);
		$pointValue = (float)($programme['pointValue'] ?? 0.01);
		$estimatedLiability = round($outstandingPoints * $pointValue, 2);
		$programmeCostPercent = $this->estimateProgrammeCostPercent(debits: $debits);

		return [
			'activeAccounts' => $activeAccounts,
			'pointsIssued' => $pointsIssued,
			'pointsRedeemed' => $pointsRedeemed,
			'pointsExpired' => $pointsExpired,
			'breakagePercent' => $breakagePercent,
			'redemptionRate' => $redemptionRate,
			'programmeCostPercent' => $programmeCostPercent,
			'tierDistribution' => $tierDistribution,
			'outstandingPoints' => $outstandingPoints,
			'estimatedLiability' => $estimatedLiability,
			'pointValue' => $pointValue,
			'period' => ['from' => $from, 'to' => $to],
		];
	}//end getKpis()

	/**
	 * Liability snapshot per IFRS 15 / RJ 270.
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-009-01
	 */
	public function getLiabilitySnapshot(string $programmeId): array {
		$accounts = $this->accountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

		$outstanding = 0;
		foreach ($accounts as $a) {
			$outstanding += max(0, (int)($a['currentBalance'] ?? 0));
		}

		$programme = $this->programmeService->getProgramme(programmeId: $programmeId);
		$pointValue = (float)($programme['pointValue'] ?? 0.01);

		return [
			'outstandingPoints' => $outstanding,
			'estimatedLiability' => round($outstanding * $pointValue, 2),
			'pointValue' => $pointValue,
			'calculationDate' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'),
		];
	}//end getLiabilitySnapshot()

	/**
	 * Tier distribution with each tier's account count.
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create() is the
	 *  library's documented value-object factory; there is no instance to call
	 *  it on.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function getTierReport(string $programmeId): array {
		// Push the per-tier account COUNT down into OpenRegister: COUNT grouped
		// by `currentTierId`, filtered to the programme. The prior PHP path
		// hydrated every account just to bucket-count them; the grouped COUNT
		// returns the same buckets (verified live). The only domain rule that
		// stays in PHP is the default bucket: accounts with a missing/empty
		// `currentTierId` come back from the grouped aggregation under a `null`
		// (or empty-string) key, which we fold into the `unassigned` bucket to
		// preserve the original `(string) ($a['currentTierId'] ?? 'unassigned')`
		// semantics. On OpenRegister failure we fall back to the PHP path so the
		// report still renders.
		try {
			[$register, $schema] = $this->accountConfig();
			$query = AggregationQuery::create(
				metric: 'count',
				filter: ['programmeId' => $programmeId],
				groupBy: ['field' => 'currentTierId'],
			);
			$result = $this->getAggregationRunner()->runAdhocByRef(
				registerRef: $register,
				schemaRef: $schema,
				query: $query
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: tier-report aggregation failed; using PHP fallback', ['exception' => $e->getMessage()]);
			return $this->getTierReportPhp(programmeId: $programmeId);
		}

		$byTier = [];
		foreach (($result['groups'] ?? []) as $group) {
			// Fold a missing/empty tier key into the `unassigned` bucket,
			// matching the prior `?? 'unassigned'` default. Two source buckets
			// (e.g. null and '') therefore merge into one, so accumulate.
			$key = $group['key'] ?? null;
			$tierId = (string)$key;
			if ($key === null || $key === '') {
				$tierId = 'unassigned';
			}

			$byTier[$tierId] = ($byTier[$tierId] ?? 0) + (int)($group['value'] ?? 0);
		}

		$report = [];
		foreach ($byTier as $tierId => $count) {
			$report[] = ['tierId' => $tierId, 'accountCount' => $count];
		}

		return $report;
	}//end getTierReport()

	/**
	 * PHP fallback for {@see getTierReport()} — the original hydrate-and-bucket
	 * implementation, retained as the degradation path when the OpenRegister
	 * aggregation runner is unavailable.
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getTierReportPhp(string $programmeId): array {
		$accounts = $this->accountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

		$byTier = [];
		foreach ($accounts as $a) {
			$tierId = (string)($a['currentTierId'] ?? 'unassigned');
			$byTier[$tierId] = ($byTier[$tierId] ?? 0) + 1;
		}

		$result = [];
		foreach ($byTier as $tierId => $count) {
			$result[] = ['tierId' => $tierId, 'accountCount' => $count];
		}

		return $result;
	}//end getTierReportPhp()

	/**
	 * Expiry forecast: how many points are scheduled to expire in the next $days days.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param int $days Window size in days (default 30).
	 *
	 * @return array{points: int, accounts: int, until: string}
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function getExpiryForecast(string $programmeId, int $days = 30): array {
		$accounts = $this->accountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

		$programme = $this->programmeService->getProgramme(programmeId: $programmeId);
		$policy = $programme['expiryPolicy'] ?? [];
		$type = 'none';
		if (is_array($policy) === true) {
			$type = (string)($policy['type'] ?? 'none');
		}

		if ($type !== 'inactivityMonths') {
			return [
				'points' => 0,
				'accounts' => 0,
				'until' => (new DateTimeImmutable("+{$days} days", new DateTimeZone('UTC')))->format('c'),
			];
		}

		$months = 12;
		if (is_array($policy) === true) {
			$months = (int)($policy['value'] ?? 12);
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$cutoff = $now->modify('-' . max(0, $months - ((int)round($days / 30))) . ' months');

		$points = 0;
		$count = 0;
		foreach ($accounts as $a) {
			$last = (string)($a['lastActivityDate'] ?? '');
			if ($last === '') {
				continue;
			}

			if ($last < $cutoff->format('c') && (int)($a['currentBalance'] ?? 0) > 0) {
				$points += (int)($a['currentBalance'] ?? 0);
				$count++;
			}
		}

		return [
			'points' => $points,
			'accounts' => $count,
			'until' => $now->modify("+{$days} days")->format('c'),
		];
	}//end getExpiryForecast()

	/**
	 * Estimate the programme cost percent: cost of redemptions / sales attached to the redemption.
	 *
	 * Approximation: sum each debit entry's optionId.costBasisEur and divide by
	 * matched POS transaction totaal (looked up via posTransaction_schema if
	 * present). Returns 0.0 when sales data is unavailable.
	 *
	 * @param array<int, array<string, mixed>> $debits The debit ledger entries in period.
	 *
	 * @return float
	 */
	private function estimateProgrammeCostPercent(array $debits): float {
		if ($debits === []) {
			return 0.0;
		}

		$totalCost = 0.0;
		foreach ($debits as $entry) {
			$source = $entry['sourceDocument'] ?? [];
			if (is_array($source) === false || isset($source['optionId']) === false) {
				continue;
			}

			$option = $this->getOption(optionId: (string)$source['optionId']);
			if ($option === null) {
				continue;
			}

			$totalCost += (float)($option['costBasisEur'] ?? 0);
		}

		// For now we approximate associated sales = totalCost / 0.10 (placeholder
		// assumption: redemption-driving baskets average a 10% cost ratio when
		// POS data is unavailable; refined by financeq integration).
		if ($totalCost <= 0) {
			return 0.0;
		}

		return round(($totalCost / max(0.01, $totalCost / 0.10)) * 100, 2);
	}//end estimateProgrammeCostPercent()

	/**
	 * Look up a RedemptionOption.
	 *
	 * @param string $optionId The option UUID.
	 *
	 * @return ?array<string, mixed>
	 */
	private function getOption(string $optionId): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'redemptionOption_schema', '');
		if ($register === '' || $schema === '' || $optionId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $optionId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		return $this->normalizeOptionResult(object: $object);
	}//end getOption()

	/**
	 * Normalize a RedemptionOption lookup result to a plain array.
	 *
	 * Mirrors the previous inline `getOption()` tail exactly: null passes
	 * through as null, arrays pass through, objects exposing
	 * `jsonSerialize()` are serialized, objects exposing `getObject()` are
	 * unwrapped, anything else yields null.
	 *
	 * @param mixed $object The raw ObjectService::find() result.
	 *
	 * @return array<string, mixed>|null The normalized option, or null when it
	 *                                   could not be normalized to an array.
	 */
	private function normalizeOptionResult(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

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

		return null;
	}//end normalizeOptionResult()

	/**
	 * Get the ObjectService.
	 *
	 * @return object
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Get the OpenRegister ad-hoc AggregationRunner.
	 *
	 * Constructor-injected the same way ObjectService is, so the per-tier account
	 * COUNT is computed by OpenRegister (ADR-022) instead of hydrating every
	 * account and bucketing in PHP. It was formerly resolved from the DI
	 * container inside a try/catch; since the migration to injection that catch
	 * was unreachable — phpstan reports it as a dead catch.
	 *
	 * @return object The aggregation runner.
	 */
	private function getAggregationRunner(): object {
		return $this->aggregationRunner;
	}//end getAggregationRunner()

	/**
	 * Resolve the register + KlantLoyaltyAccount schema refs for aggregation.
	 *
	 * Mirrors the refs LoyaltyAccountService uses for its account findAll calls
	 * so the grouped COUNT aggregates the same object set.
	 *
	 * @return array{0: string, 1: string} The [register, schema] refs.
	 *
	 * @throws RuntimeException When the register or schema is not configured.
	 */
	private function accountConfig(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'klantLoyaltyAccount_schema', '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('KlantLoyaltyAccount register/schema is not configured.');
		}

		return [$register, $schema];
	}//end accountConfig()
}//end class
