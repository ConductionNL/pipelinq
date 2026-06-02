<?php

/**
 * Unit tests for AnalyticsService.
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

use OCA\Pipelinq\Service\AnalyticsService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AnalyticsService.
 *
 * Covers period validation, period boundary computation, KPI aggregation,
 * open-request filtering, contactmoment period windowing and the error path.
 */
class AnalyticsServiceTest extends TestCase
{

    /**
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Build a stub ObjectService whose findAll returns canned objects keyed
     * by schema id.
     *
     * @param array<string, array<int, array<string, mixed>>> $bySchema Schema id → objects.
     * @param bool                                             $throw    Whether findAll throws.
     *
     * @return object The stub.
     */
    private function makeObjectServiceStub(array $bySchema, bool $throw=false): object
    {
        return new class($bySchema, $throw) {

            /**
             * @param array<string, array<int, array<string, mixed>>> $bySchema Schema map.
             * @param bool                                             $throw    Throw flag.
             */
            public function __construct(
                private array $bySchema,
                private bool $throw,
            ) {
            }

            /**
             * @param  array<string, mixed> $config
             * @return array<int, mixed>
             */
            public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
            {
                if ($this->throw === true) {
                    throw new \RuntimeException('boom');
                }

                $schema = (string) ($config['filters']['schema'] ?? '');
                return $this->bySchema[$schema] ?? [];
            }//end findAll()
        };
    }//end makeObjectServiceStub()

    /**
     * Set up shared mocks. AppConfig maps register + the three schema keys.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
                return match ($key) {
                    'register'              => 'reg',
                    'lead_schema'          => 'leadS',
                    'request_schema'       => 'reqS',
                    'contactmoment_schema' => 'cmS',
                    default                 => $default,
                };
            }
        );
    }//end setUp()

    /**
     * Build the service with a given ObjectService stub wired into the container.
     *
     * @param object $stub The ObjectService stub.
     *
     * @return AnalyticsService The service under test.
     */
    private function makeService(object $stub): AnalyticsService
    {
        $this->container->method('get')->willReturn($stub);
        return new AnalyticsService($this->appConfig, $this->container, $this->logger);
    }//end makeService()

    /**
     * isValidPeriod accepts the three windows and rejects others.
     *
     * @return void
     */
    public function testIsValidPeriod(): void
    {
        $service = new AnalyticsService($this->appConfig, $this->container, $this->logger);

        $this->assertTrue($service->isValidPeriod('week'));
        $this->assertTrue($service->isValidPeriod('month'));
        $this->assertTrue($service->isValidPeriod('quarter'));
        $this->assertFalse($service->isValidPeriod('year'));
        $this->assertFalse($service->isValidPeriod(''));
    }//end testIsValidPeriod()

    /**
     * Period boundaries differ by window: week > month > quarter (more recent).
     *
     * @return void
     */
    public function testGetPeriodBoundaryOrdering(): void
    {
        $service = new AnalyticsService($this->appConfig, $this->container, $this->logger);

        $week    = $service->getPeriodBoundary('week');
        $month   = $service->getPeriodBoundary('month');
        $quarter = $service->getPeriodBoundary('quarter');

        // A shorter window means a more recent (later) boundary.
        $this->assertGreaterThan($month, $week);
        $this->assertGreaterThan($quarter, $month);

        // Boundary is normalised to start of day.
        $this->assertSame('00:00:00', $week->format('H:i:s'));

        // Quarter is ~90 days back.
        $now      = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diffDays = (int) $now->diff($quarter)->days;
        $this->assertGreaterThanOrEqual(89, $diffDays);
        $this->assertLessThanOrEqual(91, $diffDays);
    }//end testGetPeriodBoundaryOrdering()

    /**
     * getSummary aggregates active-lead value/count, open requests and
     * contactmomenten within the period.
     *
     * @return void
     */
    public function testGetSummaryAggregates(): void
    {
        $recent = (new \DateTimeImmutable('-2 days'))->format('c');
        $old    = (new \DateTimeImmutable('-200 days'))->format('c');

        $stub = $this->makeObjectServiceStub(
            [
                'leadS' => [
                    ['status' => 'active', 'value' => 1000],
                    ['status' => 'active', 'value' => 500],
                    ['status' => 'won', 'value' => 9999],
                    ['status' => 'lost', 'value' => 1],
                ],
                'reqS' => [
                    ['status' => 'new'],
                    ['status' => 'in_progress'],
                    ['status' => 'closed'],
                    ['status' => 'rejected'],
                ],
                'cmS' => [
                    ['contactedAt' => $recent],
                    ['contactedAt' => $recent],
                    ['contactedAt' => $old],
                    ['contactedAt' => ''],
                ],
            ]
        );

        $service = $this->makeService($stub);
        $summary = $service->getSummary('month');

        $this->assertSame(1500.0, $summary['openPipelineValue']);
        $this->assertSame(2, $summary['activeLeads']);
        $this->assertSame(2, $summary['openRequests']);
        $this->assertSame(2, $summary['contactmomentenCount']);
        $this->assertSame('month', $summary['period']);
    }//end testGetSummaryAggregates()

    /**
     * A 'week' period excludes contactmomenten older than 7 days that a
     * 'quarter' would include — verifies the boundary is actually applied.
     *
     * @return void
     */
    public function testGetSummaryWeekPeriodWindowsContactmomenten(): void
    {
        $within = (new \DateTimeImmutable('-3 days'))->format('c');
        $outside = (new \DateTimeImmutable('-40 days'))->format('c');

        $stub = $this->makeObjectServiceStub(
            [
                'cmS' => [
                    ['contactedAt' => $within],
                    ['contactedAt' => $outside],
                ],
            ]
        );

        $service = $this->makeService($stub);

        $week    = $service->getSummary('week');
        $quarter = $service->getSummary('quarter');

        $this->assertSame(1, $week['contactmomentenCount']);
        $this->assertSame(2, $quarter['contactmomentenCount']);
    }//end testGetSummaryWeekPeriodWindowsContactmomenten()

    /**
     * With no register configured the summary is all-zero, not an error.
     *
     * @return void
     */
    public function testGetSummaryReturnsZeroWhenRegisterUnconfigured(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default=''): string => $default
        );

        $service = new AnalyticsService($appConfig, $this->container, $this->logger);
        $summary = $service->getSummary('month');

        $this->assertSame(0.0, $summary['openPipelineValue']);
        $this->assertSame(0, $summary['openRequests']);
        $this->assertSame(0, $summary['contactmomentenCount']);
        $this->assertSame(0, $summary['activeLeads']);
    }//end testGetSummaryReturnsZeroWhenRegisterUnconfigured()

    /**
     * An invalid period falls back to 'month' rather than erroring.
     *
     * @return void
     */
    public function testGetSummaryFallsBackToMonthOnInvalidPeriod(): void
    {
        $stub    = $this->makeObjectServiceStub([]);
        $service = $this->makeService($stub);

        $summary = $service->getSummary('nonsense');
        $this->assertSame('month', $summary['period']);
    }//end testGetSummaryFallsBackToMonthOnInvalidPeriod()

    /**
     * When the data store throws, the service raises a RuntimeException with a
     * static message (never the underlying getMessage), and logs the error.
     *
     * @return void
     */
    public function testGetSummaryPropagatesStaticErrorOnFailure(): void
    {
        $stub = $this->makeObjectServiceStub([], true);
        $this->logger->expects($this->once())->method('error');

        $service = $this->makeService($stub);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Analytics aggregation failed');
        $service->getSummary('month');
    }//end testGetSummaryPropagatesStaticErrorOnFailure()
}//end class
