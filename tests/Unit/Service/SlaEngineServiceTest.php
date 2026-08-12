<?php

/**
 * Unit tests for SlaEngineService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-policy-resolution-at-object-creation
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-holiday-aware-deadline-calculation
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Coverage for policy resolution, deadline math, pause/resume and
 * escalation chain logic.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SlaEngineServiceTest extends TestCase {
	private SlaEngineService $engine;

	private BusinessHoursCalculator $businessHours;

	/**
	 * Build the engine with real BusinessHoursCalculator and
	 * HolidayCalendarService (the latter is filesystem-backed and
	 * deterministic).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$notifications = $this->createMock(INotificationManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		$holidays = new HolidayCalendarService($appConfig, $logger);
		$this->businessHours = new BusinessHoursCalculator($holidays, $appConfig, $logger);

		$this->engine = new SlaEngineService(
			$holidays,
			$this->businessHours,
			$container,
			$appConfig,
			$notifications,
			$logger,
		);
	}//end setUp()

	/**
	 * Engine returns a parsed DateInterval for ISO 8601 durations.
	 *
	 * @return void
	 */
	public function testParseDurationIso(): void {
		$interval = $this->engine->parseDuration('PT4H');
		$this->assertEquals(4, $interval->h);
	}//end testParseDurationIso()

	/**
	 * Engine falls back to PT0S on invalid duration strings.
	 *
	 * @return void
	 */
	public function testParseDurationFallback(): void {
		$interval = $this->engine->parseDuration('not-iso');
		$this->assertEquals(0, $interval->h);
		$this->assertEquals(0, $interval->d);
	}//end testParseDurationFallback()

	/**
	 * Initial deadlines are computed and stored on slaStatus.
	 *
	 * @return void
	 */
	public function testInitialiseStatusAddsTargets(): void {
		$policy = [
			'id' => 'p1',
			'name' => 'Test policy',
			'targets' => [
				['kind' => 'acknowledgement', 'duration' => 'PT2H', 'calendar' => '24x7'],
				['kind' => 'resolution',      'duration' => 'P1D',  'calendar' => '24x7'],
			],
			'holidayCalendar' => 'none',
		];

		$status = $this->engine->initialiseStatus($policy, new DateTimeImmutable('2026-05-15T09:00:00Z'));
		$this->assertSame('p1', $status['policyId']);
		$this->assertCount(2, $status['targets']);
		$this->assertSame(SlaEngineService::STATUS_ON_TRACK, $status['targets'][0]['status']);
		$this->assertSame(0, $status['currentEscalationLevel']);
	}//end testInitialiseStatusAddsTargets()

	/**
	 * Pause sets pausedAt and resume clears it.
	 *
	 * @return void
	 */
	public function testPauseResumeRoundtrip(): void {
		$policy = [
			'id' => 'p1',
			'targets' => [
				['kind' => 'resolution', 'duration' => 'PT4H', 'calendar' => '24x7'],
			],
			'holidayCalendar' => 'none',
		];

		$start = new DateTimeImmutable('2026-05-15T09:00:00Z');
		$status = $this->engine->initialiseStatus($policy, $start);

		$paused = $this->engine->pauseTimer($status, new DateTimeImmutable('2026-05-15T10:00:00Z'));
		$this->assertNotNull($paused['pausedAt']);

		$resumed = $this->engine->resumeTimer($paused, $policy, new DateTimeImmutable('2026-05-15T11:00:00Z'));
		$this->assertNull($resumed['pausedAt']);
		// 1 hour pause should have shifted dueAt by +1h.
		$original = new DateTimeImmutable($status['targets'][0]['dueAt']);
		$shifted = new DateTimeImmutable($resumed['targets'][0]['dueAt']);
		$this->assertSame(3600, ($shifted->getTimestamp() - $original->getTimestamp()));
	}//end testPauseResumeRoundtrip()

	/**
	 * evaluateTargets flips status from on-track to at-risk (>=80%) and
	 * breached (>=100%).
	 *
	 * @return void
	 */
	public function testEvaluateTargetsFlipsStatus(): void {
		$policy = [
			'id' => 'p1',
			'targets' => [
				['kind' => 'resolution', 'duration' => 'PT10H', 'calendar' => '24x7'],
			],
			'holidayCalendar' => 'none',
		];

		$start = new DateTimeImmutable('2026-05-15T00:00:00Z');
		$status = $this->engine->initialiseStatus($policy, $start);

		$atRisk = $this->engine->evaluateTargets($status['targets'], $policy, new DateTimeImmutable('2026-05-15T08:30:00Z'));
		$this->assertSame(SlaEngineService::STATUS_AT_RISK, $atRisk[0]['status']);

		$breached = $this->engine->evaluateTargets($status['targets'], $policy, new DateTimeImmutable('2026-05-15T11:00:00Z'));
		$this->assertSame(SlaEngineService::STATUS_BREACHED, $breached[0]['status']);

		$onTrack = $this->engine->evaluateTargets($status['targets'], $policy, new DateTimeImmutable('2026-05-15T01:00:00Z'));
		$this->assertSame(SlaEngineService::STATUS_ON_TRACK, $onTrack[0]['status']);
	}//end testEvaluateTargetsFlipsStatus()

	/**
	 * markTargetMet flips matching kinds to STATUS_MET (unless breached).
	 *
	 * @return void
	 */
	public function testMarkTargetMet(): void {
		$policy = [
			'id' => 'p1',
			'targets' => [
				['kind' => 'resolution', 'duration' => 'PT4H', 'calendar' => '24x7'],
			],
			'holidayCalendar' => 'none',
		];

		$status = $this->engine->initialiseStatus($policy, new DateTimeImmutable('2026-05-15T09:00:00Z'));
		$after = $this->engine->markTargetMet($status, 'resolution', new DateTimeImmutable('2026-05-15T10:00:00Z'));
		$this->assertSame(SlaEngineService::STATUS_MET, $after['targets'][0]['status']);
		$this->assertNotEmpty($after['targets'][0]['metAt']);
	}//end testMarkTargetMet()

	/**
	 * Escalation idempotency: re-running with the same alreadyFired
	 * level returns no new event IDs.
	 *
	 * @return void
	 */
	public function testEscalationIdempotent(): void {
		$policy = [
			'id' => 'p1',
			'targets' => [
				['kind' => 'resolution', 'duration' => 'PT2H', 'calendar' => '24x7'],
			],
			'holidayCalendar' => 'none',
			'escalationChain' => [
				['triggerAt' => 0.8, 'notify' => 'assignee', 'channel' => 'nextcloud-notification'],
			],
		];

		$start = new DateTimeImmutable('2026-05-15T09:00:00Z');
		$status = $this->engine->initialiseStatus($policy, $start);

		// Force consumption past 80% to trigger.
		$status['targets'] = $this->engine->evaluateTargets(
			$status['targets'],
			$policy,
			new DateTimeImmutable('2026-05-15T10:45:00Z')
		);

		$result = $this->engine->executeEscalations(
			$policy,
			'request',
			'object-1',
			$status['targets'],
			$status['targets'],
			0,
		);
		// Level advances from 0 to 1; whether the audit write returns a UUID
		// depends on OR being available (not in unit tests). Level must change.
		$this->assertSame(1, $result['level']);

		// Re-fire with alreadyFired = 1 → no advance.
		$second = $this->engine->executeEscalations(
			$policy,
			'request',
			'object-1',
			$status['targets'],
			$status['targets'],
			1,
		);
		$this->assertSame(1, $second['level']);
		$this->assertSame([], $second['eventIds']);
	}//end testEscalationIdempotent()

	/**
	 * No escalation fires when consumption is below the first trigger.
	 *
	 * @return void
	 */
	public function testNoEscalationBelowTrigger(): void {
		$policy = [
			'id' => 'p1',
			'targets' => [
				['kind' => 'resolution', 'duration' => 'PT4H', 'calendar' => '24x7'],
			],
			'holidayCalendar' => 'none',
			'escalationChain' => [
				['triggerAt' => 0.8, 'notify' => 'assignee', 'channel' => 'nextcloud-notification'],
			],
		];

		$start = new DateTimeImmutable('2026-05-15T09:00:00Z');
		$status = $this->engine->initialiseStatus($policy, $start);

		// Only 1 hour elapsed of a 4-hour target = 25% consumption.
		$status['targets'] = $this->engine->evaluateTargets(
			$status['targets'],
			$policy,
			new DateTimeImmutable('2026-05-15T10:00:00Z')
		);

		$result = $this->engine->executeEscalations(
			$policy,
			'request',
			'object-1',
			$status['targets'],
			$status['targets'],
			0,
		);
		$this->assertSame(0, $result['level']);
		$this->assertSame([], $result['eventIds']);
	}//end testNoEscalationBelowTrigger()

	/**
	 * policyIdentity prefers `id`, falls back to slug.
	 *
	 * @return void
	 */
	public function testPolicyIdentityFallback(): void {
		$this->assertSame('uuid-1', $this->engine->policyIdentity(['id' => 'uuid-1']));
		$this->assertSame('slug-1', $this->engine->policyIdentity(['@self' => ['slug' => 'slug-1']]));
		$this->assertSame('', $this->engine->policyIdentity([]));
	}//end testPolicyIdentityFallback()
}//end class
