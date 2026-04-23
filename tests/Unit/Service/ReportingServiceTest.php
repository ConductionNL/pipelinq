<?php

// SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Unit tests for ReportingService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ReportingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportingService KPI calculations.
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-13
 */
class ReportingServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ReportingService
     */
    private ReportingService $service;

    /**
     * Mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Mock logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Mock container.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);

        $this->service = new ReportingService(
            $this->appConfig,
            $this->logger,
            $this->container,
        );
    }//end setUp()

    /**
     * Test calculateFcr with sample data.
     *
     * @return void
     */
    public function testCalculateFcrWithData(): void
    {
        $fcr = $this->service->calculateFcr(totalContacts: 150, resolvedContacts: 112);

        $this->assertEqualsWithDelta(74.7, $fcr, 0.1);
    }//end testCalculateFcrWithData()

    /**
     * Test calculateFcr with no contacts.
     *
     * @return void
     */
    public function testCalculateFcrWithNoContacts(): void
    {
        $fcr = $this->service->calculateFcr(totalContacts: 0, resolvedContacts: 0);

        $this->assertSame(0.0, $fcr);
    }//end testCalculateFcrWithNoContacts()

    /**
     * Test calculateSlaCompliance green status.
     *
     * @return void
     */
    public function testCalculateSlaCompliance(): void
    {
        $result = $this->service->calculateSlaCompliance(
            channel: 'telefoon',
            totalContacts: 100,
            withinSla: 92,
        );

        $this->assertSame(92.0, $result['compliance']);
        $this->assertSame('green', $result['status']);
    }//end testCalculateSlaCompliance()

    /**
     * Test calculateSlaCompliance orange status (within 5% of target).
     *
     * @return void
     */
    public function testCalculateSlaComplianceOrange(): void
    {
        $result = $this->service->calculateSlaCompliance(
            channel: 'telefoon',
            totalContacts: 100,
            withinSla: 87,
        );

        $this->assertSame(87.0, $result['compliance']);
        $this->assertSame('orange', $result['status']);
    }//end testCalculateSlaComplianceOrange()

    /**
     * Test calculateSlaCompliance red status (>5% below target).
     *
     * @return void
     */
    public function testCalculateSlaComplianceRed(): void
    {
        $result = $this->service->calculateSlaCompliance(
            channel: 'telefoon',
            totalContacts: 100,
            withinSla: 84,
        );

        $this->assertSame(84.0, $result['compliance']);
        $this->assertSame('red', $result['status']);
    }//end testCalculateSlaComplianceRed()

    /**
     * Test getSlaTarget returns default for telefoon.
     *
     * @return void
     */
    public function testGetSlaTargetDefault(): void
    {
        $this->appConfig->method('getValueString')->willReturn('90');

        $target = $this->service->getSlaTarget('telefoon');

        $this->assertSame(90.0, $target);
    }//end testGetSlaTargetDefault()

    /**
     * Test generateCsv produces proper format.
     *
     * @return void
     */
    public function testGenerateCsvFormat(): void
    {
        $headers = ['Date', 'Channel', 'Duration'];
        $rows = [
            ['2026-04-18', 'telefoon', '5:30'],
            ['2026-04-18', 'email', '15:00'],
        ];

        $csv = $this->service->generateCsv(headers: $headers, rows: $rows);

        // Should contain BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        // Should contain headers (fields are quoted per escapeCSVField)
        $this->assertStringContainsString('"Date";"Channel";"Duration"', $csv);

        // Should contain rows with semicolon separators
        $this->assertStringContainsString('"2026-04-18";"telefoon";"5:30"', $csv);
        $this->assertStringContainsString('"2026-04-18";"email";"15:00"', $csv);
    }//end testGenerateCsvFormat()

    /**
     * Test calculateAverageHandlingTime.
     *
     * @return void
     */
    public function testCalculateAverageHandlingTime(): void
    {
        $durations = ['PT5M30S', 'PT4M30S'];

        $avg = $this->service->calculateAverageHandlingTime(durations: $durations);

        $this->assertSame('5:00', $avg);
    }//end testCalculateAverageHandlingTime()

    /**
     * Test calculateAverageHandlingTime with empty array.
     *
     * @return void
     */
    public function testCalculateAverageHandlingTimeEmpty(): void
    {
        $durations = [];

        $avg = $this->service->calculateAverageHandlingTime(durations: $durations);

        $this->assertSame('0:00', $avg);
    }//end testCalculateAverageHandlingTimeEmpty()

    /**
     * Test getAllSlaTargets returns all channels.
     *
     * @return void
     */
    public function testGetAllSlaTargets(): void
    {
        $this->appConfig->method('getValueString')->willReturnMap([
            ['pipelinq', 'sla_telefoon_target_percent', '90', '90'],
            ['pipelinq', 'sla_telefoon_wait_seconds', '30', '30'],
            ['pipelinq', 'sla_telefoon_handle_minutes', '5', '5'],
            ['pipelinq', 'sla_email_response_hours', '8', '8'],
            ['pipelinq', 'sla_email_target_percent', '90', '90'],
            ['pipelinq', 'sla_email_resolution_hours', '24', '24'],
            ['pipelinq', 'sla_balie_wait_minutes', '5', '5'],
            ['pipelinq', 'sla_balie_target_percent', '90', '90'],
            ['pipelinq', 'sla_balie_handle_minutes', '10', '10'],
            ['pipelinq', 'sla_chat_response_seconds', '30', '30'],
            ['pipelinq', 'sla_chat_target_percent', '90', '90'],
            ['pipelinq', 'sla_chat_handle_minutes', '10', '10'],
        ]);

        $targets = $this->service->getAllSlaTargets();

        $this->assertArrayHasKey('telefoon', $targets);
        $this->assertArrayHasKey('email', $targets);
        $this->assertArrayHasKey('balie', $targets);
        $this->assertArrayHasKey('chat', $targets);
    }//end testGetAllSlaTargets()

    /**
     * Test setSlaTarget updates configuration.
     *
     * @return void
     */
    public function testSetSlaTarget(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('pipelinq', 'sla_telefoon_target_percent', '95');

        $this->service->setSlaTarget(
            channel: 'telefoon',
            metric: 'target_percent',
            value: '95',
        );
    }//end testSetSlaTarget()

    /**
     * Test getQueueStatistics returns expected structure.
     *
     * @return void
     */
    public function testGetQueueStatistics(): void
    {
        $stats = $this->service->getQueueStatistics();

        $this->assertArrayHasKey('waiting', $stats);
        $this->assertArrayHasKey('longestWait', $stats);
        $this->assertArrayHasKey('avgWait', $stats);
        $this->assertArrayHasKey('estimatedWait', $stats);
    }//end testGetQueueStatistics()
}//end class
