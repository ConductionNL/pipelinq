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
use RuntimeException;

/**
 * Tests AnalyticsService summary aggregation, period boundary maths and
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
     * `month` aggregates active-lead value, open requests (anything not in
     * closed/rejected) and contactmomenten within the last 30 days.
     *
     * @return void
     */
    public function testGetSummaryMonth(): void
    {
        $recent = (new \DateTimeImmutable('-3 days'))->format(\DateTimeInterface::ATOM);
        $stale  = (new \DateTimeImmutable('-200 days'))->format(\DateTimeInterface::ATOM);

        $service = $this->buildService(
            byCollection: [
                'lead_schema' => [
                    ['status' => 'open',   'value' => 1000],
                    ['status' => 'active', 'value' => 500.5],
                    ['status' => 'won',    'value' => 999],
                    ['status' => 'lost',   'value' => 42],
                ],
                'request_schema' => [
                    ['status' => 'new'],
                    ['status' => 'in_progress'],
                    ['status' => 'closed'],
                    ['status' => 'rejected'],
                ],
                'contactmoment_schema' => [
                    ['contactedAt' => $recent],
                    ['contactedAt' => $recent],
                    ['contactedAt' => $stale],
                    ['contactedAt' => ''],
                ],
            ]
        );

        $summary = $service->getSummary(period: 'month');

        $this->assertSame(1500.5, $summary['openPipelineValue']);
        $this->assertSame(2, $summary['openRequests']);
        $this->assertSame(2, $summary['contactmomentenCount']);
        $this->assertSame(2, $summary['activeLeads']);
        $this->assertSame('month', $summary['period']);
    }

    /**
     * `week` boundary is 7 days; contactmomenten older than that are excluded.
     *
     * @return void
     */
    public function testGetSummaryWeekBoundary(): void
    {
        $insideWeek  = (new \DateTimeImmutable('-2 days'))->format(\DateTimeInterface::ATOM);
        $outsideWeek = (new \DateTimeImmutable('-10 days'))->format(\DateTimeInterface::ATOM);

        $service = $this->buildService(
            byCollection: [
                'contactmoment_schema' => [
                    ['contactedAt' => $insideWeek],
                    ['contactedAt' => $outsideWeek],
                ],
            ]
        );

        $summary = $service->getSummary(period: 'week');
        $this->assertSame(1, $summary['contactmomentenCount']);
        $this->assertSame('week', $summary['period']);
    }

    /**
     * `quarter` includes contactmomenten up to 90 days back.
     *
     * @return void
     */
    public function testGetSummaryQuarterBoundary(): void
    {
        $within  = (new \DateTimeImmutable('-60 days'))->format(\DateTimeInterface::ATOM);
        $outside = (new \DateTimeImmutable('-120 days'))->format(\DateTimeInterface::ATOM);

        $service = $this->buildService(
            byCollection: [
                'contactmoment_schema' => [
                    ['contactedAt' => $within],
                    ['contactedAt' => $outside],
                ],
            ]
        );

        $summary = $service->getSummary(period: 'quarter');
        $this->assertSame(1, $summary['contactmomentenCount']);
        $this->assertSame('quarter', $summary['period']);
    }

    /**
     * Empty config path returns zeroes for every KPI (no register/schema mapped).
     *
     * @return void
     */
    public function testGetSummaryReturnsZeroesWhenRegisterMissing(): void
    {
        $service = $this->buildService(byCollection: [], registerMissing: true);
        $summary = $service->getSummary(period: 'month');

        $this->assertSame(0.0, $summary['openPipelineValue']);
        $this->assertSame(0, $summary['openRequests']);
        $this->assertSame(0, $summary['contactmomentenCount']);
        $this->assertSame(0, $summary['activeLeads']);
    }

    /**
     * Invalid period rejects with a static error message (`Invalid period`).
     *
     * @return void
     */
    public function testGetSummaryRejectsInvalidPeriod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid period');

        $service = $this->buildService();
        $service->getSummary(period: 'yesterday');
    }

    /**
     * ObjectService failures wrap into a RuntimeException whose message
     * does NOT carry the underlying text (controller maps to a static 500).
     *
     * @return void
     */
    public function testGetSummaryWrapsObjectServiceFailure(): void
    {
        $service = $this->buildService(throwFromObjectService: true);

        try {
            $service->getSummary(period: 'month');
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Analytics query failed', $e->getMessage());
            $this->assertStringNotContainsString('boom', $e->getMessage());
        }
    }
}
