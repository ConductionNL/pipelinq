<?php

/**
 * Unit tests for Seam 1, Batch 2 query pushdown.
 *
 * Each test pins the refactored, OpenRegister-aggregation-backed money/reporting
 * path to the SAME numeric result the prior fetch-all-then-PHP-reduce produced
 * over the identical rows. The pushed-down aggregation is driven by an in-memory
 * {@see FakeAggregationRunner} that mirrors OpenRegister's count/sum (grouped +
 * ungrouped) filter semantics, so the assertion is a true behaviour-preservation
 * guard rather than a tautology against a hand-rolled expectation.
 *
 * Covered candidates:
 *  - PointsLedgerService::getAccountBalance       (SUM aantal, ungrouped)
 *  - CashShiftService::sumConfirmedSales          (SUM total, status IN + date range) — see CashShiftServiceTest
 *  - LoyaltyReportingService::getTierReport       (grouped COUNT by tier, unassigned default)
 *  - PosStaffReportService::staffSalesReport      (grouped COUNT + signed split SUM)
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\LoyaltyProgrammeService;
use OCA\Pipelinq\Service\LoyaltyReportingService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\PosStaffReportService;
use OCA\Pipelinq\Service\PosStaffService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Behaviour-preservation tests for the Batch-2 aggregation pushdown.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the services + fakes each pushdown exercises.
 */
class QueryPushdownBatch2Test extends TestCase {
	/**
	 * Build an IAppConfig stub mapping every *_schema key to itself and
	 * `register` to a stable id.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				if ($key === 'register') {
					return 'reg';
				}

				return $key;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * Build a container that returns the given aggregation runner for the
	 * AggregationRunner id and throws for everything else.
	 *
	 * @param object $runner The fake aggregation runner.
	 *
	 * @return ContainerInterface
	 */
	private function containerWithRunner(object $runner): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($runner) {
				if ($id === 'OCA\OpenRegister\Service\Aggregation\AggregationRunner') {
					return $runner;
				}

				throw new \RuntimeException('unexpected service ' . $id);
			}
		);

		return $container;
	}//end containerWithRunner()

	/**
	 * getAccountBalance pushes a SUM(aantal) down and returns the same integer
	 * the prior PHP sum-over-history produced.
	 *
	 * @return void
	 */
	public function testGetAccountBalanceMatchesPhpSum(): void {
		$rows = [
			['accountId' => 'a1', 'count' => 100],
			['accountId' => 'a1', 'count' => -30],
			['accountId' => 'a1', 'count' => 50],
			['accountId' => 'a2', 'count' => 9999],
		];

		// Oracle: prior PHP path summed signed aantal over the account history.
		$phpBalance = 0;
		foreach ($rows as $r) {
			if ($r['accountId'] === 'a1') {
				$phpBalance += (int)$r['count'];
			}
		}

		$runner = new FakeAggregationRunner($rows);
		$service = new PointsLedgerService(
			$this->containerWithRunner($runner),
			$this->appConfig(),
			$this->createMock(LoyaltyAccountService::class),
			$this->createMock(LoggerInterface::class),
			objectService: $key,
			aggregationRunner: $this->createMock(AggregationRunner::class),
		);

		self::assertSame(120, $phpBalance);
		self::assertSame($phpBalance, $service->getAccountBalance(accountId: 'a1'));
	}//end testGetAccountBalanceMatchesPhpSum()

	/**
	 * An account with no ledger entries balances to 0 (runner returns null →
	 * cast to 0), matching the prior empty-history sum.
	 *
	 * @return void
	 */
	public function testGetAccountBalanceEmptyIsZero(): void {
		$runner = new FakeAggregationRunner([]);
		$service = new PointsLedgerService(
			$this->containerWithRunner($runner),
			$this->appConfig(),
			$this->createMock(LoyaltyAccountService::class),
			$this->createMock(LoggerInterface::class),
			objectService: $key,
			aggregationRunner: $this->createMock(AggregationRunner::class),
		);

		self::assertSame(0, $service->getAccountBalance(accountId: 'nobody'));
	}//end testGetAccountBalanceEmptyIsZero()

	/**
	 * getTierReport pushes a grouped COUNT and reproduces the prior per-tier
	 * bucket counts, folding a missing currentTierId into `unassigned`.
	 *
	 * @return void
	 */
	public function testGetTierReportMatchesPhpBuckets(): void {
		$accounts = [
			['programmeId' => 'p1', 'currentTierId' => 'gold'],
			['programmeId' => 'p1', 'currentTierId' => 'gold'],
			['programmeId' => 'p1', 'currentTierId' => 'silver'],
			['programmeId' => 'p1'],
			// No currentTierId -> unassigned.
			['programmeId' => 'p1', 'currentTierId' => ''],
			// Empty -> unassigned.
			['programmeId' => 'other', 'currentTierId' => 'gold'],
			// Different programme, excluded.
		];

		// Oracle: prior PHP path bucketed by (currentTierId ?? 'unassigned').
		$phpByTier = [];
		foreach ($accounts as $a) {
			if (($a['programmeId'] ?? '') !== 'p1') {
				continue;
			}

			$tier = (string)($a['currentTierId'] ?? 'unassigned');
			if ($tier === '') {
				$tier = 'unassigned';
			}

			$phpByTier[$tier] = ($phpByTier[$tier] ?? 0) + 1;
		}

		$runner = new FakeAggregationRunner($accounts);
		$service = new LoyaltyReportingService(
			$this->containerWithRunner($runner),
			$this->appConfig(),
			$this->createMock(LoyaltyAccountService::class),
			$this->createMock(PointsLedgerService::class),
			$this->createMock(LoyaltyProgrammeService::class),
			$this->createMock(LoggerInterface::class),
			objectService: $key,
			aggregationRunner: $this->createMock(AggregationRunner::class),
		);

		$report = $service->getTierReport(programmeId: 'p1');

		// Flatten the report back to a tier => count map for comparison.
		$aggByTier = [];
		foreach ($report as $row) {
			$aggByTier[(string)$row['tierId']] = (int)$row['accountCount'];
		}

		self::assertSame(['gold' => 2, 'silver' => 1, 'unassigned' => 2], $phpByTier);
		self::assertEqualsCanonicalizing($phpByTier, $aggByTier);
	}//end testGetTierReportMatchesPhpBuckets()

	/**
	 * staffSalesReport reproduces the prior per-staff transactionCount and the
	 * refund-netted total / totalTax via the grouped COUNT + signed split SUM.
	 *
	 * @return void
	 */
	public function testStaffSalesReportMatchesPhpReduce(): void {
		$rows = [
			['staffMemberId' => 's1', 'status' => 'confirmed', 'total' => 100.00, 'totalTax' => 21.00],
			['staffMemberId' => 's1', 'status' => 'settled',   'total' => 50.00,  'totalTax' => 10.50],
			['staffMemberId' => 's1', 'status' => 'refunded',  'total' => 30.00,  'totalTax' => 6.30],
			['staffMemberId' => 's2', 'status' => 'confirmed', 'total' => 200.00, 'totalTax' => 42.00],
			['staffMemberId' => 's2', 'status' => 'draft',     'total' => 9.00,   'totalTax' => 1.00],
			// Draft excluded.
			['staffMemberId' => '',   'status' => 'confirmed', 'total' => 5.00,   'totalTax' => 1.00],
			// Empty staff id excluded.
		];

		// Oracle: prior PHP reduce with per-row sign (refunded -> -1).
		$php = [];
		foreach ($rows as $tx) {
			$staffId = (string)($tx['staffMemberId'] ?? '');
			if ($staffId === '') {
				continue;
			}

			if (in_array((string)$tx['status'], ['confirmed', 'settled', 'refunded'], true) === false) {
				continue;
			}

			$php[$staffId] ??= ['transactionCount' => 0, 'total' => 0.0, 'totalTax' => 0.0];
			$sign = ($tx['status'] === 'refunded') ? -1.0 : 1.0;
			$php[$staffId]['transactionCount']++;
			$php[$staffId]['total'] += ($sign * (float)$tx['total']);
			$php[$staffId]['totalTax'] += ($sign * (float)$tx['totalTax']);
		}

		foreach ($php as &$row) {
			$row['total'] = round($row['total'], 2);
			$row['totalTax'] = round($row['totalTax'], 2);
		}

		unset($row);

		$staffService = $this->createMock(PosStaffService::class);
		$staffService->method('getStaff')->willReturnCallback(
			fn (string $id): array => ['displayName' => 'Name-' . $id]
		);

		$runner = new FakeAggregationRunner($rows);
		$service = new PosStaffReportService(
			$this->containerWithRunner($runner),
			$this->appConfig(),
			$staffService,
			$this->createMock(LoggerInterface::class),
			objectService: $key,
			aggregationRunner: $this->createMock(AggregationRunner::class),
		);

		$report = $service->staffSalesReport();

		$agg = [];
		foreach ($report as $row) {
			$agg[(string)$row['staffMemberId']] = [
				'transactionCount' => (int)$row['transactionCount'],
				'total' => (float)$row['total'],
				'totalTax' => (float)$row['totalTax'],
			];
		}

		// s1: count 3, total 100+50-30=120, tax 21+10.5-6.3=25.2 ; s2: count 1, total 200, tax 42.
		self::assertSame(3, $php['s1']['transactionCount']);
		self::assertSame(120.0, $php['s1']['total']);
		self::assertSame(25.2, $php['s1']['totalTax']);
		self::assertEqualsCanonicalizing($php, $agg);
	}//end testStaffSalesReportMatchesPhpReduce()
}//end class
