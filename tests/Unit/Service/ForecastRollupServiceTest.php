<?php

/**
 * Unit tests for ForecastRollupService.
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

use OCA\Pipelinq\Service\ExchangeRateService;
use OCA\Pipelinq\Service\ForecastRollupService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure roll-up aggregation engine.
 */
class ForecastRollupServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ForecastRollupService
     */
    private ForecastRollupService $service;

    /**
     * Set up the fixtures with a single-currency (EUR) exchange service.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                if ($key === ExchangeRateService::REPORTING_CURRENCY_KEY) {
                    return 'EUR';
                }

                return $default;
            }
        );
        $exchange      = new ExchangeRateService(appConfig: $appConfig);
        $this->service = new ForecastRollupService(exchangeRate: $exchange);
    }//end setUp()

    /**
     * Deals bucket into the correct category; omitted and closed_lost excluded.
     *
     * @return void
     */
    public function testBucketDealsByCategory(): void
    {
        $deals  = [
            ['forecast_category' => 'commit', 'value' => 75000, 'uuid' => 'a'],
            ['forecast_category' => 'best_case', 'value' => 25000, 'uuid' => 'b'],
            ['forecast_category' => 'pipeline', 'value' => 12000, 'uuid' => 'c'],
            ['forecast_category' => 'closed_won', 'value' => 30000, 'uuid' => 'd'],
            ['forecast_category' => 'omitted', 'value' => 20000, 'uuid' => 'e'],
            ['forecast_category' => 'closed_lost', 'value' => 99000, 'uuid' => 'f'],
        ];
        $result = $this->service->bucketDeals($deals);

        $this->assertSame(75000.0, $result['totals']['commit_amount']);
        $this->assertSame(25000.0, $result['totals']['best_case_amount']);
        $this->assertSame(12000.0, $result['totals']['pipeline_amount']);
        $this->assertSame(30000.0, $result['totals']['closed_won_amount']);
        // Omitted + closed_lost do not contribute deal ids.
        $this->assertSame(['a', 'b', 'c', 'd'], $result['deal_ids']);
        $this->assertSame('EUR', $result['currency']);
    }//end testBucketDealsByCategory()

    /**
     * Team totals are the sum of rep totals across all four amounts.
     *
     * @return void
     */
    public function testSumChildTotals(): void
    {
        $repA = ['commit_amount' => 200000.0, 'best_case_amount' => 300000.0, 'pipeline_amount' => 400000.0, 'closed_won_amount' => 50000.0];
        $repB = ['commit_amount' => 150000.0, 'best_case_amount' => 250000.0, 'pipeline_amount' => 350000.0, 'closed_won_amount' => 100000.0];

        $team = $this->service->sumChildTotals([$repA, $repB]);

        $this->assertSame(350000.0, $team['commit_amount']);
        $this->assertSame(550000.0, $team['best_case_amount']);
        $this->assertSame(750000.0, $team['pipeline_amount']);
        $this->assertSame(150000.0, $team['closed_won_amount']);
    }//end testSumChildTotals()

    /**
     * Summing no children yields a zeroed total.
     *
     * @return void
     */
    public function testSumChildTotalsEmpty(): void
    {
        $team = $this->service->sumChildTotals([]);
        $this->assertSame(0.0, $team['commit_amount']);
        $this->assertSame(0.0, $team['closed_won_amount']);
    }//end testSumChildTotalsEmpty()
}//end class
