<?php

/**
 * Unit tests for RapportageService.
 *
 * Exercises the pure aggregation logic (stage values, source performance,
 * aging buckets, win/loss) with known lead arrays — no running OpenRegister
 * is required because the calculators operate on plain arrays.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/lead-management/tasks.md#9.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\RapportageService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RapportageService aggregation logic.
 */
class RapportageServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var RapportageService
     */
    private RapportageService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $appConfig  = $this->createMock(IAppConfig::class);
        $appManager = $this->createMock(IAppManager::class);
        $container  = $this->createMock(ContainerInterface::class);
        $logger     = $this->createMock(LoggerInterface::class);

        $this->service = new RapportageService($appConfig, $appManager, $container, $logger);
    }//end setUp()

    /**
     * Build a representative set of leads for the aggregation tests.
     *
     * @return array<int, array<string, mixed>> The test leads.
     */
    private function sampleLeads(): array
    {
        $recent = date('c', (time() - (3 * 86400)));
        $old    = date('c', (time() - (40 * 86400)));

        return [
            // Open leads in various stages.
            ['stage' => 'Nieuw', 'status' => 'open', 'source' => 'website', 'value' => 10000, 'probability' => 20, 'pipeline' => 'p1', '_dateModified' => $recent],
            ['stage' => 'Nieuw', 'status' => 'open', 'source' => 'referral', 'value' => 20000, 'probability' => 50, 'pipeline' => 'p1', '_dateModified' => $old],
            ['stage' => 'Voorstel', 'status' => 'open', 'source' => 'website', 'value' => 30000, 'probability' => 60, 'pipeline' => 'p2', '_dateModified' => $recent],
            // Closed leads.
            ['stage' => 'Gewonnen', 'status' => 'won', 'source' => 'referral', 'value' => 40000, 'probability' => 100, 'pipeline' => 'p1', '_dateCreated' => $old, '_dateModified' => $recent],
            ['stage' => 'Gewonnen', 'status' => 'won', 'source' => 'website', 'value' => 60000, 'probability' => 100, 'pipeline' => 'p1', '_dateCreated' => $old, '_dateModified' => $recent],
            ['stage' => 'Verloren', 'status' => 'lost', 'source' => 'website', 'value' => 5000, 'probability' => 0, 'pipeline' => 'p2', '_dateCreated' => $old, '_dateModified' => $recent],
        ];
    }//end sampleLeads()

    /**
     * computeStageValues groups open leads by stage with correct totals and weighting.
     *
     * @return void
     */
    public function testComputeStageValuesAggregatesOpenLeads(): void
    {
        $rows = $this->service->computeStageValues($this->sampleLeads());

        $byStage = [];
        foreach ($rows as $row) {
            $byStage[$row['stage']] = $row;
        }

        // Only open leads counted (won/lost excluded).
        $this->assertArrayHasKey('Nieuw', $byStage);
        $this->assertArrayHasKey('Voorstel', $byStage);
        $this->assertArrayNotHasKey('Gewonnen', $byStage);

        $this->assertSame(2, $byStage['Nieuw']['count']);
        $this->assertSame(30000.0, $byStage['Nieuw']['totalValue']);
        // Weighted = 10000*0.2 + 20000*0.5 = 2000 + 10000 = 12000.
        $this->assertSame(12000.0, $byStage['Nieuw']['weightedValue']);

        $this->assertSame(1, $byStage['Voorstel']['count']);
        $this->assertSame(18000.0, $byStage['Voorstel']['weightedValue']);
    }//end testComputeStageValuesAggregatesOpenLeads()

    /**
     * computeStageValues honours the pipeline filter.
     *
     * @return void
     */
    public function testComputeStageValuesFiltersByPipeline(): void
    {
        $rows   = $this->service->computeStageValues($this->sampleLeads(), 'p2');
        $stages = array_column($rows, 'stage');

        $this->assertContains('Voorstel', $stages);
        $this->assertNotContains('Nieuw', $stages);
    }//end testComputeStageValuesFiltersByPipeline()

    /**
     * computeSourcePerformance computes conversion rate and average won value.
     *
     * @return void
     */
    public function testComputeSourcePerformanceConversionAndAverages(): void
    {
        $rows = $this->service->computeSourcePerformance($this->sampleLeads());

        $bySource = [];
        foreach ($rows as $row) {
            $bySource[$row['source']] = $row;
        }

        // website: 4 leads, 1 won (60000). conversion 25%, avg won 60000.
        $this->assertSame(4, $bySource['website']['total']);
        $this->assertSame(1, $bySource['website']['won']);
        $this->assertSame(25.0, $bySource['website']['conversionRate']);
        $this->assertSame(60000.0, $bySource['website']['avgWonValue']);

        // referral: 2 leads, 1 won (40000). conversion 50%, avg won 40000.
        $this->assertSame(2, $bySource['referral']['total']);
        $this->assertSame(50.0, $bySource['referral']['conversionRate']);
        $this->assertSame(40000.0, $bySource['referral']['avgWonValue']);
    }//end testComputeSourcePerformanceConversionAndAverages()

    /**
     * A source with no won leads exposes a null average (renders as "—") not an error.
     *
     * @return void
     */
    public function testComputeSourcePerformanceZeroConversionHasNullAverage(): void
    {
        $leads = [
            ['status' => 'open', 'source' => 'cold-call', 'value' => 1000],
            ['status' => 'lost', 'source' => 'cold-call', 'value' => 2000],
        ];

        $rows = $this->service->computeSourcePerformance($leads);

        $this->assertCount(1, $rows);
        $this->assertSame('cold-call', $rows[0]['source']);
        $this->assertSame(0.0, $rows[0]['conversionRate']);
        $this->assertNull($rows[0]['avgWonValue']);
    }//end testComputeSourcePerformanceZeroConversionHasNullAverage()

    /**
     * computeAgingBuckets distributes open leads into the four buckets.
     *
     * @return void
     */
    public function testComputeAgingBucketsDistributesOpenLeads(): void
    {
        $buckets = $this->service->computeAgingBuckets($this->sampleLeads());

        $byBucket = [];
        foreach ($buckets as $bucket) {
            $byBucket[$bucket['bucket']] = $bucket;
        }

        // Always returns the four canonical buckets.
        $this->assertArrayHasKey('0-7d', $byBucket);
        $this->assertArrayHasKey('30d+', $byBucket);

        // One open lead is 3 days old, one is 40 days old, one is 3 days old.
        $this->assertSame(2, $byBucket['0-7d']['count']);
        $this->assertSame(1, $byBucket['30d+']['count']);
        // Closed leads excluded → total open = 3.
        $total = array_sum(array_column($buckets, 'count'));
        $this->assertSame(3, $total);
    }//end testComputeAgingBucketsDistributesOpenLeads()

    /**
     * computeWinLoss returns correct win rate and averages over closed leads.
     *
     * @return void
     */
    public function testComputeWinLossRateAndAverages(): void
    {
        $result = $this->service->computeWinLoss($this->sampleLeads());

        $this->assertSame(2, $result['wonCount']);
        $this->assertSame(1, $result['lostCount']);
        // 2 won of 3 closed = 66.7%.
        $this->assertSame(66.7, $result['winRate']);
        // avg won = (40000 + 60000) / 2 = 50000.
        $this->assertSame(50000.0, $result['avgWonValue']);
        $this->assertSame(5000.0, $result['avgLostValue']);
        // All closed leads created 40d ago, modified 3d ago → ~37 days to close.
        $this->assertNotNull($result['avgDaysToClose']);
        $this->assertGreaterThan(30, $result['avgDaysToClose']);
    }//end testComputeWinLossRateAndAverages()

    /**
     * Empty input yields zeroed, well-formed aggregates (no division by zero).
     *
     * @return void
     */
    public function testEmptyLeadsYieldZeroedAggregates(): void
    {
        $this->assertSame([], $this->service->computeStageValues([]));
        $this->assertSame([], $this->service->computeSourcePerformance([]));

        $winLoss = $this->service->computeWinLoss([]);
        $this->assertSame(0, $winLoss['wonCount']);
        $this->assertSame(0.0, $winLoss['winRate']);
        $this->assertNull($winLoss['avgWonValue']);

        $buckets = $this->service->computeAgingBuckets([]);
        $this->assertSame(0, array_sum(array_column($buckets, 'count')));
    }//end testEmptyLeadsYieldZeroedAggregates()
}//end class
