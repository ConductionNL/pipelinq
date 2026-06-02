<?php

/**
 * Unit tests for RapportageService.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\RapportageService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RapportageService.
 */
class RapportageServiceTest extends TestCase
{

    /**
     * Mock container.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

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
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->appConfig->method('getValueString')->willReturnMap(
            [
                [Application::APP_ID, 'register', '', 'reg-1'],
                [Application::APP_ID, 'lead_schema', '', 'lead-1'],
            ]
        );
    }//end setUp()

    /**
     * Build a service whose ObjectService returns the given leads.
     *
     * @param array<int, array<string, mixed>> $leads The lead fixtures.
     *
     * @return RapportageService The service under test.
     */
    private function serviceWithLeads(array $leads): RapportageService
    {
        $objectService = new class($leads) {

            /**
             * @param array<int, array<string, mixed>> $leads The fixtures.
             */
            public function __construct(private array $leads)
            {
            }//end __construct()


            /**
             * Mimic the OpenRegister ObjectService findAll signature.
             *
             * @param array<string, mixed> $config The query config.
             *
             * @return array<int, array<string, mixed>> The leads.
             */
            public function findAll(array $config=[]): array
            {
                unset($config);
                return $this->leads;
            }//end findAll()
        };

        $this->container->method('get')->willReturn($objectService);

        return new RapportageService(
            $this->container,
            $this->appConfig,
            $this->logger,
        );
    }//end serviceWithLeads()

    /**
     * A lead modified $daysAgo days ago.
     *
     * @param array<string, mixed> $fields  The lead body fields.
     * @param int                  $daysAgo Days since last modification.
     *
     * @return array<string, mixed> The lead with a @self envelope.
     */
    private function lead(array $fields, int $daysAgo=0): array
    {
        $updated = date('c', (time() - ($daysAgo * 86400)));
        $created = date('c', (time() - (($daysAgo + 10) * 86400)));

        return array_merge(
            $fields,
            ['@self' => ['updated' => $updated, 'created' => $created]]
        );
    }//end lead()

    /**
     * getStageValues groups open leads and sums total + weighted value.
     *
     * @return void
     */
    public function testGetStageValuesAggregatesByStage(): void
    {
        $service = $this->serviceWithLeads(
            [
                $this->lead(['stage' => 'Nieuw', 'value' => 10000, 'probability' => 20, 'status' => 'open']),
                $this->lead(['stage' => 'Nieuw', 'value' => 30000, 'probability' => 50, 'status' => 'open']),
                $this->lead(['stage' => 'Voorstel', 'value' => 50000, 'probability' => 80, 'status' => 'open']),
                $this->lead(['stage' => 'Nieuw', 'value' => 99000, 'probability' => 100, 'status' => 'won']),
            ]
        );

        $result = $service->getStageValues();

        $byStage = [];
        foreach ($result as $row) {
            $byStage[$row['stage']] = $row;
        }

        // Won lead is excluded (closed); Nieuw has 2 open leads.
        $this->assertSame(2, $byStage['Nieuw']['count']);
        $this->assertSame(40000.0, $byStage['Nieuw']['totalValue']);
        // Weighted = 10000*0.2 + 30000*0.5 = 2000 + 15000 = 17000.
        $this->assertSame(17000.0, $byStage['Nieuw']['weightedValue']);
        $this->assertSame(1, $byStage['Voorstel']['count']);
        $this->assertSame(40000.0, $byStage['Voorstel']['weightedValue']);
    }//end testGetStageValuesAggregatesByStage()

    /**
     * getSourcePerformance computes per-source conversion and avg won value.
     *
     * @return void
     */
    public function testGetSourcePerformanceComputesConversion(): void
    {
        $service = $this->serviceWithLeads(
            [
                $this->lead(['source' => 'referral', 'value' => 20000, 'status' => 'won']),
                $this->lead(['source' => 'referral', 'value' => 40000, 'status' => 'won']),
                $this->lead(['source' => 'referral', 'value' => 5000, 'status' => 'open']),
                $this->lead(['source' => 'cold-call', 'value' => 1000, 'status' => 'open']),
            ]
        );

        $result = $service->getSourcePerformance();

        $bySource = [];
        foreach ($result as $row) {
            $bySource[$row['source']] = $row;
        }

        $this->assertSame(3, $bySource['referral']['total']);
        $this->assertSame(2, $bySource['referral']['won']);
        // 2 / 3 = 66.7%.
        $this->assertSame(66.7, $bySource['referral']['conversionRate']);
        // avg won value = (20000 + 40000) / 2 = 30000.
        $this->assertSame(30000.0, $bySource['referral']['avgWonValue']);

        // Source with no wins => 0% and null avg value.
        $this->assertSame(0.0, $bySource['cold-call']['conversionRate']);
        $this->assertNull($bySource['cold-call']['avgWonValue']);
    }//end testGetSourcePerformanceComputesConversion()

    /**
     * getAgingBuckets distributes open leads into the four age buckets.
     *
     * @return void
     */
    public function testGetAgingBucketsDistributesOpenLeads(): void
    {
        $service = $this->serviceWithLeads(
            [
                $this->lead(['status' => 'open', 'value' => 1000], 3),
                $this->lead(['status' => 'open', 'value' => 2000], 10),
                $this->lead(['status' => 'open', 'value' => 3000], 20),
                $this->lead(['status' => 'open', 'value' => 4000], 45),
                $this->lead(['status' => 'won', 'value' => 9999], 60),
            ]
        );

        $buckets = [];
        foreach ($service->getAgingBuckets() as $row) {
            $buckets[$row['bucket']] = $row;
        }

        $this->assertSame(1, $buckets['0-7d']['count']);
        $this->assertSame(1, $buckets['8-14d']['count']);
        $this->assertSame(1, $buckets['15-30d']['count']);
        // 45-day open lead in 30d+; the won lead is excluded.
        $this->assertSame(1, $buckets['30d+']['count']);
        $this->assertSame(4000.0, $buckets['30d+']['totalValue']);
    }//end testGetAgingBucketsDistributesOpenLeads()

    /**
     * getWinLossAnalysis computes win rate and averages.
     *
     * @return void
     */
    public function testGetWinLossAnalysisComputesRates(): void
    {
        $service = $this->serviceWithLeads(
            [
                $this->lead(['status' => 'won', 'value' => 20000], 5),
                $this->lead(['status' => 'won', 'value' => 40000], 5),
                $this->lead(['status' => 'won', 'value' => 30000], 5),
                $this->lead(['status' => 'lost', 'value' => 10000], 5),
                $this->lead(['status' => 'lost', 'value' => 14000], 5),
                $this->lead(['status' => 'open', 'value' => 99000], 5),
            ]
        );

        $result = $service->getWinLossAnalysis();

        $this->assertSame(3, $result['wonCount']);
        $this->assertSame(2, $result['lostCount']);
        // 3 / 5 = 60%.
        $this->assertSame(60.0, $result['winRate']);
        $this->assertSame(30000.0, $result['avgWonValue']);
        $this->assertSame(12000.0, $result['avgLostValue']);
        // created is 10 days before updated => 10 days to close.
        $this->assertSame(10, $result['avgDaysToClose']);
    }//end testGetWinLossAnalysisComputesRates()

    /**
     * getPipelineStats returns all four sections keyed correctly.
     *
     * @return void
     */
    public function testGetPipelineStatsReturnsAllSections(): void
    {
        $service = $this->serviceWithLeads(
            [$this->lead(['stage' => 'Nieuw', 'source' => 'web', 'value' => 1000, 'status' => 'open'])]
        );

        $stats = $service->getPipelineStats();

        $this->assertArrayHasKey('stageValues', $stats);
        $this->assertArrayHasKey('sourcePerformance', $stats);
        $this->assertArrayHasKey('agingBuckets', $stats);
        $this->assertArrayHasKey('winLoss', $stats);
        $this->assertCount(4, $stats['agingBuckets']);
    }//end testGetPipelineStatsReturnsAllSections()

    /**
     * An empty dataset yields safe zeroed aggregates (no errors).
     *
     * @return void
     */
    public function testEmptyDatasetYieldsZeroedAggregates(): void
    {
        $service = $this->serviceWithLeads([]);

        $this->assertSame([], $service->getStageValues());
        $this->assertSame([], $service->getSourcePerformance());

        $winLoss = $service->getWinLossAnalysis();
        $this->assertSame(0, $winLoss['wonCount']);
        $this->assertSame(0.0, $winLoss['winRate']);
        $this->assertSame(0, $winLoss['avgDaysToClose']);
    }//end testEmptyDatasetYieldsZeroedAggregates()
}//end class
