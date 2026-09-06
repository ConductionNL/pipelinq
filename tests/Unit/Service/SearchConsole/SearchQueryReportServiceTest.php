<?php

/**
 * Unit tests for SearchQueryReportService.
 *
 * Covers:
 * - rows of one query across days and pages fold into one row
 * - position is the impressions-weighted mean, CTR recomputed from the sums
 * - ordering by clicks then impressions, and the limit
 * - the window defaults to the last 28 days and is read without RBAC
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\SearchConsole
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\SearchConsole;

use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SearchQueryReportService.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
 */
class SearchQueryReportServiceTest extends TestCase {

	/**
	 * Fixed clock: 2026-09-04T10:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1788516000;

	/**
	 * Fake object service recording every call.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Build the report service over fixed rows.
	 *
	 * @param array<int, array<string, mixed>> $rows What findAll answers.
	 *
	 * @return SearchQueryReportService
	 */
	private function build(array $rows = []): SearchQueryReportService {
		$this->objectService = new class ($rows) {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct(private array $rows) {
			}//end __construct()

			/** @return array<int, array<string, mixed>> */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['config' => $config, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return $this->rows;
			}//end findAll()
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->objectService;
		$container->method('get')->willReturn($objectService);

		return new SearchQueryReportService($container, $appConfig, $time, $this->createMock(LoggerInterface::class));
	}//end build()

	/**
	 * @return void
	 */
	public function testAggregatesPerQueryAcrossDaysAndPages(): void {
		$rows = [
			['query' => 'woo', 'page' => '/a', 'date' => '2026-09-01', 'clicks' => 2, 'impressions' => 100, 'position' => 5.0],
			['query' => 'woo', 'page' => '/a', 'date' => '2026-09-02', 'clicks' => 3, 'impressions' => 100, 'position' => 5.0],
			['query' => 'woo', 'page' => '/b', 'date' => '2026-09-03', 'clicks' => 5, 'impressions' => 200, 'position' => 2.0],
			['query' => 'contact', 'page' => '/c', 'date' => '2026-09-03', 'clicks' => 1, 'impressions' => 10, 'position' => 1.0],
			['query' => '', 'page' => '/x', 'date' => '2026-09-03', 'clicks' => 99, 'impressions' => 10, 'position' => 1.0],
		];

		$out = $this->build()->aggregate(rows: $rows);

		$this->assertCount(2, $out);
		$this->assertSame(['query' => 'woo', 'clicks' => 10, 'impressions' => 400, 'ctr' => 0.025, 'position' => 3.5, 'pages' => 2], $out[0]);
		$this->assertSame('contact', $out[1]['query']);
	}//end testAggregatesPerQueryAcrossDaysAndPages()

	/**
	 * @return void
	 */
	public function testPositionIsImpressionWeighted(): void {
		$rows = [
			['query' => 'q', 'page' => '/a', 'clicks' => 0, 'impressions' => 1, 'position' => 1.0],
			['query' => 'q', 'page' => '/a', 'clicks' => 0, 'impressions' => 999, 'position' => 30.0],
		];

		$out = $this->build()->aggregate(rows: $rows);

		$this->assertSame(30.0, $out[0]['position']);
		$this->assertSame(0.0, $out[0]['ctr']);
	}//end testPositionIsImpressionWeighted()

	/**
	 * @return void
	 */
	public function testOrdersByClicksAndHonoursTheLimit(): void {
		$rows = [
			['query' => 'low', 'page' => '/', 'clicks' => 1, 'impressions' => 1000, 'position' => 1.0],
			['query' => 'high', 'page' => '/', 'clicks' => 9, 'impressions' => 10, 'position' => 1.0],
			['query' => 'mid-a', 'page' => '/', 'clicks' => 5, 'impressions' => 50, 'position' => 1.0],
			['query' => 'mid-b', 'page' => '/', 'clicks' => 5, 'impressions' => 40, 'position' => 1.0],
		];
		$report = $this->build($rows)->topQueries(limit: 3);

		$this->assertSame(['high', 'mid-a', 'mid-b'], array_column($report['rows'], 'query'));
		$this->assertSame(4, $report['totalQueries']);
	}//end testOrdersByClicksAndHonoursTheLimit()

	/**
	 * @return void
	 */
	public function testWindowDefaultsToTheLastTwentyEightDaysAndReadsWithoutRbac(): void {
		$report = $this->build()->topQueries();

		$this->assertSame('2026-08-07', $report['from']);
		$this->assertSame('2026-09-04', $report['to']);
		$this->assertSame([], $report['rows']);
		$call = $this->objectService->calls[0];
		$this->assertFalse($call['_rbac']);
		$this->assertFalse($call['_multitenancy']);
		$this->assertSame('searchQueryDaily', $call['config']['filters']['schema']);
		$this->assertSame(['gte' => '2026-08-07', 'lt' => '2026-09-05'], $call['config']['filters']['date']);
		$this->assertArrayNotHasKey('property', $call['config']['filters']);
	}//end testWindowDefaultsToTheLastTwentyEightDaysAndReadsWithoutRbac()

	/**
	 * @return void
	 */
	public function testExplicitWindowAndPropertyAreForwarded(): void {
		$report = $this->build()->topQueries(from: '2026-08-01', to: '2026-08-10', property: ' sc-domain:example.org ');

		$this->assertSame('2026-08-01', $report['from']);
		$this->assertSame('2026-08-10', $report['to']);
		$this->assertSame('sc-domain:example.org', $this->objectService->calls[0]['config']['filters']['property']);
	}//end testExplicitWindowAndPropertyAreForwarded()
}//end class
