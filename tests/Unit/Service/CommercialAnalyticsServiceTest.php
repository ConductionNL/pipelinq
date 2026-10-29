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
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\AnalyticsService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
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
	 * @param array<string, array<int, array<string, mixed>>> $byCollection   Per-schema fixture rows.
	 * @param int                                              $pipelineTarget The configured pipeline target, 0 for none.
	 *
	 * @return AnalyticsService
	 */
	private function buildService(array $byCollection, int $pipelineTarget = 0): AnalyticsService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				return $key === 'register' ? 'register-1' : $key;
			}
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $appId, string $key, int $default = 0) use ($pipelineTarget): int {
				if ($key === 'pipeline_target') {
					return $pipelineTarget;
				}

				return $default;
			}
		);

		$objectService = $this->createFakeObjectService(byCollection: $byCollection);

		$logger = $this->createMock(LoggerInterface::class);

		// Commercial KPIs read leads + POS only, so the ticket resolver is a
		// stub that never yields rows.
		$ticketService = $this->createMock(TicketService::class);
		$ticketService->method('isConfigured')->willReturn(true);
		$ticketService->method('getRegisterId')->willReturn('register-1');
		$ticketService->method('getSchemaId')->willReturn('ticket_schema');
		$ticketService->method('findByType')->willReturn([]);

		return new AnalyticsService(
			appConfig: $appConfig,
			logger: $logger,
			ticketService: $ticketService,
			objectService: $objectService,
		);
	}

	/**
	 * A fixture-backed ObjectService satisfying the ADR-084 contract.
	 *
	 * Extends OpenRegister's ObjectService so the double satisfies the
	 * `ObjectServiceInterface` type-hint; `findAll()` repeats the parent
	 * signature EXACTLY, because PHP checks compatibility at class-load time.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $byCollection Per-schema fixture rows.
	 *
	 * @return ObjectServiceInterface
	 */
	private function createFakeObjectService(array $byCollection): ObjectServiceInterface {
		return new class($byCollection) extends ObjectService {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $byCollection Fixtures.
			 */
			public function __construct(
				private array $byCollection,
			) {
			}

			/**
			 * @param array<string, mixed> $config        Query config.
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
				$schema = (string)($config['filters']['schema'] ?? '');
				return $this->byCollection[$schema] ?? [];
			}
		};
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
				['id' => 'D', 'status' => 'open', 'value' => 2000, 'client' => 'c1', 'qualificationScore' => 50, 'stage' => 'Qualification', 'stageOrder' => 2],
				['id' => 'E', 'status' => 'open', 'value' => 4000, 'client' => 'c2', 'qualificationScore' => 25, 'stage' => 'Proposal',      'stageOrder' => 3],
				['id' => 'F', 'status' => 'open', 'value' => 1000, 'client' => 'c1', 'qualificationScore' => 75, 'stage' => 'Qualification', 'stageOrder' => 2],
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
		// weighted on the qualification score: 2000*0.5 + 4000*0.25 + 1000*0.75 = 2750.
		$this->assertSame(2750.0, $overview['weightedForecast']);
		$this->assertArrayHasKey('previousPeriod', $overview);
		$this->assertSame('month', $overview['period']);
	}

	/**
	 * The weighted forecast reads the qualification score, never the hidden probability field.
	 *
	 * The lead form hides `probability`, so on a real install it is empty and the
	 * forecast read EUR 0 for a 250000 lead with score 65 (pipelinq review F3).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/commercial-dashboard/spec.md
	 */
	public function testWeightedForecastUsesQualificationScoreNotProbability(): void {
		$data = $this->fixture();
		$data['lead_schema'] = [
			['id' => 'R', 'status' => 'open', 'value' => 250000, 'client' => 'c1', 'qualificationScore' => 65],
			['id' => 'S', 'status' => 'open', 'value' => 1000, 'client' => 'c1', 'probability' => 90],
			['id' => 'T', 'status' => 'open', 'value' => 1000, 'client' => 'c1', 'qualificationScore' => 140],
		];

		$overview = $this->buildService($data)->getCommercialOverview(period: 'month');

		// 250000*0.65 + 0 (no score) + 1000*1.0 (score capped at 100) = 163500.
		$this->assertSame(163500.0, $overview['weightedForecast']);
	}

	/**
	 * The open-pipeline figures follow a lead filter (the leads list's
	 * pipeline and active filters, board PqLeads), and a list value matches
	 * any of its values; a key that is not a plain field name is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leads-list-board-line/specs/leads-list-board-line/spec.md#requirement-the-weighted-forecast-follows-the-list
	 */
	public function testOpenPipelineFollowsALeadFilter(): void {
		$data = $this->fixture();
		$data['lead_schema'] = [
			['id' => 'A', 'status' => 'open', 'value' => 1000, 'pipeline' => 'sales', 'qualificationScore' => 50],
			['id' => 'B', 'status' => 'open', 'value' => 2000, 'pipeline' => 'partners', 'qualificationScore' => 100],
			['id' => 'C', 'status' => 'open', 'value' => 4000, 'pipeline' => 'sales', 'priority' => 'high', 'qualificationScore' => 25],
		];
		$service = $this->buildService(byCollection: $data);

		$sales = $service->getCommercialOverview(period: 'month', leadFilter: ['pipeline' => 'sales']);
		$this->assertSame(expected: 1500.0, actual: $sales['weightedForecast']);
		$this->assertSame(expected: 5000.0, actual: $sales['openPipelineValue']);

		$high = $service->getCommercialOverview(period: 'month', leadFilter: ['pipeline' => ['sales', 'partners'], 'priority' => 'high']);
		$this->assertSame(expected: 1000.0, actual: $high['weightedForecast']);

		$odd = $service->getCommercialOverview(period: 'month', leadFilter: ['@self[updated]' => 'x']);
		$this->assertSame(expected: 3500.0, actual: $odd['weightedForecast']);
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

	/**
	 * The overview counts the deals behind its figures, so a tile can say what
	 * its number is made of (board PqVerkoopoverzicht: "1 deal gewonnen",
	 * "1 gewonnen, 1 verloren", "geen lead heeft een score").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-overview-board/specs/commercial-dashboard/spec.md#requirement-the-commercial-overview-counts-the-deals-behind-its-figures
	 */
	public function testCommercialOverviewCountsTheDealsBehindItsFigures(): void {
		$data = $this->fixture();
		$data['lead_schema'][] = ['id' => 'G', 'status' => 'open', 'value' => 900, 'client' => 'c2'];

		$overview = $this->buildService(byCollection: $data)->getCommercialOverview(period: 'month');

		$this->assertSame(expected: 2, actual: $overview['wonCount']);
		$this->assertSame(expected: 1, actual: $overview['lostCount']);
		$this->assertSame(expected: 4, actual: $overview['openCount']);
		$this->assertSame(expected: 3, actual: $overview['scoredOpenCount']);
		// Won 4000 of open 7900 is 50.6%, written whole.
		$this->assertSame(expected: 51, actual: $overview['wonShareOfOpen']);
		$this->assertArrayHasKey(key: 'lostCount', array: $overview['previousPeriod']);
	}

	/**
	 * The pipeline cover is the open pipeline over what is still to come in
	 * against the target: target 9000, won 4000, open 7000 reads 1.4 times.
	 * Without a target, or with the target already met, there is no cover.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-overview-board/specs/commercial-dashboard/spec.md#requirement-the-commercial-overview-gives-the-pipeline-cover
	 */
	public function testCommercialOverviewGivesThePipelineCover(): void {
		$withTarget = $this->buildService(byCollection: $this->fixture(), pipelineTarget: 9000)->getCommercialOverview(period: 'month');
		$this->assertSame(expected: 5000.0, actual: $withTarget['targetRemaining']);
		$this->assertSame(expected: 1.4, actual: $withTarget['pipelineCover']);

		$noTarget = $this->buildService(byCollection: $this->fixture())->getCommercialOverview(period: 'month');
		$this->assertNull(actual: $noTarget['pipelineCover']);
		$this->assertNull(actual: $noTarget['targetRemaining']);

		$nothingOpen = $this->buildService(byCollection: ['lead_schema' => []])->getCommercialOverview(period: 'month');
		$this->assertNull(actual: $nothingOpen['wonShareOfOpen']);

		$met = $this->buildService(byCollection: $this->fixture(), pipelineTarget: 3000)->getCommercialOverview(period: 'month');
		$this->assertNull(actual: $met['pipelineCover']);
		$this->assertSame(expected: 0.0, actual: $met['targetRemaining']);
	}
}
