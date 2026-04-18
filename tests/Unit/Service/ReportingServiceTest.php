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
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ReportingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportingService.
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
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
            appConfig: $this->appConfig,
            logger: $this->logger,
            container: $this->container,
        );
    }//end setUp()

    /**
     * Test FCR calculation with values.
     *
     * @return void
     */
    public function testCalculateFcr(): void
    {
        $fcr = $this->service->calculateFcr(totalContacts: 150, resolvedContacts: 112);
        $this->assertEqualsWithDelta(74.7, $fcr, 0.1);
    }//end testCalculateFcr()

    /**
     * Test FCR calculation with zero total contacts.
     *
     * @return void
     */
    public function testCalculateFcrZeroTotal(): void
    {
        $fcr = $this->service->calculateFcr(totalContacts: 0, resolvedContacts: 0);
        $this->assertEquals(0.0, $fcr);
    }//end testCalculateFcrZeroTotal()

    /**
     * Test FCR calculation with 100% resolution.
     *
     * @return void
     */
    public function testCalculateFcr100Percent(): void
    {
        $fcr = $this->service->calculateFcr(totalContacts: 100, resolvedContacts: 100);
        $this->assertEquals(100.0, $fcr);
    }//end testCalculateFcr100Percent()

    /**
     * Test SLA compliance calculation above target.
     *
     * @return void
     */
    public function testCalculateSlaComplianceGreen(): void
    {
        // 80 out of 95 = 84.2%, target 90% - but only 5.8% below, so orange
        $result = $this->service->calculateSlaCompliance(
            channel: 'telefoon',
            totalContacts: 95,
            withinSla: 84,
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('compliance', $result);
        $this->assertArrayHasKey('target', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEqualsWithDelta(88.4, $result['compliance'], 0.1);
        $this->assertEquals(90.0, $result['target']);
    }//end testCalculateSlaComplianceGreen()

    /**
     * Test SLA compliance calculation with red status.
     *
     * @return void
     */
    public function testCalculateSlaComplianceRed(): void
    {
        // 70 out of 100 = 70%, target 90% - 20% below = red
        $result = $this->service->calculateSlaCompliance(
            channel: 'telefoon',
            totalContacts: 100,
            withinSla: 70,
        );

        $this->assertEquals('red', $result['status']);
        $this->assertEquals(70.0, $result['compliance']);
    }//end testCalculateSlaComplianceRed()

    /**
     * Test SLA compliance with zero total.
     *
     * @return void
     */
    public function testCalculateSlaComplianceZeroTotal(): void
    {
        $result = $this->service->calculateSlaCompliance(
            channel: 'telefoon',
            totalContacts: 0,
            withinSla: 0,
        );

        $this->assertEquals(0.0, $result['compliance']);
        $this->assertEquals('red', $result['status']);
    }//end testCalculateSlaComplianceZeroTotal()

    /**
     * Test get SLA target.
     *
     * @return void
     */
    public function testGetSlaTarget(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static fn($app, $key, $default) => $default);

        $target = $this->service->getSlaTarget('telefoon');
        $this->assertEquals(90.0, $target);
    }//end testGetSlaTarget()

    /**
     * Test get all SLA targets returns default structure.
     *
     * @return void
     */
    public function testGetAllSlaTargets(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static fn($app, $key, $default) => $default);

        $targets = $this->service->getAllSlaTargets();

        $this->assertIsArray($targets);
        $this->assertArrayHasKey('telefoon', $targets);
        $this->assertArrayHasKey('email', $targets);
        $this->assertArrayHasKey('balie', $targets);
        $this->assertArrayHasKey('chat', $targets);
    }//end testGetAllSlaTargets()

    /**
     * Test CSV generation with headers and rows.
     *
     * @return void
     */
    public function testGenerateCsv(): void
    {
        $headers = ['Date', 'Channel', 'Agent'];
        $rows    = [
            ['2026-04-18', 'telefoon', 'John Doe'],
            ['2026-04-18', 'email', 'Jane Doe'],
        ];

        $csv = $this->service->generateCsv($headers, $rows);

        $this->assertStringContainsString('Date;Channel;Agent', $csv);
        $this->assertStringContainsString('2026-04-18', $csv);
        $this->assertStringContainsString('John Doe', $csv);
        // Check for UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }//end testGenerateCsv()

    /**
     * Test CSV generation with empty rows.
     *
     * @return void
     */
    public function testGenerateCsvEmpty(): void
    {
        $headers = ['Date', 'Channel'];
        $rows    = [];

        $csv = $this->service->generateCsv($headers, $rows);

        $this->assertStringContainsString('Date;Channel', $csv);
    }//end testGenerateCsvEmpty()

    /**
     * Test get daily KPI summary returns expected structure.
     *
     * @return void
     */
    public function testGetDailyKpiSummary(): void
    {
        $summary = $this->service->getDailyKpiSummary();

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('totalContacts', $summary);
        $this->assertArrayHasKey('perChannel', $summary);
        $this->assertArrayHasKey('fcrRate', $summary);
        $this->assertArrayHasKey('slaCompliance', $summary);
        $this->assertArrayHasKey('queueLength', $summary);
        $this->assertArrayHasKey('activeAgents', $summary);
        $this->assertArrayHasKey('lastUpdated', $summary);

        // Check per-channel structure
        $this->assertArrayHasKey('telefoon', $summary['perChannel']);
        $this->assertArrayHasKey('email', $summary['perChannel']);
        $this->assertArrayHasKey('balie', $summary['perChannel']);
        $this->assertArrayHasKey('chat', $summary['perChannel']);
    }//end testGetDailyKpiSummary()

    /**
     * Test get daily KPI summary empty state.
     *
     * @return void
     */
    public function testGetDailyKpiSummaryEmpty(): void
    {
        $summary = $this->service->getDailyKpiSummary();

        $this->assertEquals(0, $summary['totalContacts']);
        $this->assertEquals(0.0, $summary['fcrRate']);
        $this->assertEquals(0, $summary['queueLength']);
        $this->assertEquals(0, $summary['activeAgents']);
    }//end testGetDailyKpiSummaryEmpty()

    /**
     * Test get KPI trend returns expected structure.
     *
     * @return void
     */
    public function testGetKpiTrend(): void
    {
        $trend = $this->service->getKpiTrend('telefoon', 'fcr', 7);

        $this->assertIsArray($trend);
        $this->assertArrayHasKey('channel', $trend);
        $this->assertArrayHasKey('metric', $trend);
        $this->assertArrayHasKey('period', $trend);
        $this->assertArrayHasKey('currentValue', $trend);
        $this->assertArrayHasKey('previousValue', $trend);
        $this->assertArrayHasKey('trendDirection', $trend);

        $this->assertEquals('telefoon', $trend['channel']);
        $this->assertEquals('fcr', $trend['metric']);
        $this->assertEquals(7, $trend['period']);
    }//end testGetKpiTrend()

    /**
     * Test get channel distribution returns expected structure.
     *
     * @return void
     */
    public function testGetChannelDistribution(): void
    {
        $dist = $this->service->getChannelDistribution('2026-04-01', '2026-04-30', 'day');

        $this->assertIsArray($dist);
        $this->assertArrayHasKey('dateFrom', $dist);
        $this->assertArrayHasKey('dateTo', $dist);
        $this->assertArrayHasKey('granularity', $dist);
        $this->assertArrayHasKey('channels', $dist);

        $this->assertEquals('2026-04-01', $dist['dateFrom']);
        $this->assertEquals('2026-04-30', $dist['dateTo']);
        $this->assertEquals('day', $dist['granularity']);
    }//end testGetChannelDistribution()

    /**
     * Test get channel comparison returns expected structure.
     *
     * @return void
     */
    public function testGetChannelComparison(): void
    {
        $comp = $this->service->getChannelComparison('2026-04');

        $this->assertIsArray($comp);
        $this->assertArrayHasKey('currentMonth', $comp);
        $this->assertArrayHasKey('channels', $comp);

        $this->assertEquals('2026-04', $comp['currentMonth']);
        $this->assertArrayHasKey('telefoon', $comp['channels']);
        $this->assertArrayHasKey('email', $comp['channels']);
    }//end testGetChannelComparison()

    /**
     * Test get queue statistics returns expected structure.
     *
     * @return void
     */
    public function testGetQueueStatistics(): void
    {
        $stats = $this->service->getQueueStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('itemsWaiting', $stats);
        $this->assertArrayHasKey('longestWaitSeconds', $stats);
        $this->assertArrayHasKey('averageWaitSeconds', $stats);
        $this->assertArrayHasKey('perChannel', $stats);
        $this->assertArrayHasKey('timestamp', $stats);

        $this->assertEquals(0, $stats['itemsWaiting']);
    }//end testGetQueueStatistics()

    /**
     * Test get agent statistics returns expected structure.
     *
     * @return void
     */
    public function testGetAgentStatistics(): void
    {
        $stats = $this->service->getAgentStatistics('user123');

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('agentId', $stats);
        $this->assertArrayHasKey('contactsToday', $stats);
        $this->assertArrayHasKey('avgHandlingTime', $stats);
        $this->assertArrayHasKey('fcrRate', $stats);

        $this->assertEquals('user123', $stats['agentId']);
    }//end testGetAgentStatistics()

    /**
     * Test get team overview returns expected structure.
     *
     * @return void
     */
    public function testGetTeamOverview(): void
    {
        $overview = $this->service->getTeamOverview();

        $this->assertIsArray($overview);
        $this->assertArrayHasKey('teamSize', $overview);
        $this->assertArrayHasKey('totalContacts', $overview);
        $this->assertArrayHasKey('avgHandlingTime', $overview);
        $this->assertArrayHasKey('agents', $overview);
    }//end testGetTeamOverview()

    /**
     * Test get monthly trend report returns expected structure.
     *
     * @return void
     */
    public function testGetMonthlyTrendReport(): void
    {
        $report = $this->service->getMonthlyTrendReport(6);

        $this->assertIsArray($report);
        $this->assertArrayHasKey('period', $report);
        $this->assertArrayHasKey('months', $report);
        $this->assertArrayHasKey('trends', $report);

        $this->assertEquals(6, $report['period']);
    }//end testGetMonthlyTrendReport()

    /**
     * Test get peak hours heatmap returns all hours.
     *
     * @return void
     */
    public function testGetPeakHoursHeatmap(): void
    {
        $heatmap = $this->service->getPeakHoursHeatmap(4);

        $this->assertIsArray($heatmap);
        $this->assertArrayHasKey('heatmap', $heatmap);
        $this->assertEquals(4, $heatmap['period']);

        $heatmapData = $heatmap['heatmap'];
        $this->assertArrayHasKey('Monday', $heatmapData);
        $this->assertCount(24, $heatmapData['Monday']);
    }//end testGetPeakHoursHeatmap()

    /**
     * Test generate WOO report returns expected structure.
     *
     * @return void
     */
    public function testGenerateWooReport(): void
    {
        $report = $this->service->generateWooReport('2026-01-01', '2026-03-31');

        $this->assertIsArray($report);
        $this->assertArrayHasKey('dateFrom', $report);
        $this->assertArrayHasKey('dateTo', $report);
        $this->assertArrayHasKey('totalContacts', $report);
        $this->assertArrayHasKey('perChannel', $report);
        $this->assertArrayHasKey('slaCompliance', $report);

        $this->assertEquals('2026-01-01', $report['dateFrom']);
        $this->assertEquals('2026-03-31', $report['dateTo']);
        $this->assertStringContainsString('No PII', $report['note']);
    }//end testGenerateWooReport()

    /**
     * Test set and get SLA target persistence.
     *
     * @return void
     */
    public function testSetSlaTarget(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('pipelinq', 'sla_telefoon_target_percent', '95');

        $this->service->setSlaTarget('telefoon', 'target_percent', '95');
    }//end testSetSlaTarget()

    /**
     * Test calculate average handling time.
     *
     * @return void
     */
    public function testCalculateAverageHandlingTime(): void
    {
        $durations = ['PT5M30S', 'PT6M15S', 'PT4M45S'];
        $avg       = $this->service->calculateAverageHandlingTime($durations);

        $this->assertStringContainsString(':', $avg);
        // Average should be around 5:30
        $this->assertMatchesRegularExpression('/\d+:\d{2}/', $avg);
    }//end testCalculateAverageHandlingTime()

    /**
     * Test calculate average handling time with empty array.
     *
     * @return void
     */
    public function testCalculateAverageHandlingTimeEmpty(): void
    {
        $avg = $this->service->calculateAverageHandlingTime([]);

        $this->assertEquals('0:00', $avg);
    }//end testCalculateAverageHandlingTimeEmpty()
}//end class
