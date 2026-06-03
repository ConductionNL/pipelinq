<?php

/**
 * Unit tests for AnalyticsService.
 *
 * Exercises the pure cross-module aggregation maths (conversion rate, request
 * resolution time, contact moment volume, satisfaction score, trend bucketing,
 * funnels) with plain arrays — no OpenRegister instance required. The thin
 * OR-fetch wrappers are covered at the integration level in CI.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AnalyticsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AnalyticsService.
 */
class AnalyticsServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var AnalyticsService
     */
    private AnalyticsService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $appConfig = $this->createMock(IAppConfig::class);
        $logger    = $this->createMock(LoggerInterface::class);

        $this->service = new AnalyticsService($container, $appConfig, $logger);
    }//end setUp()

    /**
     * Conversion rate is won/total inside the window, as a percentage.
     *
     * @return void
     */
    public function testLeadConversionRateComputesWonOverTotal(): void
    {
        $now   = time();
        $from  = ($now - 86400);
        $to    = ($now + 86400);
        $leads = [
            ['status' => 'won', 'expectedCloseDate' => date('c', $now)],
            ['status' => 'open', 'expectedCloseDate' => date('c', $now)],
            ['status' => 'lost', 'expectedCloseDate' => date('c', $now)],
            ['status' => 'won', 'expectedCloseDate' => date('c', $now)],
        ];

        $this->assertSame(50.0, $this->service->leadConversionRate($leads, $from, $to));
    }//end testLeadConversionRateComputesWonOverTotal()

    /**
     * Conversion rate is 0 (not a divide-by-zero) when no leads fall in window.
     *
     * @return void
     */
    public function testLeadConversionRateEmptyIsZero(): void
    {
        $this->assertSame(0.0, $this->service->leadConversionRate([], 0, 1));
    }//end testLeadConversionRateEmptyIsZero()

    /**
     * Resolution time is the mean elapsed hours between requestedAt/completedAt.
     *
     * @return void
     */
    public function testAvgRequestResolutionTimeMeanHours(): void
    {
        $now      = time();
        $requests = [
            [
                'requestedAt' => date('c', ($now - (2 * 3600))),
                'completedAt' => date('c', $now),
            ],
            [
                'requestedAt' => date('c', ($now - (4 * 3600))),
                'completedAt' => date('c', $now),
            ],
        ];

        $result = $this->service->avgRequestResolutionTime($requests, ($now - 86400), ($now + 86400));
        $this->assertSame(3.0, $result);
    }//end testAvgRequestResolutionTimeMeanHours()

    /**
     * Resolution time is null when there are no resolved requests in the window.
     *
     * @return void
     */
    public function testAvgRequestResolutionTimeNullWhenNoResolved(): void
    {
        $this->assertNull($this->service->avgRequestResolutionTime([], 0, 1));
        $this->assertNull(
            $this->service->avgRequestResolutionTime(
                [['requestedAt' => date('c'), 'completedAt' => null]],
                0,
                (time() + 86400)
            )
        );
    }//end testAvgRequestResolutionTimeNullWhenNoResolved()

    /**
     * Contact moment volume counts only items inside the window.
     *
     * @return void
     */
    public function testContactMomentVolumeCountsInWindow(): void
    {
        $now     = time();
        $moments = [
            ['date' => date('c', $now)],
            ['date' => date('c', ($now - (10 * 86400)))],
            ['date' => date('c', $now)],
        ];

        $this->assertSame(2, $this->service->contactMomentVolume($moments, ($now - 86400), ($now + 86400)));
    }//end testContactMomentVolumeCountsInWindow()

    /**
     * Satisfaction score averages valid 1-5 scores, ignoring out-of-range.
     *
     * @return void
     */
    public function testCustomerSatisfactionScoreAveragesValid(): void
    {
        $now       = time();
        $responses = [
            ['score' => 5, 'submittedAt' => date('c', $now)],
            ['score' => 3, 'submittedAt' => date('c', $now)],
            ['score' => 9, 'submittedAt' => date('c', $now)],
            ['score' => 'abc', 'submittedAt' => date('c', $now)],
        ];

        $this->assertSame(4.0, $this->service->customerSatisfactionScore($responses, ($now - 86400), ($now + 86400)));
    }//end testCustomerSatisfactionScoreAveragesValid()

    /**
     * Satisfaction score is null (renders "N/A") when there are no responses.
     *
     * @return void
     */
    public function testCustomerSatisfactionScoreNullWhenNone(): void
    {
        $this->assertNull($this->service->customerSatisfactionScore([], 0, 1));
    }//end testCustomerSatisfactionScoreNullWhenNone()

    /**
     * getTrends rejects an unsupported metric with InvalidArgumentException.
     *
     * @return void
     */
    public function testGetTrendsUnsupportedMetricThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getTrends('totally-unknown', 'month');
    }//end testGetTrendsUnsupportedMetricThrows()

    /**
     * Requests-by-category buckets per category and excludes empties.
     *
     * @return void
     */
    public function testRequestsByCategorySeriesBuckets(): void
    {
        $now      = time();
        $requests = [
            ['category' => 'wmo', 'requestedAt' => date('c', $now)],
            ['category' => 'wmo', 'requestedAt' => date('c', $now)],
            ['category' => 'belasting', 'requestedAt' => date('c', $now)],
            ['category' => 'oud', 'requestedAt' => date('c', ($now - (40 * 86400)))],
        ];

        $series = $this->service->requestsByCategorySeries($requests, ($now - (31 * 86400)), ($now + 86400));

        $byCategory = [];
        foreach ($series as $point) {
            $byCategory[$point['date']] = $point['value'];
        }

        $this->assertSame(2.0, $byCategory['wmo']);
        $this->assertSame(1.0, $byCategory['belasting']);
        $this->assertArrayNotHasKey('oud', $byCategory);
    }//end testRequestsByCategorySeriesBuckets()

    /**
     * Lead trend series buckets by day and returns sorted points.
     *
     * @return void
     */
    public function testLeadTrendSeriesBucketsByDay(): void
    {
        $now   = time();
        $leads = [
            ['expectedCloseDate' => date('c', $now)],
            ['expectedCloseDate' => date('c', $now)],
        ];

        $series = $this->service->leadTrendSeries($leads, 'month', ($now - 86400), ($now + 86400));
        $this->assertCount(1, $series);
        $this->assertSame(2.0, $series[0]['value']);
    }//end testLeadTrendSeriesBucketsByDay()

    /**
     * Lead funnel buckets leads by status.
     *
     * @return void
     */
    public function testBuildLeadFunnelBucketsByStatus(): void
    {
        $funnel = $this->service->buildLeadFunnel([
            ['status' => 'won'],
            ['status' => 'open'],
            ['status' => 'lost'],
            ['status' => 'open'],
        ]);

        $this->assertSame(4, $funnel['total']);
        $this->assertSame(2, $funnel['open']);
        $this->assertSame(1, $funnel['won']);
        $this->assertSame(1, $funnel['lost']);
    }//end testBuildLeadFunnelBucketsByStatus()

    /**
     * Request funnel buckets requests into in-progress and resolved.
     *
     * @return void
     */
    public function testBuildRequestFunnelBucketsByStatus(): void
    {
        $funnel = $this->service->buildRequestFunnel([
            ['status' => 'new'],
            ['status' => 'in_progress'],
            ['status' => 'completed'],
            ['status' => 'converted'],
        ]);

        $this->assertSame(4, $funnel['total']);
        $this->assertSame(1, $funnel['inProgress']);
        $this->assertSame(2, $funnel['resolved']);
    }//end testBuildRequestFunnelBucketsByStatus()

    /**
     * computeKpis aggregates all four KPIs into one payload.
     *
     * @return void
     */
    public function testComputeKpisAggregatesAll(): void
    {
        $now  = time();
        $from = ($now - 86400);
        $to   = ($now + 86400);

        $kpis = $this->service->computeKpis(
            [['status' => 'won', 'expectedCloseDate' => date('c', $now)]],
            [['requestedAt' => date('c', ($now - 3600)), 'completedAt' => date('c', $now)]],
            [['date' => date('c', $now)]],
            [['score' => 4, 'submittedAt' => date('c', $now)]],
            $from,
            $to
        );

        $this->assertSame(100.0, $kpis['leadConversionRate']);
        $this->assertSame(1.0, $kpis['avgRequestResolutionTime']);
        $this->assertSame(1, $kpis['contactMomentVolume']);
        $this->assertSame(4.0, $kpis['customerSatisfactionScore']);
    }//end testComputeKpisAggregatesAll()
}//end class
