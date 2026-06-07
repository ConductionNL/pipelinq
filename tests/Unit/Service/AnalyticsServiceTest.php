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
     * Build a service with deterministic schema/register config and an
     * ObjectService double whose `findAll` returns the supplied list
     * (keyed by schema key) so tests can pre-seed leads / requests /
     * contactmomenten.
     *
     * @param array<string, array<int, array<string, mixed>>> $byCollection Map of
     *        schema config key ("lead_schema", "request_schema",
     *        "contactmoment_schema") to a list of object arrays.
     * @param bool                                            $registerMissing If
     *        true, the appConfig returns an empty `register` so the service
     *        treats every collection as empty (configuration-not-set path).
     * @param bool                                            $throwFromObjectService Force the ObjectService
     *        double to throw on findAll (error propagation path).
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
                    return $registerMissing ? '' : 'register-1';
                }

                // lead_schema, request_schema, contactmoment_schema — map to themselves.
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
    }//end buildService()

    /**
     * Summary for the `month` window aggregates active-lead value, open
     * requests (anything not in closed/rejected) and contactmomenten
     * within the last 30 days.
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
                    ['status' => 'active', 'value' => 1000],
                    ['status' => 'active', 'value' => 500.5],
                    ['status' => 'won',    'value' => 999],   // not counted (status != active).
                    ['status' => 'lost',   'value' => 42],    // not counted.
                ],
                'request_schema' => [
                    ['status' => 'new'],
                    ['status' => 'in_progress'],
                    ['status' => 'closed'],   // closed -> excluded.
                    ['status' => 'rejected'], // rejected -> excluded.
                ],
                'contactmoment_schema' => [
                    ['contactedAt' => $recent],
                    ['contactedAt' => $recent],
                    ['contactedAt' => $stale],  // outside window.
                    ['contactedAt' => ''],      // invalid timestamp.
                ],
            ]
        );

        $summary = $service->getSummary(period: 'month');

        $this->assertSame(1500.5, $summary['openPipelineValue']);
        $this->assertSame(2, $summary['openRequests']);
        $this->assertSame(2, $summary['contactmomentenCount']);
        $this->assertSame(2, $summary['activeLeads']);
        $this->assertSame('month', $summary['period']);
    }//end testGetSummaryMonth()

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
    }//end testGetSummaryWeekBoundary()

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
    }//end testGetSummaryQuarterBoundary()

    /**
     * Empty-config path returns zeroes (no register/schema mapped).
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
    }//end testGetSummaryReturnsZeroesWhenRegisterMissing()

    /**
     * Invalid period rejects with InvalidArgumentException carrying a
     * static error message (caller can map directly to HTTP 400).
     *
     * @return void
     */
    public function testGetSummaryRejectsInvalidPeriod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid period');

        $service = $this->buildService();
        $service->getSummary(period: 'yesterday');
    }//end testGetSummaryRejectsInvalidPeriod()

    /**
     * ObjectService throwing is wrapped in RuntimeException — the original
     * message must NOT bubble through to the caller (controller maps to
     * the static 500 string per ADR-004 / REQ-KB360-020 error contract).
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
    }//end testGetSummaryWrapsObjectServiceFailure()
}//end class
