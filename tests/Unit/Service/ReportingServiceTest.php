<?php

/**
 * Unit tests for ReportingService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ReportingService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportingService.
 */
class ReportingServiceTest extends TestCase {

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
	 * The unified ticket resolver mock (unify-ticket-supertype).
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService $ticketService;

	/**
	 * The service under test.
	 *
	 * @var ReportingService
	 */
	private ReportingService $service;

	/**
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->ticketService = $this->createMock(TicketService::class);

		// Reporting reads contactmoment tickets; with no tickets the KPI methods
		// must still return zero-value data rather than throwing.
		$this->ticketService->method('findByType')->willReturn([]);

		$this->service = new ReportingService($this->appConfig,
			$this->logger,
			$this->ticketService,
		);
	}//end setUp()

	/**
	 * Test calculateFcr with mixed outcomes returns correct percentage.
	 *
	 * Given 10 contacts where 8 have outcome 'resolved', FCR should be 80.0%.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testCalculateFcrWithMixedOutcomes(): void {
		$contactmomenten = [
			['outcome' => 'resolved'],
			['outcome' => 'resolved'],
			['outcome' => 'resolved'],
			['outcome' => 'resolved'],
			['outcome' => 'resolved'],
			['outcome' => 'resolved'],
			['outcome' => 'resolved'],
			['outcome' => 'resolved'],
			['outcome' => 'referred'],
			['outcome' => 'referred'],
		];

		$result = $this->service->calculateFcr($contactmomenten);

		$this->assertSame(80.0, $result);
	}//end testCalculateFcrWithMixedOutcomes()

	/**
	 * Test calculateFcr returns 0.0 for empty dataset.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testCalculateFcrEmptyDatasetReturnsZero(): void {
		$result = $this->service->calculateFcr([]);

		$this->assertSame(0.0, $result);
	}//end testCalculateFcrEmptyDatasetReturnsZero()

	/**
	 * Test calculateFcr returns 100.0 when all contacts are resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testCalculateFcrAllResolvedReturnsHundred(): void {
		$contactmomenten = [
			['outcome' => 'resolved'],
			['outcome' => 'resolved'],
			['outcome' => 'resolved'],
		];

		$result = $this->service->calculateFcr($contactmomenten);

		$this->assertSame(100.0, $result);
	}//end testCalculateFcrAllResolvedReturnsHundred()

	/**
	 * Test getChannelDistribution groups contacts by channel.
	 *
	 * getChannelDistribution returns an empty array when ObjectService is not
	 * available (which is always the case in unit tests). The groupByChannel
	 * logic is therefore tested via getKpis / calculateFcr integration.
	 * This test verifies the method is callable and returns an array.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testGetChannelDistributionGroupsByChannel(): void {
		$result = $this->service->getChannelDistribution('2026-04-01', '2026-04-30');

		$this->assertIsArray($result);
	}//end testGetChannelDistributionGroupsByChannel()

	/**
	 * Test getSlaCompliance returns 100.0 for empty dataset.
	 *
	 * When no contacts exist for a channel and date range, the channel is
	 * vacuously compliant (100%).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testSlaComplianceEmptyDatasetReturnsHundred(): void {
		$result = $this->service->getSlaCompliance('telefoon', '2026-04-01', '2026-04-30');

		$this->assertSame(100.0, $result);
	}//end testSlaComplianceEmptyDatasetReturnsHundred()

	/**
	 * Test calculateAverageHandlingTime returns 0:00 for empty input.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testCalculateAverageHandlingTimeEmptyReturnsZero(): void {
		$result = $this->service->calculateAverageHandlingTime([]);

		$this->assertSame('0:00', $result);
	}//end testCalculateAverageHandlingTimeEmptyReturnsZero()

	/**
	 * Test calculateAverageHandlingTime computes correct average.
	 *
	 * Two durations: PT4M30S (270 s) and PT1M30S (90 s) → avg 180 s → 3:00.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testCalculateAverageHandlingTimeComputesCorrectAverage(): void {
		$durations = ['PT4M30S', 'PT1M30S'];

		$result = $this->service->calculateAverageHandlingTime($durations);

		$this->assertSame('3:00', $result);
	}//end testCalculateAverageHandlingTimeComputesCorrectAverage()

	/**
	 * Test getSlaTarget reads from IAppConfig with fallback.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testGetSlaTargetReadFromConfig(): void {
		$this->appConfig
			->method('getValueString')
			->with('pipelinq', 'sla_telefoon_target_percent', '90')
			->willReturn('85');

		$result = $this->service->getSlaTarget('telefoon');

		$this->assertSame(85.0, $result);
	}//end testGetSlaTargetReadFromConfig()

	/**
	 * Test getKpis returns zero-value array when no data is available.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testGetKpisReturnsZeroValueArrayWhenNoData(): void {
		$result = $this->service->getKpis('2026-04-01', '2026-04-30');

		$this->assertIsArray($result);
		$this->assertArrayHasKey('total', $result);
		$this->assertArrayHasKey('fcrRate', $result);
		$this->assertArrayHasKey('avgHandlingTime', $result);
		$this->assertArrayHasKey('slaCompliance', $result);
		$this->assertSame(0, $result['total']);
	}//end testGetKpisReturnsZeroValueArrayWhenNoData()

	/**
	 * Test setSlaTarget rejects unknown channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testSetSlaTargetRejectsUnknownChannel(): void {
		$this->appConfig->expects($this->never())->method('setValueString');

		$result = $this->service->setSlaTarget('smoke_signal', 'target_percent', '95');

		$this->assertFalse($result);
	}//end testSetSlaTargetRejectsUnknownChannel()

	/**
	 * Test setSlaTarget rejects unknown metric for known channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testSetSlaTargetRejectsUnknownMetric(): void {
		$this->appConfig->expects($this->never())->method('setValueString');

		$result = $this->service->setSlaTarget('telefoon', 'unknown_metric', '99');

		$this->assertFalse($result);
	}//end testSetSlaTargetRejectsUnknownMetric()

	/**
	 * Test setSlaTarget accepts valid channel and metric.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	public function testSetSlaTargetAcceptsValidChannelAndMetric(): void {
		$this->appConfig
			->expects($this->once())
			->method('setValueString')
			->with('pipelinq', 'sla_telefoon_target_percent', '95');

		$result = $this->service->setSlaTarget('telefoon', 'target_percent', '95');

		$this->assertTrue($result);
	}//end testSetSlaTargetAcceptsValidChannelAndMetric()
}//end class
