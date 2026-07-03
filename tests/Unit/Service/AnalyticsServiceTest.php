<?php

/**
 * Unit tests for the Klantbeeld 360 AnalyticsService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Pipelinq\Service\AnalyticsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests AnalyticsService overview / trends / funnels aggregation and
 * error-path semantics (never leaks the underlying exception text).
 */
class AnalyticsServiceTest extends TestCase
{
    /**
     * Build a service with deterministic config and a fake ObjectService.
     *
     * @param array<string, array<int, array<string, mixed>>> $byCollection           Per-schema fixture rows.
     * @param bool                                            $registerMissing        Force the app-config `register` empty.
     * @param bool                                            $throwFromObjectService Force the fake to throw on findAll.
     *
     * @return AnalyticsService
     */
    private function buildService(
        array $byCollection = [],
        bool $registerMissing = false,
        bool $throwFromObjectService = false,
    ): AnalyticsService {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $appId, string $key, string $default = '') use ($registerMissing): string {
                if ($key === 'register') {
                    return $registerMissing === true ? '' : 'register-1';
                }
                return $key;
            }
        );

        $objectService = new class($byCollection, $throwFromObjectService) {
            /**
             * @param array<string, array<int, array<string, mixed>>> $byCollection
             */
            public function __construct(private array $byCollection, private bool $throwAlways)
            {
            }

            /**
             * @param array{filters?: array<string, mixed>} $config
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $config): array
            {
                if ($this->throwAlways === true) {
                    throw new \RuntimeException('boom');
                }
                $schema = (string) ($config['filters']['schema'] ?? '');
                return $this->byCollection[$schema] ?? [];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $logger = $this->createMock(LoggerInterface::class);

        return new AnalyticsService(container: $container, appConfig: $appConfig, logger: $logger);
    }

    /**
     * getOverview returns conversion-rate, contact volume, satisfaction
     * score plus a previousPeriod block of the same shape.
     *
     * @return void
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.3
     */
    public function testGetOverviewReturnsAllKpiFields(): void
    {
        $recent  = (new \DateTimeImmutable('-3 days'))->format(\DateTimeInterface::ATOM);
        $earlier = (new \DateTimeImmutable('-40 days'))->format(\DateTimeInterface::ATOM);

        $service = $this->buildService(byCollection: [
            'lead_schema' => [
                ['status' => 'won',  'createdAt' => $recent,  'value' => 100],
                ['status' => 'lost', 'createdAt' => $recent,  'value' => 0],
                ['status' => 'open', 'createdAt' => $recent,  'value' => 0],
                ['status' => 'won',  'createdAt' => $earlier, 'value' => 0],
            ],
            'request_schema' => [
                ['requestedAt' => $recent, 'completedAt' => $recent, 'status' => 'completed'],
            ],
            'contactmoment_schema' => [
                ['contactedAt' => $recent],
                ['contactedAt' => $recent],
                ['contactedAt' => $earlier],
            ],
        ]);

        $overview = $service->getOverview(period: 'month');

        $this->assertSame(33.3, $overview['leadConversionRate']);
        $this->assertSame(0.0, $overview['avgRequestResolutionTime']);
        $this->assertSame(2, $overview['contactMomentVolume']);
        // CSAT is sourced from the OpenRegister forms leaf (NC Forms) and is
        // null until that leaf exposes a query helper; see
        // openspec/changes/migrate-forms-to-forms-leaf.
        $this->assertNull($overview['customerSatisfactionScore']);
        $this->assertSame('month', $overview['period']);
        $this->assertArrayHasKey('previousPeriod', $overview);
        $this->assertArrayHasKey('leadConversionRate', $overview['previousPeriod']);
    }

    /**
     * Empty survey responses yield a null customerSatisfactionScore.
     *
     * @return void
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.3
     */
    public function testGetOverviewReturnsNullScoreWhenNoSurveyResponses(): void
    {
        $service  = $this->buildService(byCollection: []);
        $overview = $service->getOverview(period: 'month');
        $this->assertNull($overview['customerSatisfactionScore']);
        $this->assertNull($overview['leadConversionRate']);
        $this->assertNull($overview['avgRequestResolutionTime']);
        $this->assertSame(0, $overview['contactMomentVolume']);
    }

    /**
     * getTrends throws InvalidArgumentException for an unsupported metric.
     *
     * @return void
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.3
     */
    public function testGetTrendsRejectsUnsupportedMetric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported metric');

        $service = $this->buildService();
        $service->getTrends(metric: 'meaning-of-life', period: 'month');
    }

    /**
     * getTrends returns an empty series array when the dataset is empty.
     *
     * @return void
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.3
     */
    public function testGetTrendsReturnsEmptySeriesForNoData(): void
    {
        $service = $this->buildService(byCollection: ['lead_schema' => []]);

        $payload = $service->getTrends(metric: 'leads', period: 'month');
        $this->assertSame('leads', $payload['metric']);
        $this->assertSame('month', $payload['period']);
        $this->assertSame([], $payload['series']);
    }

    /**
     * getTrends groups requests by category, excluding zero-count buckets.
     *
     * @return void
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.3
     */
    public function testGetTrendsRequestsByCategoryBuildsBars(): void
    {
        $recent  = (new \DateTimeImmutable('-2 days'))->format(\DateTimeInterface::ATOM);

        $service = $this->buildService(byCollection: [
            'request_schema' => [
                ['requestedAt' => $recent, 'category' => 'belastingen'],
                ['requestedAt' => $recent, 'category' => 'belastingen'],
                ['requestedAt' => $recent, 'category' => 'vergunningen'],
                ['requestedAt' => $recent, 'category' => ''],
            ],
        ]);

        $payload = $service->getTrends(metric: 'requests-by-category', period: 'month');
        $this->assertSame('requests-by-category', $payload['metric']);
        $this->assertCount(2, $payload['series']);
        $this->assertSame('belastingen', $payload['series'][0]['date']);
        $this->assertSame(2, $payload['series'][0]['value']);
    }

    /**
     * getFunnels returns conversion + resolution rates plus per-status counts.
     *
     * @return void
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.3
     */
    public function testGetFunnelsAggregatesRates(): void
    {
        $service = $this->buildService(byCollection: [
            'lead_schema' => [
                ['status' => 'open'],
                ['status' => 'won'],
                ['status' => 'won'],
                ['status' => 'lost'],
            ],
            'request_schema' => [
                ['status' => 'new'],
                ['status' => 'completed'],
                ['status' => 'completed'],
                ['status' => 'rejected'],
            ],
        ]);

        $funnels = $service->getFunnels();
        $this->assertSame(50.0, $funnels['leadFunnel']['conversionRate']);
        $this->assertSame(50.0, $funnels['requestFunnel']['resolutionRate']);
        $this->assertSame(2, $funnels['leadFunnel']['won']);
        $this->assertSame(2, $funnels['requestFunnel']['completed']);
    }

    /**
     * Empty datasets in getFunnels yield null rates (avoids divide-by-zero).
     *
     * @return void
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.3
     */
    public function testGetFunnelsNullRatesWhenEmpty(): void
    {
        $service = $this->buildService();
        $funnels = $service->getFunnels();
        $this->assertNull($funnels['leadFunnel']['conversionRate']);
        $this->assertNull($funnels['requestFunnel']['resolutionRate']);
    }
}
