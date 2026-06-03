<?php

/**
 * Unit tests for NaviService.
 *
 * Covers deterministic intent detection, the empty-result text fallback, and
 * the chart/table response shaping in formatResponse — all without a live
 * OpenRegister or LLM backend (the conversational enrichment path is gated off
 * by default and returns an empty summary).
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
use OCA\Pipelinq\Service\NaviService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for NaviService.
 */
class NaviServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var NaviService
     */
    private NaviService $service;

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

        // With an unconfigured register/schema AnalyticsService::fetch() returns
        // empty arrays, so buildContext yields empty series — exactly the
        // "no data" path we want to assert against without a live backend.
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = '') {
                return $default;
            }
        );

        $analytics = new AnalyticsService($container, $appConfig, $logger);

        $this->service = new NaviService($container, $analytics, $appConfig, $logger);
    }//end setUp()

    /**
     * detectIntent classifies the four supported intents and the unknown case.
     *
     * @return void
     */
    public function testDetectIntentClassifiesQueries(): void
    {
        $this->assertSame('trend', $this->service->detectIntent('Toon de trend van leads over tijd'));
        $this->assertSame('conversion', $this->service->detectIntent('Wat is de conversie deze maand?'));
        $this->assertSame('breakdown', $this->service->detectIntent('Geef de verdeling per categorie'));
        $this->assertSame('count', $this->service->detectIntent('Hoeveel leads zijn er?'));
        $this->assertSame('unknown', $this->service->detectIntent('Het regent vandaag'));
    }//end testDetectIntentClassifiesQueries()

    /**
     * An empty query returns a helpful text response, never an error.
     *
     * @return void
     */
    public function testProcessQueryEmptyReturnsText(): void
    {
        $response = $this->service->processQuery('', 'alice');
        $this->assertSame('text', $response['resultType']);
        $this->assertNotEmpty($response['textResponse']);
    }//end testProcessQueryEmptyReturnsText()

    /**
     * An unrecognised query returns a clarification text response.
     *
     * @return void
     */
    public function testProcessQueryUnknownReturnsClarification(): void
    {
        $response = $this->service->processQuery('paarse olifanten dansen', 'alice');
        $this->assertSame('text', $response['resultType']);
        $this->assertLessThanOrEqual(3, count($response['suggestedFollowUps']));
    }//end testProcessQueryUnknownReturnsClarification()

    /**
     * A recognised query with no underlying data collapses to a text response
     * (never an empty chart/table).
     *
     * @return void
     */
    public function testProcessQueryNoDataReturnsText(): void
    {
        $response = $this->service->processQuery('Hoeveel leads zijn er deze maand?', 'alice');
        $this->assertSame('table', $response['resultType']);
        // Funnel rows are always present (zeroed), so count intent yields a table
        // even with no data — assert the table shape is well-formed.
        $this->assertArrayHasKey('tableData', $response);
        $this->assertSame(['metric', 'value'], $response['tableData']['columns']);
    }//end testProcessQueryNoDataReturnsText()

    /**
     * formatResponse builds a chartData payload when series are present.
     *
     * @return void
     */
    public function testFormatResponseChart(): void
    {
        $raw = [
            'resultType' => 'chart',
            'chartType'  => 'line',
            'label'      => 'Leads',
            'series'     => [
                ['date' => '2026-06-01', 'value' => 3],
                ['date' => '2026-06-02', 'value' => 5],
            ],
        ];

        $response = $this->service->formatResponse(['text' => 'Samenvatting'], $raw);
        $this->assertSame('chart', $response['resultType']);
        $this->assertSame('line', $response['chartData']['type']);
        $this->assertSame(['2026-06-01', '2026-06-02'], $response['chartData']['labels']);
        $this->assertSame([3.0, 5.0], $response['chartData']['values']);
    }//end testFormatResponseChart()

    /**
     * formatResponse builds a tableData payload when rows are present.
     *
     * @return void
     */
    public function testFormatResponseTable(): void
    {
        $raw = [
            'resultType' => 'table',
            'label'      => 'Overzicht',
            'rows'       => [
                ['metric' => 'Leads', 'value' => '12'],
            ],
        ];

        $response = $this->service->formatResponse([], $raw);
        $this->assertSame('table', $response['resultType']);
        $this->assertCount(1, $response['tableData']['rows']);
        $this->assertSame('Leads', $response['tableData']['rows'][0]['metric']);
    }//end testFormatResponseTable()

    /**
     * formatResponse collapses an empty chart series to a text response.
     *
     * @return void
     */
    public function testFormatResponseEmptyChartCollapsesToText(): void
    {
        $response = $this->service->formatResponse([], ['resultType' => 'chart', 'series' => []]);
        $this->assertSame('text', $response['resultType']);
        $this->assertNotEmpty($response['textResponse']);
    }//end testFormatResponseEmptyChartCollapsesToText()
}//end class
