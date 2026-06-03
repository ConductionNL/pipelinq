<?php

/**
 * Unit tests for SlaAttainmentService.
 *
 * Validates per-target accounting and in-flight vs. closed breach separation
 * (REQ-006) with a mocked ObjectService.
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\SlaAttainmentService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SlaAttainmentService.
 */
class SlaAttainmentServiceTest extends TestCase
{
    /**
     * The mocked ObjectService.
     *
     * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * The service under test.
     *
     * @var SlaAttainmentService
     */
    private SlaAttainmentService $service;

    /**
     * Wire the service with a single tracked type (request).
     *
     * @return void
     */
    protected function setUp(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                $map = [
                    'register'          => 'reg-1',
                    'request_schema'    => 'sch-request',
                    'sla_tracked_types' => 'request',
                ];
                return ($map[$key] ?? $default);
            }
        );

        $this->objectService = $this->createMock(ObjectService::class);
        $container           = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $this->service = new SlaAttainmentService($appConfig, $container, $this->createMock(LoggerInterface::class));
    }//end setUp()

    /**
     * Build a tracked request with given target outcomes.
     *
     * @param string                       $id      The object id.
     * @param string                       $status  The object status.
     * @param array<int, array<string,mixed>> $targets The slaStatus targets.
     *
     * @return array<string, mixed> The object.
     */
    private function request(string $id, string $status, array $targets): array
    {
        return [
            'id'        => $id,
            'status'    => $status,
            'client'    => 'org-a',
            'slaTier'   => 'gold',
            'assignee'  => 'team-1',
            'slaStatus' => [
                'policyId'  => 'policy-1',
                'startedAt' => '2026-05-20T10:00:00+00:00',
                'targets'   => $targets,
            ],
        ];
    }//end request()

    /**
     * Per-target accounting: met ack + breached resolution counts separately (REQ-006).
     *
     * @return void
     */
    public function testPerTargetAccounting(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->request(
                    'req-1',
                    'completed',
                    [
                        ['kind' => 'acknowledgement', 'status' => 'met'],
                        ['kind' => 'resolution', 'status' => 'breached'],
                    ]
                ),
            ]
        );

        $report = $this->service->report(['bucket' => 'quarter', 'quarter' => '2026-Q2', 'groupBy' => 'policy']);

        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['met']);
        $this->assertSame(1, $report['breached']);
        $this->assertSame(1.0, $report['details']['byTarget']['acknowledgement']['attainment']);
        $this->assertSame(0.0, $report['details']['byTarget']['resolution']['attainment']);
    }//end testPerTargetAccounting()

    /**
     * In-flight breach (still open) is separated from closed breach (REQ-006).
     *
     * @return void
     */
    public function testInFlightVsClosedBreach(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                // Open object, breached resolution => in-flight breach.
                $this->request('req-open', 'in_progress', [['kind' => 'resolution', 'status' => 'breached']]),
                // Closed object, breached resolution => closed breach.
                $this->request('req-closed', 'completed', [['kind' => 'resolution', 'status' => 'breached']]),
            ]
        );

        $report = $this->service->report(['bucket' => 'quarter', 'quarter' => '2026-Q2']);

        $this->assertSame(2, $report['breached']);
        $this->assertSame(1, $report['inFlightBreached']);
        $this->assertSame(1, $report['closedBreached']);
    }//end testInFlightVsClosedBreach()

    /**
     * On-track / at-risk (undecided) targets are excluded from attainment.
     *
     * @return void
     */
    public function testUndecidedTargetsExcluded(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->request('req-1', 'in_progress', [['kind' => 'resolution', 'status' => 'on-track']]),
                $this->request('req-2', 'completed', [['kind' => 'acknowledgement', 'status' => 'met']]),
            ]
        );

        $report = $this->service->report(['bucket' => 'quarter', 'quarter' => '2026-Q2']);

        $this->assertSame(1, $report['total']);
        $this->assertSame(1.0, $report['attainment']);
    }//end testUndecidedTargetsExcluded()

    /**
     * Grouping by tier produces a per-tier breakdown (REQ-006).
     *
     * @return void
     */
    public function testGroupByTier(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [$this->request('req-1', 'completed', [['kind' => 'resolution', 'status' => 'met']])]
        );

        $report = $this->service->report(['bucket' => 'quarter', 'quarter' => '2026-Q2', 'groupBy' => 'tier']);

        $this->assertCount(1, $report['details']['byGroup']);
        $this->assertSame('gold', $report['details']['byGroup'][0]['groupKey']);
        $this->assertSame(1.0, $report['details']['byGroup'][0]['attainment']);
    }//end testGroupByTier()

    /**
     * Objects whose start falls outside the bucket window are excluded.
     *
     * @return void
     */
    public function testWindowFiltersByStart(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [$this->request('req-1', 'completed', [['kind' => 'resolution', 'status' => 'met']])]
        );

        // Q1 window excludes the 2026-05-20 start.
        $report = $this->service->report(['bucket' => 'quarter', 'quarter' => '2026-Q1']);
        $this->assertSame(0, $report['total']);
    }//end testWindowFiltersByStart()
}//end class
