<?php

/**
 * Unit tests for SlaDeadlineSweepJob.
 *
 * Verifies silent-deadline-crossing detection and idempotent re-runs (REQ-008)
 * using a mocked ObjectService and a real engine/tracking stack.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
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

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\BackgroundJob\SlaDeadlineSweepJob;
use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCA\Pipelinq\Service\SlaEscalationDispatcher;
use OCA\Pipelinq\Service\SlaTrackingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for SlaDeadlineSweepJob.
 */
class SlaDeadlineSweepJobTest extends TestCase
{
    /**
     * The mocked ObjectService.
     *
     * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * The escalation dispatcher mock.
     *
     * @var SlaEscalationDispatcher&\PHPUnit\Framework\MockObject\MockObject
     */
    private $dispatcher;

    /**
     * The job under test.
     *
     * @var SlaDeadlineSweepJob
     */
    private SlaDeadlineSweepJob $job;

    /**
     * Wire the job with a real engine + tracking and mocked OR ObjectService.
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
                    'slaPolicy_schema'  => 'sch-policy',
                ];
                return ($map[$key] ?? $default);
            }
        );
        $appConfig->method('getValueInt')->willReturnCallback(
            static function (string $app, string $key, int $default = 0): int {
                return $default;
            }
        );

        $logger     = $this->createMock(LoggerInterface::class);
        $holidays   = new HolidayCalendarService($appConfig, $logger);
        $calculator = new BusinessHoursCalculator($holidays, $appConfig);
        $this->dispatcher = $this->createMock(SlaEscalationDispatcher::class);
        $this->dispatcher->method('dispatch')->willReturn(['team-lead']);

        $this->objectService = $this->createMock(ObjectService::class);
        $container           = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $engine   = new SlaEngineService($calculator, $this->dispatcher, $appConfig, $container, $logger);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturnCallback(
            static fn (): DateTime => new DateTime('2026-05-20T18:00:00+00:00')
        );

        $tracking = new SlaTrackingService($engine, $time, $appConfig, $container, $logger);

        $this->job = new SlaDeadlineSweepJob($time, $tracking, $appConfig, $container, $logger);
    }//end setUp()

    /**
     * Build a request object whose acknowledgement deadline has silently passed.
     *
     * @param int $level The current escalation level.
     *
     * @return array<string, mixed> The request object data.
     */
    private function breachedRequest(int $level = 0): array
    {
        return [
            'id'        => 'req-1',
            'title'     => 'Silent breach',
            'status'    => 'in_progress',
            'slaStatus' => [
                'policyId'               => 'policy-1',
                'startedAt'              => '2026-05-20T10:00:00+00:00',
                'pausedAt'               => null,
                'totalPausedMs'          => 0,
                'targets'                => [
                    [
                        'kind'               => 'acknowledgement',
                        'calendar'           => '24x7',
                        'dueAt'              => '2026-05-20T14:00:00+00:00',
                        'consumedPercentage' => 0.5,
                        'status'             => 'on-track',
                        'metAt'              => null,
                        'breachEventIds'     => [],
                    ],
                ],
                'currentEscalationLevel' => $level,
                'lastEvaluatedAt'        => '2026-05-20T12:00:00+00:00',
            ],
        ];
    }//end breachedRequest()

    /**
     * The bound policy returned by find() during reconcile.
     *
     * @return array<string, mixed> The policy.
     */
    private function policy(): array
    {
        return [
            'id'              => 'policy-1',
            'appliesTo'       => 'request',
            'customerTier'    => '*',
            'priority'        => 100,
            'active'          => true,
            'status'          => 'published',
            'holidayCalendar' => 'none',
            'pauseConditions' => [],
            'targets'         => [['kind' => 'acknowledgement', 'duration' => 'PT4H', 'calendar' => '24x7']],
            'escalationChain' => [['triggerAt' => 1.0, 'notify' => 'team-lead', 'channel' => 'email']],
        ];
    }//end policy()

    /**
     * Invoke the protected run() method.
     *
     * @return void
     */
    private function invokeRun(): void
    {
        $ref = new ReflectionMethod($this->job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($this->job, null);
    }//end invokeRun()

    /**
     * The sweep detects a silent breach and fires the escalation once (REQ-008).
     *
     * @return void
     */
    public function testSweepDetectsSilentBreachAndEscalates(): void
    {
        // First findAll = page of objects; subsequent = empty page; policy reload via find().
        $this->objectService->method('findAll')->willReturnOnConsecutiveCalls(
            [$this->breachedRequest()],
            []
        );
        $this->objectService->method('find')->willReturn($this->policy());

        // The escalation must dispatch exactly once for the breached object.
        $this->dispatcher->expects($this->once())->method('dispatch');
        // And the breach status must be persisted.
        $this->objectService->expects($this->atLeastOnce())->method('saveObject')->willReturnArgument(0);

        $this->invokeRun();
    }//end testSweepDetectsSilentBreachAndEscalates()

    /**
     * A re-run over an already-escalated object does not re-fire (idempotent).
     *
     * @return void
     */
    public function testSweepIsIdempotent(): void
    {
        // Object already at escalation level 1 with a breached target.
        $already = $this->breachedRequest(1);
        $already['slaStatus']['targets'][0]['status']             = 'breached';
        $already['slaStatus']['targets'][0]['consumedPercentage'] = 1.5;

        $this->objectService->method('findAll')->willReturnOnConsecutiveCalls([$already], []);
        $this->objectService->method('find')->willReturn($this->policy());

        // Already at level 1 and target already breached: no new dispatch, no save.
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->objectService->expects($this->never())->method('saveObject');

        $this->invokeRun();
    }//end testSweepIsIdempotent()

    /**
     * Paused objects are skipped by the sweep (REQ-003).
     *
     * @return void
     */
    public function testSweepSkipsPausedObjects(): void
    {
        $paused = $this->breachedRequest();
        $paused['slaStatus']['pausedAt'] = '2026-05-20T11:00:00+00:00';

        $this->objectService->method('findAll')->willReturnOnConsecutiveCalls([$paused], []);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->invokeRun();
    }//end testSweepSkipsPausedObjects()
}//end class
