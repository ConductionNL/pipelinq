<?php

/**
 * Unit tests for SlaEngineService.
 *
 * Deterministic-clock tests for policy resolution + tie-breaking (REQ-001),
 * deadline computation (REQ-002), target evaluation, pause/resume (REQ-003),
 * and escalation idempotency/ordering (REQ-004).
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

use DateTimeImmutable;
use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCA\Pipelinq\Service\SlaEscalationDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SlaEngineService.
 */
class SlaEngineServiceTest extends TestCase
{
    /**
     * The escalation dispatcher mock.
     *
     * @var SlaEscalationDispatcher&\PHPUnit\Framework\MockObject\MockObject
     */
    private $dispatcher;

    /**
     * The service under test.
     *
     * @var SlaEngineService
     */
    private SlaEngineService $engine;

    /**
     * Set up the engine with a real calculator and a mocked dispatcher.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return $default;
            }
        );

        $logger          = $this->createMock(LoggerInterface::class);
        $holidays        = new HolidayCalendarService($appConfig, $logger);
        $calculator      = new BusinessHoursCalculator($holidays, $appConfig);
        $this->dispatcher = $this->createMock(SlaEscalationDispatcher::class);
        $container       = $this->createMock(ContainerInterface::class);

        $this->engine = new SlaEngineService($calculator, $this->dispatcher, $appConfig, $container, $logger);
    }//end setUp()

    /**
     * Build a minimal policy array.
     *
     * @param array<string, mixed> $overrides Field overrides.
     *
     * @return array<string, mixed> The policy.
     */
    private function policy(array $overrides = []): array
    {
        return array_merge(
            [
                'name'            => 'Test policy',
                'appliesTo'       => 'request',
                'customerTier'    => '*',
                'priority'        => 100,
                'active'          => true,
                'status'          => 'published',
                'holidayCalendar' => 'none',
                'pauseConditions' => ['awaiting-customer'],
                'targets'         => [['kind' => 'acknowledgement', 'duration' => 'PT4H', 'calendar' => '24x7']],
                'escalationChain' => [],
            ],
            $overrides
        );
    }//end policy()

    /**
     * Gold-tier policy wins over the baseline by tier match + priority (REQ-001).
     *
     * @return void
     */
    public function testGoldTierPolicySelectedOverBaseline(): void
    {
        $baseline = $this->policy(['name' => 'Baseline', 'customerTier' => '*', 'priority' => 100]);
        $gold     = $this->policy(['name' => 'Gold', 'customerTier' => 'gold', 'priority' => 10]);

        $winner = $this->engine->resolvePolicyForObject(
            'request',
            ['customerTier' => 'gold'],
            new DateTimeImmutable('2026-05-20T10:00:00+00:00'),
            [$baseline, $gold]
        );

        $this->assertSame('Gold', $winner['name']);
    }//end testGoldTierPolicySelectedOverBaseline()

    /**
     * No tier attribute resolves to the bronze/baseline policy (REQ-001/REQ-005).
     *
     * @return void
     */
    public function testBaselineSelectedWhenNoTier(): void
    {
        $baseline = $this->policy(['name' => 'Baseline', 'customerTier' => '*', 'priority' => 100]);
        $gold     = $this->policy(['name' => 'Gold', 'customerTier' => 'gold', 'priority' => 10]);

        $winner = $this->engine->resolvePolicyForObject(
            'request',
            [],
            new DateTimeImmutable('2026-05-20T10:00:00+00:00'),
            [$baseline, $gold]
        );

        $this->assertSame('Baseline', $winner['name']);
    }//end testBaselineSelectedWhenNoTier()

    /**
     * Most-specific scope (contract) beats organisation scope (REQ-001/REQ-005).
     *
     * @return void
     */
    public function testContractScopeBeatsOrganisationScope(): void
    {
        $contract = $this->policy(
            ['name' => 'Contract', 'customerTier' => 'gold', 'priority' => 100, 'customerScope' => ['contractIds' => ['Y']]]
        );
        $org = $this->policy(
            ['name' => 'Org', 'customerTier' => 'gold', 'priority' => 1, 'customerScope' => ['organisationIds' => ['X']]]
        );

        $winner = $this->engine->resolvePolicyForObject(
            'request',
            ['customerTier' => 'gold', 'contractId' => 'Y', 'organisationId' => 'X'],
            new DateTimeImmutable('2026-05-20T10:00:00+00:00'),
            [$org, $contract]
        );

        $this->assertSame('Contract', $winner['name']);
    }//end testContractScopeBeatsOrganisationScope()

    /**
     * Identical priority/tier ties break on the newest validFrom (REQ-001).
     *
     * @return void
     */
    public function testTieBreakByValidFrom(): void
    {
        $older = $this->policy(['name' => 'Older', 'priority' => 100, 'validFrom' => '2026-05-19T00:00:00+00:00']);
        $newer = $this->policy(['name' => 'Newer', 'priority' => 100, 'validFrom' => '2026-05-20T00:00:00+00:00']);

        $winner = $this->engine->resolvePolicyForObject(
            'request',
            [],
            new DateTimeImmutable('2026-05-21T00:00:00+00:00'),
            [$older, $newer]
        );

        $this->assertSame('Newer', $winner['name']);
    }//end testTieBreakByValidFrom()

    /**
     * No matching policy returns null (fail-safe, REQ-001).
     *
     * @return void
     */
    public function testNoMatchReturnsNull(): void
    {
        $gold = $this->policy(['name' => 'Gold', 'appliesTo' => 'klacht', 'customerTier' => 'gold']);

        $winner = $this->engine->resolvePolicyForObject(
            'request',
            ['customerTier' => 'silver'],
            new DateTimeImmutable('2026-05-20T10:00:00+00:00'),
            [$gold]
        );

        $this->assertNull($winner);
    }//end testNoMatchReturnsNull()

    /**
     * computeDeadlines produces one dueAt per target (REQ-002).
     *
     * @return void
     */
    public function testComputeDeadlines24x7(): void
    {
        $policy = $this->policy(
            [
                'holidayCalendar' => 'none',
                'targets'         => [
                    ['kind' => 'acknowledgement', 'duration' => 'PT4H', 'calendar' => '24x7'],
                    ['kind' => 'resolution', 'duration' => 'PT72H', 'calendar' => '24x7'],
                ],
            ]
        );

        $targets = $this->engine->computeDeadlines($policy, new DateTimeImmutable('2026-05-20T10:00:00+00:00'));

        $this->assertCount(2, $targets);
        $this->assertSame('2026-05-20T14:00:00+00:00', $targets[0]['dueAt']);
        $this->assertSame('2026-05-23T10:00:00+00:00', $targets[1]['dueAt']);
        $this->assertSame('on-track', $targets[0]['status']);
    }//end testComputeDeadlines24x7()

    /**
     * evaluateTargets moves a target to at-risk then breached (REQ-002).
     *
     * @return void
     */
    public function testEvaluateTargetsTransitions(): void
    {
        $status = $this->engine->buildInitialStatus(
            $this->policy(),
            'policy-1',
            new DateTimeImmutable('2026-05-20T10:00:00+00:00')
        );

        // At 80% of a 4h window (3h12m in) it should be at-risk.
        $atRisk = $this->engine->evaluateTargets($status, new DateTimeImmutable('2026-05-20T13:15:00+00:00'));
        $this->assertSame('at-risk', $atRisk['targets'][0]['status']);

        // Past the deadline it should be breached.
        $breached = $this->engine->evaluateTargets($status, new DateTimeImmutable('2026-05-20T14:30:00+00:00'));
        $this->assertSame('breached', $breached['targets'][0]['status']);
    }//end testEvaluateTargetsTransitions()

    /**
     * A resolved object marks targets met with a metAt timestamp (REQ-004).
     *
     * @return void
     */
    public function testResolvedMarksMet(): void
    {
        $status = $this->engine->buildInitialStatus(
            $this->policy(),
            'policy-1',
            new DateTimeImmutable('2026-05-20T10:00:00+00:00')
        );

        $met = $this->engine->evaluateTargets($status, new DateTimeImmutable('2026-05-20T12:00:00+00:00'), true);
        $this->assertSame('met', $met['targets'][0]['status']);
        $this->assertSame('2026-05-20T12:00:00+00:00', $met['targets'][0]['metAt']);
    }//end testResolvedMarksMet()

    /**
     * Pausing freezes status; the deadline does not move on pause (REQ-003).
     *
     * @return void
     */
    public function testPauseFreezesTimer(): void
    {
        $status = $this->engine->buildInitialStatus(
            $this->policy(),
            'policy-1',
            new DateTimeImmutable('2026-05-20T10:00:00+00:00')
        );
        $originalDue = $status['targets'][0]['dueAt'];

        $paused = $this->engine->pauseTimer($status, new DateTimeImmutable('2026-05-20T11:00:00+00:00'));
        $this->assertNotNull($paused['pausedAt']);
        $this->assertSame($originalDue, $paused['targets'][0]['dueAt']);

        // While paused, crossing the original deadline does NOT breach.
        $evaluated = $this->engine->evaluateTargets($paused, new DateTimeImmutable('2026-05-20T15:00:00+00:00'));
        $this->assertSame('on-track', $evaluated['targets'][0]['status']);
    }//end testPauseFreezesTimer()

    /**
     * Resuming extends the deadline by the paused wall-clock time (24x7) (REQ-003).
     *
     * @return void
     */
    public function testResumeExtendsDeadline(): void
    {
        $status = $this->engine->buildInitialStatus(
            $this->policy(),
            'policy-1',
            new DateTimeImmutable('2026-05-20T10:00:00+00:00')
        );
        // Original 24x7 4h deadline = 14:00.
        $this->assertSame('2026-05-20T14:00:00+00:00', $status['targets'][0]['dueAt']);

        $paused  = $this->engine->pauseTimer($status, new DateTimeImmutable('2026-05-20T11:00:00+00:00'));
        $resumed = $this->engine->resumeTimer($paused, new DateTimeImmutable('2026-05-20T13:00:00+00:00'), 'none');

        // Paused 2 hours => deadline extended to 16:00.
        $this->assertNull($resumed['pausedAt']);
        $this->assertSame(7200000, $resumed['totalPausedMs']);
        $this->assertSame('2026-05-20T16:00:00+00:00', $resumed['targets'][0]['dueAt']);
    }//end testResumeExtendsDeadline()

    /**
     * Escalations fire in order, once per level, idempotently (REQ-004).
     *
     * @return void
     */
    public function testEscalationOrderAndIdempotency(): void
    {
        $policy = $this->policy(
            [
                'escalationChain' => [
                    ['triggerAt' => 0.8, 'notify' => 'assignee', 'channel' => 'nextcloud-notification'],
                    ['triggerAt' => 1.0, 'notify' => 'team-lead', 'channel' => 'email'],
                ],
            ]
        );

        $this->dispatcher->method('dispatch')->willReturn(['actor']);

        $status = $this->engine->buildInitialStatus($policy, 'policy-1', new DateTimeImmutable('2026-05-20T10:00:00+00:00'));

        // At 80% only level 1 fires.
        $status = $this->engine->evaluateTargets($status, new DateTimeImmutable('2026-05-20T13:20:00+00:00'));
        $status = $this->engine->executeEscalations($policy, 'policy-1', 'request', 'obj-1', [], $status, new DateTimeImmutable('2026-05-20T13:20:00+00:00'));
        $this->assertSame(1, $status['currentEscalationLevel']);

        // Re-evaluating at the same point does not re-fire level 1 (idempotent).
        $status = $this->engine->executeEscalations($policy, 'policy-1', 'request', 'obj-1', [], $status, new DateTimeImmutable('2026-05-20T13:25:00+00:00'));
        $this->assertSame(1, $status['currentEscalationLevel']);

        // Past 100% level 2 fires.
        $status = $this->engine->evaluateTargets($status, new DateTimeImmutable('2026-05-20T14:30:00+00:00'));
        $status = $this->engine->executeEscalations($policy, 'policy-1', 'request', 'obj-1', [], $status, new DateTimeImmutable('2026-05-20T14:30:00+00:00'));
        $this->assertSame(2, $status['currentEscalationLevel']);
    }//end testEscalationOrderAndIdempotency()

    /**
     * No escalation fires while the timer is paused (REQ-003).
     *
     * @return void
     */
    public function testNoEscalationWhilePaused(): void
    {
        $policy = $this->policy(
            ['escalationChain' => [['triggerAt' => 1.0, 'notify' => 'team-lead', 'channel' => 'email']]]
        );

        $this->dispatcher->expects($this->never())->method('dispatch');

        $status = $this->engine->buildInitialStatus($policy, 'policy-1', new DateTimeImmutable('2026-05-20T10:00:00+00:00'));
        $status = $this->engine->pauseTimer($status, new DateTimeImmutable('2026-05-20T11:00:00+00:00'));
        $status = $this->engine->executeEscalations($policy, 'policy-1', 'request', 'obj-1', [], $status, new DateTimeImmutable('2026-05-20T20:00:00+00:00'));

        $this->assertSame(0, $status['currentEscalationLevel']);
    }//end testNoEscalationWhilePaused()

    /**
     * isPauseStatus recognises a policy's pause conditions (REQ-003).
     *
     * @return void
     */
    public function testIsPauseStatus(): void
    {
        $policy = $this->policy(['pauseConditions' => ['awaiting-customer', 'on-hold']]);
        $this->assertTrue($this->engine->isPauseStatus($policy, 'on-hold'));
        $this->assertFalse($this->engine->isPauseStatus($policy, 'in_progress'));
    }//end testIsPauseStatus()
}//end class
