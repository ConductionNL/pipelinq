<?php

/**
 * Unit tests for SlaTrackingService.
 *
 * Exercises the orchestration layer with a deterministic clock and a mocked
 * ObjectService: slaStatus initialisation on create, pause on status change,
 * the event re-entrancy guard, and tracked-type gating (REQ-003/REQ-007).
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

use DateTime;
use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCA\Pipelinq\Service\SlaEscalationDispatcher;
use OCA\Pipelinq\Service\SlaTrackingService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SlaTrackingService.
 */
class SlaTrackingServiceTest extends TestCase
{
    /**
     * The mocked OpenRegister ObjectService.
     *
     * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * The configured "now" returned by the time factory.
     *
     * @var string
     */
    private string $nowString = '2026-05-20T10:00:00+00:00';

    /**
     * The service under test.
     *
     * @var SlaTrackingService
     */
    private SlaTrackingService $service;

    /**
     * Wire a tracking service with a real engine + deterministic clock.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = ''): string {
                $map = [
                    'register'             => 'reg-1',
                    'slaPolicy_schema'     => 'sch-policy',
                    'request_schema'       => 'sch-request',
                    'sla_tracked_types'    => 'request,complaint',
                ];
                return ($map[$key] ?? $default);
            }
        );

        $logger     = $this->createMock(LoggerInterface::class);
        $holidays   = new HolidayCalendarService($appConfig, $logger);
        $calculator = new BusinessHoursCalculator($holidays, $appConfig);
        $dispatcher = $this->createMock(SlaEscalationDispatcher::class);
        $dispatcher->method('dispatch')->willReturn(['actor']);

        $this->objectService = $this->createMock(ObjectService::class);
        $container           = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $engine = new SlaEngineService($calculator, $dispatcher, $appConfig, $container, $logger);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturnCallback(
            fn (): DateTime => new DateTime($this->nowString)
        );

        $this->service = new SlaTrackingService($engine, $time, $appConfig, $container, $logger);
    }//end setUp()

    /**
     * A standard request policy used for resolution in tests.
     *
     * @return array<string, mixed> The policy.
     */
    private function requestPolicy(): array
    {
        return [
            'id'              => 'policy-1',
            'name'            => 'Standaard request-SLA',
            'appliesTo'       => 'request',
            'customerTier'    => '*',
            'priority'        => 100,
            'active'          => true,
            'status'          => 'published',
            'holidayCalendar' => 'none',
            'pauseConditions' => ['awaiting-customer'],
            'targets'         => [['kind' => 'acknowledgement', 'duration' => 'PT4H', 'calendar' => '24x7']],
            'escalationChain' => [],
        ];
    }//end requestPolicy()

    /**
     * isTracked honours the configured tracked-types list.
     *
     * @return void
     */
    public function testIsTracked(): void
    {
        $this->assertTrue($this->service->isTracked('request'));
        $this->assertTrue($this->service->isTracked('complaint'));
        $this->assertFalse($this->service->isTracked('lead'));
    }//end testIsTracked()

    /**
     * onCreated resolves a policy, computes deadlines and persists slaStatus.
     *
     * @return void
     */
    public function testOnCreatedInitialisesStatus(): void
    {
        // Policy lookup (findAll) returns the baseline request policy.
        $this->objectService->method('findAll')->willReturn([$this->requestPolicy()]);

        $saved = null;
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function ($data) use (&$saved) {
                    $saved = $data;
                    return $data;
                }
            );

        $status = $this->service->onCreated('request', 'obj-1', ['title' => 'Need help']);

        $this->assertIsArray($status);
        $this->assertSame('policy-1', $status['policyId']);
        $this->assertSame('2026-05-20T14:00:00+00:00', $status['targets'][0]['dueAt']);
        $this->assertIsArray($saved);
        $this->assertSame($status, $saved['slaStatus']);
    }//end testOnCreatedInitialisesStatus()

    /**
     * onCreated with no matching policy leaves the object untouched.
     *
     * @return void
     */
    public function testOnCreatedNoPolicyDoesNotPersist(): void
    {
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->expects($this->never())->method('saveObject');

        $status = $this->service->onCreated('request', 'obj-1', ['title' => 'Need help']);
        $this->assertNull($status);
    }//end testOnCreatedNoPolicyDoesNotPersist()

    /**
     * onUpdated pauses the timer when the status enters a pause condition.
     *
     * @return void
     */
    public function testOnUpdatedPausesTimer(): void
    {
        $this->objectService->method('find')->willReturn($this->requestPolicy());
        $this->objectService->method('saveObject')->willReturnArgument(0);

        $existingStatus = [
            'policyId'               => 'policy-1',
            'startedAt'              => '2026-05-20T08:00:00+00:00',
            'pausedAt'               => null,
            'totalPausedMs'          => 0,
            'targets'                => [
                ['kind' => 'acknowledgement', 'calendar' => '24x7', 'dueAt' => '2026-05-20T12:00:00+00:00', 'consumedPercentage' => 0.5, 'status' => 'on-track', 'metAt' => null, 'breachEventIds' => []],
            ],
            'currentEscalationLevel' => 0,
            'lastEvaluatedAt'        => '2026-05-20T09:00:00+00:00',
        ];

        $status = $this->service->onUpdated(
            'request',
            'obj-1',
            ['title' => 'x', 'status' => 'awaiting-customer', 'slaStatus' => $existingStatus],
            ['title' => 'x', 'status' => 'in_progress']
        );

        $this->assertIsArray($status);
        $this->assertNotNull($status['pausedAt']);
        // Deadline unchanged on pause.
        $this->assertSame('2026-05-20T12:00:00+00:00', $status['targets'][0]['dueAt']);
    }//end testOnUpdatedPausesTimer()

    /**
     * The re-entrancy guard blocks a nested onUpdated during persist.
     *
     * The mocked saveObject re-enters onUpdated for the same id; the guard must
     * make that nested call a no-op so there is exactly one save.
     *
     * @return void
     */
    public function testReentrancyGuard(): void
    {
        $this->objectService->method('findAll')->willReturn([$this->requestPolicy()]);

        $calls = 0;
        $this->objectService->method('saveObject')->willReturnCallback(
            function ($data) use (&$calls) {
                $calls++;
                // Simulate OR re-emitting an update event during the save.
                $this->service->onUpdated('request', 'obj-1', $data, $data);
                return $data;
            }
        );

        $this->service->onCreated('request', 'obj-1', ['title' => 'Need help']);

        $this->assertSame(1, $calls, 'saveObject must be called exactly once (no recursion)');
    }//end testReentrancyGuard()
}//end class
