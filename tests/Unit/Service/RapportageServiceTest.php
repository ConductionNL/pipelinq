<?php

/**
 * Unit tests for RapportageService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\RapportageService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RapportageService analytics aggregation.
 */
class RapportageServiceTest extends TestCase {

	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The fixture-backed ObjectService injected into the service.
	 *
	 * @var ObjectServiceInterface
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Set up the test fixtures with a fixed lead dataset.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default): string {
					if ($key === 'register') {
						return 'pipelinq';
					}

					if ($key === 'lead_schema') {
						return 'lead';
					}

					return $default;
				}
			);

		$leads = $this->fixtureLeads();

		// ADR-084: the service now takes an injected ObjectServiceInterface, so the
		// double extends OpenRegister's ObjectService and repeats the findAll()
		// signature EXACTLY (PHP checks compatibility at class-load time).
		$this->objectService = new class($leads) extends ObjectService {

			/**
			 * @param array<int, array<string, mixed>> $leads The lead fixtures.
			 */
			public function __construct(
				private array $leads,
			) {
			}

			/**
			 * @param array<string, mixed> $config        The query configuration.
			 * @param bool                 $_rbac         Whether to enforce RBAC checks.
			 * @param bool                 $_multitenancy Whether to enforce tenant scoping.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(
				array $config = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				return $this->leads;
			}
		};

	}//end setUp()

	/**
	 * Three known leads across stages, sources and statuses.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fixtureLeads(): array {
		return [
			[
				'stage' => 'Nieuw',
				'value' => 1000,
				'probability' => 20,
				'source' => 'referral',
				'status' => 'open',
				'_dateModified' => date('Y-m-d', (time() - 86400 * 3)),
				'_dateCreated' => date('Y-m-d', (time() - 86400 * 5)),
			],
			[
				'stage' => 'Voorstel',
				'value' => 5000,
				'probability' => 50,
				'source' => 'referral',
				'status' => 'won',
				'_dateModified' => date('Y-m-d', (time() - 86400 * 10)),
				'_dateCreated' => date('Y-m-d', (time() - 86400 * 40)),
			],
			[
				'stage' => 'Voorstel',
				'value' => 2000,
				'probability' => 30,
				'source' => 'website',
				'status' => 'lost',
				'_dateModified' => date('Y-m-d', (time() - 86400 * 35)),
				'_dateCreated' => date('Y-m-d', (time() - 86400 * 60)),
			],
		];

	}//end fixtureLeads()

	/**
	 * getStageValues aggregates count and weighted value per stage.
	 *
	 * @return void
	 */
	public function testGetStageValuesAggregatesCountAndValue(): void {
		$service = new RapportageService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->objectService,
		);
		$rows = $service->getStageValues();
		$byStage = [];
		foreach ($rows as $row) {
			$byStage[$row['stage']] = $row;
		}

		$this->assertArrayHasKey('Nieuw', $byStage);
		$this->assertArrayHasKey('Voorstel', $byStage);
		$this->assertSame(1, $byStage['Nieuw']['count']);
		$this->assertSame(2, $byStage['Voorstel']['count']);
		$this->assertSame(1000.0, $byStage['Nieuw']['totalValue']);
		$this->assertSame(7000.0, $byStage['Voorstel']['totalValue']);
		// Weighted: (1000*0.2) = 200 for Nieuw, (5000*0.5)+(2000*0.3)=3100 for Voorstel.
		$this->assertSame(200.0, $byStage['Nieuw']['weightedValue']);
		$this->assertSame(3100.0, $byStage['Voorstel']['weightedValue']);

	}//end testGetStageValuesAggregatesCountAndValue()

	/**
	 * getSourcePerformance computes conversion and avg won value.
	 *
	 * @return void
	 */
	public function testGetSourcePerformanceComputesConversion(): void {
		$service = new RapportageService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->objectService,
		);
		$rows = $service->getSourcePerformance();
		$bySrc = [];
		foreach ($rows as $row) {
			$bySrc[$row['source']] = $row;
		}

		$this->assertSame(2, $bySrc['referral']['total']);
		$this->assertSame(1, $bySrc['referral']['won']);
		$this->assertSame(50.0, $bySrc['referral']['conversionRate']);
		$this->assertSame(5000.0, $bySrc['referral']['avgWonValue']);

		$this->assertSame(1, $bySrc['website']['total']);
		$this->assertSame(0, $bySrc['website']['won']);
		$this->assertSame(0.0, $bySrc['website']['conversionRate']);
		// Zero won => avg is 0 (sentinel for "—" in UI).
		$this->assertSame(0.0, $bySrc['website']['avgWonValue']);

	}//end testGetSourcePerformanceComputesConversion()

	/**
	 * getWinLossAnalysis returns win rate, avg values and days-to-close.
	 *
	 * @return void
	 */
	public function testGetWinLossAnalysisComputesWinRate(): void {
		$service = new RapportageService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->objectService,
		);
		$winLoss = $service->getWinLossAnalysis();

		$this->assertSame(1, $winLoss['wonCount']);
		$this->assertSame(1, $winLoss['lostCount']);
		$this->assertSame(50.0, $winLoss['winRate']);
		$this->assertSame(5000.0, $winLoss['avgWonValue']);
		$this->assertSame(2000.0, $winLoss['avgLostValue']);
		// Days-to-close: won 30d, lost 25d → avg ~27.5d.
		$this->assertGreaterThan(0.0, $winLoss['avgDaysToClose']);

	}//end testGetWinLossAnalysisComputesWinRate()

	/**
	 * getAgingBuckets distributes open leads into the four fixed buckets.
	 *
	 * @return void
	 */
	public function testGetAgingBucketsDistributesOpenLeads(): void {
		$service = new RapportageService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->objectService,
		);
		$rows = $service->getAgingBuckets();
		$byBucket = [];
		foreach ($rows as $row) {
			$byBucket[$row['bucket']] = $row;
		}

		// One open lead with _dateModified 3 days ago → 0-7d bucket.
		$this->assertSame(1, $byBucket['0-7d']['count']);
		// Won + lost leads are excluded from open-aging.
		$this->assertSame(0, $byBucket['8-14d']['count']);
		$this->assertSame(0, $byBucket['30d+']['count']);

	}//end testGetAgingBucketsDistributesOpenLeads()

}//end class
