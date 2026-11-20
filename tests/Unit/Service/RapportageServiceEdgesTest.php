<?php

/**
 * Edge cases of RapportageService: pipeline filter, score clamping, date bounds, aging buckets and a failing read.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\RapportageService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The reports read every lead once and bucket them by stage, source, age and outcome.
 *
 * @covers \OCA\Pipelinq\Service\RapportageService
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class RapportageServiceEdgesTest extends TestCase {

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'crm', 'lead_schema' => 'lead'];

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The service over the given leads.
	 *
	 * @param array<int, array<string, mixed>> $leads The leads findAll answers.
	 *
	 * @return RapportageService
	 */
	private function service(array $leads): RapportageService {
		$this->objects->method('findAll')->willReturn($leads);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return new RapportageService(appConfig: $appConfig, logger: $this->logger, objectService: $this->objects);

	}//end service()

	/**
	 * The stage report keeps one pipeline, skips leads without a stage and clamps the score to 0..100.
	 *
	 * @return void
	 */
	public function testStageValuesFilterAndClamp(): void {
		$rows = $this->service(leads: [
			['pipeline' => 'p-1', 'stage' => 'A', 'value' => 100, 'qualificationScore' => 150],
			['pipeline' => 'p-1', 'stage' => 'A', 'value' => 100, 'qualificationScore' => -20],
			['pipeline' => 'p-1', 'stage' => 'A', 'value' => 100, 'qualificationScore' => 'high'],
			['pipeline' => 'p-1', 'stage' => '', 'value' => 999],
			['pipeline' => 'p-2', 'stage' => 'A', 'value' => 999, 'qualificationScore' => 50],
		])->getStageValues(pipelineId: 'p-1');

		$this->assertSame([['stage' => 'A', 'count' => 3, 'totalValue' => 300.0, 'weightedValue' => 100.0]], $rows);

	}//end testStageValuesFilterAndClamp()

	/**
	 * An empty pipeline id keeps every pipeline.
	 *
	 * @return void
	 */
	public function testAnEmptyPipelineIdKeepsAll(): void {
		$rows = $this->service(leads: [
			['pipeline' => 'p-1', 'stage' => 'A'],
			['pipeline' => 'p-2', 'stage' => 'B'],
		])->getStageValues(pipelineId: '');

		$this->assertSame(['A', 'B'], array_column($rows, 'stage'));

	}//end testAnEmptyPipelineIdKeepsAll()

	/**
	 * The source report filters on the creation date, from @self too, and names a blank source unknown.
	 *
	 * @return void
	 */
	public function testSourcePerformanceHonoursTheDateBounds(): void {
		$rows = $this->service(leads: [
			['source' => '', 'status' => 'won', 'value' => 10, '_dateCreated' => '2026-03-10'],
			['source' => 'web', 'status' => 'open', '@self' => ['created' => '2026-03-15T10:00:00Z']],
			['source' => 'web', 'status' => 'won', 'value' => 30, '_dateCreated' => 1773964800],
			['source' => 'web', 'status' => 'won', 'value' => 999, '_dateCreated' => '2026-01-01'],
			['source' => 'web', 'status' => 'won', 'value' => 999, '_dateCreated' => '2026-06-01'],
			['source' => 'web', 'status' => 'won', 'value' => 999],
			['source' => 'web', 'status' => 'won', 'value' => 999, '_dateCreated' => 'not a date'],
		])->getSourcePerformance(dateFrom: '2026-03-01', dateTo: '2026-03-31');

		$this->assertSame(
			[
				['source' => 'unknown', 'total' => 1, 'won' => 1, 'conversionRate' => 100.0, 'avgWonValue' => 10.0],
				['source' => 'web', 'total' => 2, 'won' => 1, 'conversionRate' => 50.0, 'avgWonValue' => 30.0],
			],
			$rows
		);

	}//end testSourcePerformanceHonoursTheDateBounds()

	/**
	 * An unreadable bound is no bound; a source with no wins averages zero.
	 *
	 * @return void
	 */
	public function testAnUnreadableBoundIsIgnored(): void {
		$rows = $this->service(leads: [
			['source' => 'mail', 'status' => 'open', '_dateCreated' => '2020-01-01'],
			['source' => 'mail', 'status' => 'lost', '_dateCreated' => '2030-01-01'],
		])->getSourcePerformance(dateFrom: 'whenever', dateTo: null);

		$this->assertSame([['source' => 'mail', 'total' => 2, 'won' => 0, 'conversionRate' => 0.0, 'avgWonValue' => 0.0]], $rows);

	}//end testAnUnreadableBoundIsIgnored()

	/**
	 * Open leads are aged on their last change, from @self too; closed and undated leads are not aged.
	 *
	 * @return void
	 */
	public function testAgingBucketsByLastChange(): void {
		$day = 86400;
		$rows = $this->service(leads: [
			['status' => 'open', 'value' => 1, '_dateModified' => gmdate('c', time() - (3 * $day))],
			['status' => 'open', 'value' => 2, '@self' => ['updated' => gmdate('c', time() - (10 * $day))]],
			['status' => 'open', 'value' => 4, '_dateModified' => (time() - (20 * $day))],
			['status' => 'open', 'value' => 8, '_dateModified' => gmdate('c', time() - (45 * $day))],
			['status' => 'open', 'value' => 16, '_dateModified' => gmdate('c', time() + (5 * $day))],
			['status' => 'won', 'value' => 99, '_dateModified' => gmdate('c', time() - (45 * $day))],
			['status' => 'lost', 'value' => 99, '_dateModified' => gmdate('c', time() - (45 * $day))],
			['value' => 99],
		])->getAgingBuckets();

		$this->assertSame(
			[
				['bucket' => '0-7d', 'count' => 2, 'totalValue' => 17.0],
				['bucket' => '8-14d', 'count' => 1, 'totalValue' => 2.0],
				['bucket' => '15-30d', 'count' => 1, 'totalValue' => 4.0],
				['bucket' => '30d+', 'count' => 1, 'totalValue' => 8.0],
			],
			$rows
		);

	}//end testAgingBucketsByLastChange()

	/**
	 * Days to close skip a lead without dates or closed before it was created; empty sides average zero.
	 *
	 * @return void
	 */
	public function testWinLossSkipsUnusableDurations(): void {
		$summary = $this->service(leads: [
			['status' => 'won', 'value' => 100, '_dateCreated' => '2026-01-01', '_dateModified' => '2026-01-11'],
			['status' => 'won', 'value' => 300, '_dateCreated' => '2026-01-10', '_dateModified' => '2026-01-05'],
			['status' => 'won', 'value' => 200],
			['status' => 'open', 'value' => 999],
		])->getWinLossAnalysis();

		$this->assertSame(
			['wonCount' => 3, 'lostCount' => 0, 'winRate' => 100.0, 'avgWonValue' => 200.0, 'avgLostValue' => 0.0, 'avgDaysToClose' => 10.0],
			$summary
		);

	}//end testWinLossSkipsUnusableDurations()

	/**
	 * With no closed leads every figure is zero.
	 *
	 * @return void
	 */
	public function testWinLossWithoutClosedLeads(): void {
		$this->assertSame(
			['wonCount' => 0, 'lostCount' => 0, 'winRate' => 0.0, 'avgWonValue' => 0.0, 'avgLostValue' => 0.0, 'avgDaysToClose' => 0.0],
			$this->service(leads: [['status' => 'open']])->getWinLossAnalysis(dateFrom: '2026-01-01')
		);

	}//end testWinLossWithoutClosedLeads()

	/**
	 * The leads are read under the configured register and schema; unconfigured, nothing is read.
	 *
	 * @return void
	 */
	public function testTheLeadsAreReadUnderTheConfiguredSchema(): void {
		$this->objects->expects($this->once())->method('findAll')->with(['filters' => ['register' => 'crm', 'schema' => 'lead']])->willReturn([]);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$service = new RapportageService(appConfig: $appConfig, logger: $this->logger, objectService: $this->objects);

		$this->assertSame([], $service->getStageValues());

		$this->config = ['register' => 'crm'];
		$this->assertSame([], $service->getStageValues());

	}//end testTheLeadsAreReadUnderTheConfiguredSchema()

	/**
	 * A failing read reports nothing, logged.
	 *
	 * @return void
	 */
	public function testAFailingReadReportsNothing(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->once())->method('warning')->with('Pipelinq: rapportage lead fetch failed', ['exception' => 'down']);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		$service = new RapportageService(appConfig: $appConfig, logger: $this->logger, objectService: $this->objects);

		$this->assertSame(0, $service->getAgingBuckets()[0]['count']);

	}//end testAFailingReadReportsNothing()
}//end class
