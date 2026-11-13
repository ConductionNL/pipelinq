<?php

/**
 * Unit tests for LoyaltyReportingService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\LoyaltyProgrammeService;
use OCA\Pipelinq\Service\LoyaltyReportingService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\ReportingService;
use OCA\Pipelinq\Service\TierService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * LoyaltyReportingService computes a programme's KPIs, liability, tier report and expiry forecast.
 */
class LoyaltyReportingServiceTest extends TestCase {

	/**
	 * The programme's accounts.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $accounts = [];

	/**
	 * Ledger entries per movement type.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $entries = ['credit' => [], 'debit' => [], 'expiry' => []];

	/**
	 * The programme record.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $programme = ['pointValue' => 0.05];

	/**
	 * The CSV calls, as [headers, rows].
	 *
	 * @var list<array{0: list<string>, 1: list<list<string>>}>
	 */
	private array $csv = [];

	/**
	 * The service over doubles.
	 *
	 * @param AggregationRunner|null $runner     The aggregation runner.
	 * @param bool                   $configured Whether the register is configured.
	 *
	 * @return LoyaltyReportingService
	 */
	private function service(?AggregationRunner $runner=null, bool $configured=true): LoyaltyReportingService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => ($configured === true ? $key . '-id' : ''));

		$accounts = $this->createMock(LoyaltyAccountService::class);
		$accounts->method('listAccountsForProgramme')->willReturnCallback(fn (): array => $this->accounts);
		$ledger = $this->createMock(PointsLedgerService::class);
		$ledger->method('getLedgerEntriesForProgramme')->willReturnCallback(fn (string $programmeId, string $type): array => $this->entries[$type]);
		$programmes = $this->createMock(LoyaltyProgrammeService::class);
		$programmes->method('getProgramme')->willReturnCallback(fn (): ?array => $this->programme);
		$tiers = $this->createMock(TierService::class);
		$tiers->method('getTierRules')->willReturn([['@self' => ['id' => 'gold'], 'name' => 'Goud'], ['id' => 'silver', 'name' => 'Zilver'], ['name' => 'no id']]);
		$reporting = $this->createMock(ReportingService::class);
		$reporting->method('generateCsv')->willReturnCallback(function (array $headers, array $rows): string {
			$this->csv[] = [$headers, $rows];
			return 'csv';
		});
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(static function (int|string $id): ?ObjectEntity {
			$costs = ['opt-coffee' => 2.5, 'opt-lunch' => 7.5];
			if (isset($costs[$id]) === false) {
				return null;
			}

			$entity = new ObjectEntity();
			$entity->setUuid((string)$id);
			$entity->setObject(['costBasisEur' => $costs[$id]]);
			return $entity;
		});

		return new LoyaltyReportingService(
			appConfig: $config,
			accountService: $accounts,
			ledgerService: $ledger,
			programmeService: $programmes,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objects,
			aggregationRunner: ($runner ?? new AggregationRunner()),
			tierService: $tiers,
			reportingService: $reporting
		);
	}//end service()

	/**
	 * The KPIs count active accounts, tiers, outstanding points, movements and their rates.
	 *
	 * @return void
	 */
	public function testTheKpis(): void {
		$this->accounts = [
			['status' => 'active', 'currentTierId' => 'gold', 'currentBalance' => 300],
			['status' => 'active', 'currentBalance' => 100],
			['status' => 'frozen', 'currentTierId' => 'gold', 'currentBalance' => -20],
		];
		$this->entries = [
			'credit' => [['count' => 600], ['count' => 400]],
			'debit' => [['count' => -150, 'sourceDocument' => ['optionId' => 'opt-coffee']], ['count' => -50, 'sourceDocument' => ['optionId' => 'gone']], ['count' => -10]],
			'expiry' => [['count' => -25]],
		];

		$kpis = $this->service()->getKpis(programmeId: 'prog-1', from: '2026-10-01', to: '2026-10-31');

		self::assertSame(2, $kpis['activeAccounts']);
		self::assertSame(['gold' => 2, 'unassigned' => 1], $kpis['tierDistribution']);
		self::assertSame(400, $kpis['outstandingPoints']);
		self::assertSame(1000, $kpis['pointsIssued']);
		self::assertSame(210, $kpis['pointsRedeemed']);
		self::assertSame(25, $kpis['pointsExpired']);
		self::assertSame(2.5, $kpis['breakagePercent']);
		self::assertSame(21.0, $kpis['redemptionRate']);
		self::assertSame(0.05, $kpis['pointValue']);
		self::assertSame(20.0, $kpis['estimatedLiability']);
		self::assertSame(10.0, $kpis['programmeCostPercent']);
		self::assertSame(['from' => '2026-10-01', 'to' => '2026-10-31'], $kpis['period']);
	}//end testTheKpis()

	/**
	 * Without points issued the rates are zero, and a missing programme values a point at one cent.
	 *
	 * @return void
	 */
	public function testAnEmptyProgramme(): void {
		$this->programme = null;
		$this->accounts = [['status' => 'active', 'currentBalance' => 1000]];

		$kpis = $this->service()->getKpis(programmeId: 'prog-1');

		self::assertSame(0.0, $kpis['breakagePercent']);
		self::assertSame(0.0, $kpis['redemptionRate']);
		self::assertSame(0.0, $kpis['programmeCostPercent']);
		self::assertSame(0.01, $kpis['pointValue']);
		self::assertSame(10.0, $kpis['estimatedLiability']);
		self::assertSame(['from' => null, 'to' => null], $kpis['period']);
	}//end testAnEmptyProgramme()

	/**
	 * The liability snapshot values the positive balances today.
	 *
	 * @return void
	 */
	public function testTheLiabilitySnapshot(): void {
		$this->accounts = [['currentBalance' => 250], ['currentBalance' => -40], []];

		$snapshot = $this->service()->getLiabilitySnapshot(programmeId: 'prog-1');

		self::assertSame(250, $snapshot['outstandingPoints']);
		self::assertSame(12.5, $snapshot['estimatedLiability']);
		self::assertSame(gmdate('Y-m-d'), $snapshot['calculationDate']);
	}//end testTheLiabilitySnapshot()

	/**
	 * The tier report names the known tiers in rule order and lists the rest after them.
	 *
	 * @return void
	 */
	public function testTheAggregatedTierReport(): void {
		$runner = $this->createMock(AggregationRunner::class);
		$runner->method('runAdhocByRef')->willReturn(['groups' => [['key' => 'gold', 'value' => 4], ['key' => null, 'value' => 2], ['key' => '', 'value' => 1], ['key' => 'legacy', 'value' => 3]]]);

		self::assertSame(
			[
				['tierId' => 'gold', 'accountCount' => 4, 'name' => 'Goud'],
				['tierId' => 'silver', 'accountCount' => 0, 'name' => 'Zilver'],
				['tierId' => 'unassigned', 'accountCount' => 3],
				['tierId' => 'legacy', 'accountCount' => 3],
			],
			$this->service(runner: $runner)->getTierReport(programmeId: 'prog-1')
		);
	}//end testTheAggregatedTierReport()

	/**
	 * Without aggregation the tier report counts the accounts in PHP.
	 *
	 * @return void
	 */
	public function testTheTierReportFallsBackToCounting(): void {
		$this->accounts = [['currentTierId' => 'silver'], ['currentTierId' => 'silver'], []];
		$runner = $this->createMock(AggregationRunner::class);
		$runner->method('runAdhocByRef')->willThrowException(new RuntimeException('no aggregation'));

		$report = $this->service(runner: $runner)->getTierReport(programmeId: 'prog-1');

		self::assertSame([0, 2, 1], array_column($report, 'accountCount'));
		self::assertSame('unassigned', $report[2]['tierId']);
	}//end testTheTierReportFallsBackToCounting()

	/**
	 * Without a configured account schema the tier report also counts in PHP.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredTierReportCountsInPhp(): void {
		$this->accounts = [['currentTierId' => 'gold']];

		self::assertSame(1, $this->service(configured: false)->getTierReport(programmeId: 'prog-1')[0]['accountCount']);
	}//end testAnUnconfiguredTierReportCountsInPhp()

	/**
	 * Without an inactivity policy nothing is forecast to expire.
	 *
	 * @return void
	 */
	public function testNoPolicyForecastsNothing(): void {
		$this->accounts = [['lastActivityDate' => '2001-01-01T00:00:00+00:00', 'currentBalance' => 50]];
		$this->programme = ['expiryPolicy' => ['type' => 'none']];

		$forecast = $this->service()->getExpiryForecast(programmeId: 'prog-1', days: 30);

		self::assertSame(0, $forecast['points']);
		self::assertSame(0, $forecast['accounts']);
	}//end testNoPolicyForecastsNothing()

	/**
	 * Inactive accounts with points before the cut-off are forecast to expire.
	 *
	 * @return void
	 */
	public function testInactiveBalancesAreForecast(): void {
		$this->programme = ['expiryPolicy' => ['type' => 'inactivityMonths', 'value' => 12]];
		$this->accounts = [
			['lastActivityDate' => '2001-01-01T00:00:00+00:00', 'currentBalance' => 50],
			['lastActivityDate' => '2001-01-01T00:00:00+00:00', 'currentBalance' => 0],
			['lastActivityDate' => gmdate('c'), 'currentBalance' => 80],
			['currentBalance' => 999],
		];

		$forecast = $this->service()->getExpiryForecast(programmeId: 'prog-1', days: 30);

		self::assertSame(50, $forecast['points']);
		self::assertSame(1, $forecast['accounts']);
		self::assertGreaterThan(gmdate('c'), $forecast['until']);
	}//end testInactiveBalancesAreForecast()

	/**
	 * KPIs and tiers export as two-column CSV, scalar KPIs only.
	 *
	 * @return void
	 */
	public function testCsvExports(): void {
		$service = $this->service();
		$service->kpisToCsv(kpis: ['activeAccounts' => 2, 'redemptionRate' => 21.5, 'tierDistribution' => ['gold' => 1]]);
		$service->tiersToCsv(tiers: [['name' => 'Goud', 'accountCount' => 4], ['tierId' => 'legacy', 'accountCount' => 3], []]);

		self::assertSame([['metric', 'value'], [['activeAccounts', '2'], ['redemptionRate', '21.5']]], $this->csv[0]);
		self::assertSame([['tier', 'accounts'], [['Goud', '4'], ['legacy', '3'], ['', '0']]], $this->csv[1]);
	}//end testCsvExports()

	/**
	 * The last-days window ends today and counts at least one day back.
	 *
	 * @return void
	 */
	public function testTheLastDaysWindow(): void {
		$window = $this->service()->lastDaysWindow(days: 7);
		self::assertSame(gmdate('Y-m-d'), $window['to']);
		self::assertSame(gmdate('Y-m-d', strtotime('-7 days')), $window['from']);

		self::assertSame(gmdate('Y-m-d', strtotime('-1 day')), $this->service()->lastDaysWindow(days: 0)['from']);
	}//end testTheLastDaysWindow()
}//end class
