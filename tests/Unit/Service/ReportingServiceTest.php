<?php

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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportingService.
 *
 * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.1
 */
class ReportingServiceTest extends TestCase
{
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
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the service under test.
     *
     * @return ReportingService
     */
    private function buildService(): ReportingService
    {
        return new ReportingService(
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }//end buildService()

    /**
     * Test calculating FCR with valid data.
     *
     * @return void
     */
    public function testCalculateFcrWithValidData(): void
    {
        $service = $this->buildService();

        // Test: 50 total contacts, 45 resolved = 90% FCR
        $result = $service->calculateFcr(totalContacts: 50, resolvedContacts: 45);

        $this->assertEqualsWithDelta(90.0, $result, 0.1);
    }//end testCalculateFcrWithValidData()

    /**
     * Test calculating FCR with zero contacts.
     *
     * @return void
     */
    public function testCalculateFcrWithZeroContacts(): void
    {
        $service = $this->buildService();

        $result = $service->calculateFcr(totalContacts: 0, resolvedContacts: 0);

        $this->assertEquals(0.0, $result);
    }//end testCalculateFcrWithZeroContacts()

    /**
     * Test SLA compliance calculation.
     *
     * @return void
     */
    public function testCalculateSlaComplianceGreen(): void
    {
        $service = $this->buildService();

        // Mock the SLA target to be 90%
        $this->appConfig
            ->method('getValueString')
            ->with('pipelinq', 'sla_telefoon_target_percent', '90')
            ->willReturn('90');

        // Test: 100 contacts, 92 within SLA = 92% compliance (green)
        $result = $service->calculateSlaCompliance(
            channel: 'telefoon',
            totalContacts: 100,
            withinSla: 92,
        );

        $this->assertEquals('green', $result['status']);
        $this->assertEqualsWithDelta(92.0, $result['compliance'], 0.1);
        $this->assertEquals(90.0, $result['target']);
    }//end testCalculateSlaComplianceGreen()

    /**
     * Test CSV generation.
     *
     * @return void
     */
    public function testGenerateCsv(): void
    {
        $service = $this->buildService();

        $headers = ['Date', 'Channel', 'Agent'];
        $rows    = [
            ['2024-01-01', 'telefoon', 'John'],
            ['2024-01-02', 'email', 'Jane'],
        ];

        $csv = $service->generateCsv(headers: $headers, rows: $rows);

        $this->assertStringContainsString('Date;Channel;Agent', $csv);
        $this->assertStringContainsString('2024-01-01', $csv);
        $this->assertStringContainsString('telefoon', $csv);
        // CSV should contain UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }//end testGenerateCsv()

    /**
     * Test average handling time calculation.
     *
     * @return void
     */
    public function testCalculateAverageHandlingTime(): void
    {
        $service = $this->buildService();

        // PT5M = 5 minutes, PT10M = 10 minutes, avg = 7.5 minutes = 7:30
        $durations = ['PT5M', 'PT10M'];
        $result    = $service->calculateAverageHandlingTime(durations: $durations);

        // Average of 5 and 10 minutes = 7.5 minutes = 7 minutes 30 seconds
        $this->assertEquals('7:30', $result);
    }//end testCalculateAverageHandlingTime()
}//end class
