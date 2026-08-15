<?php

/**
 * Unit tests for the commercial-dashboard analytics on AnalyticsService.
 *
 * Covers getCommercialOverview (revenue / won value / win rate / avg deal
 * size / weighted forecast / open pipeline value) plus the four commercial
 * trend builders (revenue, pipeline-by-stage, revenue-by-product-category,
 * top-customers).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\AnalyticsService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the commercial KPI overview and the commercial trend builders.
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 */
class CommercialAnalyticsServiceTest extends TestCase {
	/**
	 * Build a service with a fake ObjectService keyed by schema id.
	 *
	 * The IAppConfig mock returns the schema KEY as the schema id, so the
	 * fake ObjectService can resolve fixtures keyed by `lead_schema`,
	 * `posTransaction_schema`, etc.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $byCollection Per-schema fixture rows.
	 *
	 * @return AnalyticsService
	 */
	private function buildService(array $byCollection): AnalyticsService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				return $key === 'register' ? 'register-1' : $key;
			}
		);

		$objectService = new class($byCollection) {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $byCollection Fixtures.
			 */
			public function __construct(
				private array $byCollection,
			) {
			}

			/**
			 * @param array{filters?: array<string, mixed>} $config Query config.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config): array {
				$schema = (string)($config['filters']['schema'] ?? '');
				return $this->byCollection[$schema] ?? [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$logger = $this->createMock(LoggerInterface::class);

		// Commercial KPIs read leads + POS only, so the ticket resolver is a
		// stub that never yields rows.
		$ticketService = $this->createMock(TicketService::class);
		$ticketService->method('isConfigured')->willReturn(true);
		$ticketService->method('getRegisterId')->willReturn('register-1');
		$ticketService->method('getSchemaId')->willReturn('ticket_schema');
		$ticketService->method('findByType')->willReturn([]);

		return new AnalyticsService(
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
			ticketService: $ticketService,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}

	/**
	 * ISO timestamp $days in the past.
	 *
	 * @param int $days Days ago.
	 *
	 * @return string
	 */
	private function daysAgo(int $days): string {
		return (new DateTimeImmutable(sprintf('-%d days', $days)))->format(DateTimeInterface::ATOM);
	}

	/**
	 * A representative commercial fixture set within the last 30 days.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function fixture(): array {
		return [
			'lead_schema' => [
				['id' => 'A', 'status' => 'won',  'value' => 1000, 'client' => 'c1', 'stageEnteredAt' => $this->daysAgo(5)],
				['id' => 'B', 'status' => 'won',  'value' => 3000, 'client' => 'c2', 'stageEnteredAt' => $this->daysAgo(10)],
				['id' => 'C', 'status' => 'lost', 'value' => 500,  'client' => 'c1', 'stageEnteredAt' => $this->daysAgo(7)],
				['id' => 'D', 'status' => 'open', 'value' => 2000, 'client' => 'c1', 'probability' => 50, 'stage' => 'Qualification', 'stageOrder' => 2],
				['id' => 'E', 'status' => 'open', 'value' => 4000, 'client' => 'c2', 'probability' => 25, 'stage' => 'Proposal',      'stageOrder' => 3],
				['id' => 'F', 'status' => 'open', 'value' => 1000, 'client' => 'c1', 'probability' => 75, 'stage' => 'Qualification', 'stageOrder' => 2],
			],
			'posTransaction_schema' => [
				['id' => 't1', 'status' => 'settled',   'total' => 250, 'client' => 'c2', 'settledAt' => $this->daysAgo(3)],
				['id' => 't2', 'status' => 'confirmed', 'total' => 150, 'client' => 'c1', 'confirmedAt' => $this->daysAgo(8)],
				['id' => 't3', 'status' => 'draft',     'total' => 999, 'client' => 'c1', 'settledAt' => $this->daysAgo(2)],
			],
			'posTransactionLine_schema' => [
				['transaction' => 't1', 'product' => 'p1', 'lineTotal' => 250],
				['transaction' => 't2', 'product' => 'p2', 'lineTotal' => 100],
				['transaction' => 't2', 'product' => '',   'lineTotal' => 50],
				['transaction' => 'tX', 'product' => 'p1', 'lineTotal' => 999],
			],
			'product_schema' => [
				['id' => 'p1', 'category' => 'Drinks'],
				['id' => 'p2', 'category' => 'Food'],
			],
			'client_schema' => [
				['id' => 'c1', 'name' => 'Acme'],
				['id' => 'c2', 'name' => 'Globex'],
			],
		];
	}

	/**
	 * getCommercialOverview computes the six commercial figures.
	 *
	 * @return void
	 */
	public function testCommercialOverviewComputesFigures(): void {
		$overview = $this->buildService($this->fixture())->getCommercialOverview(period: 'month');

		// revenue = settled+confirmed POS (250+150) + won-deal value (1000+3000) = 4400.
		$this->assertSame(4400.0, $overview['revenue']);
		$this->assertSame(4000.0, $overview['wonValue']);
		// 2 won / (2 won + 1 lost) = 66.7%.
		$this->assertSame(66.7, $overview['winRate']);
		$this->assertSame(2000.0, $overview['avgDealSize']);
		// open value 2000+4000+1000 = 7000.
		$this->assertSame(7000.0, $overview['openPipelineValue']);
		// weighted: 2000*0.5 + 4000*0.25 + 1000*0.75 = 2750.
		$this->assertSame(2750.0, $overview['weightedForecast']);
		$this->assertArrayHasKey('previousPeriod', $overview);
		$this->assertSame('month', $overview['period']);
	}

	/**
	 * An unknown period is rejected.
	 *
	 * @return void
	 */
	public function testCommercialOverviewRejectsUnknownPeriod(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->buildService([])->getCommercialOverview(period: 'decade');
	}

	/**
	 * pipeline-by-stage sums open-lead value per stage, ordered by stage order.
	 *
	 * @return void
	 */
	public function testPipelineByStageSumsOpenValueOrdered(): void {
		$result = $this->buildService($this->fixture())->getTrends(metric: 'pipeline-by-stage', period: 'quarter');

		$this->assertSame('pipeline-by-stage', $result['metric']);
		$this->assertSame(
			[
				['date' => 'Qualification', 'value' => 3000.0],
				['date' => 'Proposal', 'value' => 4000.0],
			],
			$result['series']
		);
	}

	/**
	 * revenue-by-product-category buckets unresolved lines under "Other".
	 *
	 * @return void
	 */
	public function testRevenueByCategoryBucketsOther(): void {
		$result = $this->buildService($this->fixture())->getTrends(metric: 'revenue-by-product-category', period: 'month');

		$map = [];
		foreach ($result['series'] as $entry) {
			$map[$entry['date']] = $entry['value'];
		}

		$this->assertSame(250.0, $map['Drinks']);
		$this->assertSame(100.0, $map['Food']);
		// The unlinked line on the eligible transaction t2 lands under Other;
		// the line on the non-eligible transaction tX is excluded entirely.
		$this->assertSame(50.0, $map['Other']);
	}

	/**
	 * top-customers groups won-deal + POS revenue by client, names resolved.
	 *
	 * @return void
	 */
	public function testTopCustomersGroupsRevenueByClient(): void {
		$result = $this->buildService($this->fixture())->getTrends(metric: 'top-customers', period: 'month');

		// c2 (Globex): won 3000 + POS 250 = 3250; c1 (Acme): won 1000 + POS 150 = 1150.
		$this->assertSame(
			[
				['date' => 'Globex', 'value' => 3250.0],
				['date' => 'Acme', 'value' => 1150.0],
			],
			$result['series']
		);
	}

	/**
	 * revenue trend buckets settled POS + won-deal value; totals reconcile.
	 *
	 * @return void
	 */
	public function testRevenueTrendTotalsReconcile(): void {
		$result = $this->buildService($this->fixture())->getTrends(metric: 'revenue', period: 'month');

		$this->assertSame('revenue', $result['metric']);
		$total = 0.0;
		foreach ($result['series'] as $entry) {
			$total += (float)$entry['value'];
		}
		// Same 4400 as the overview revenue figure.
		$this->assertSame(4400.0, round($total, 2));
	}
}
