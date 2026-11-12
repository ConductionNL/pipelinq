<?php

/**
 * Pipelinq SlaEngineService.
 *
 * Cross-cutting Service Level Agreement engine for tracked object types
 * (request, klacht/complaint, callback). Resolves a unique policy per
 * object, computes holiday-aware deadlines, pauses and resumes on
 * status change, and executes escalation chains when thresholds are
 * crossed.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Sla\SlaBreachEvent;
use OCA\Pipelinq\Service\Sla\SlaEscalation;
use OCA\Pipelinq\Service\Sla\SlaHeat;
use OCA\Pipelinq\Service\Sla\SlaInstant;
use OCA\Pipelinq\Service\Sla\SlaPause;
use OCA\Pipelinq\Service\Sla\SlaPolicyQuery;
use OCA\Pipelinq\Service\Sla\SlaRecipient;
use OCA\Pipelinq\Service\Sla\SlaTrackedObject;
use OCA\Pipelinq\Util\EntityAccessorTrait;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Core SLA engine: policy resolution, deadline math, escalation dispatch.
 *
 * The class deliberately avoids direct ObjectService coupling by using
 * `ContainerInterface` to resolve OpenRegister's ObjectService lazily —
 * mirroring the existing pipelinq pattern (DealCreatedListener etc.).
 *
 * @spec openspec/changes/sla-engine-and-escalation/tasks.md#3.1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.UnusedPrivateField)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     SLA lifecycle surface is the intended API (resolve/compute/evaluate/escalate/pause/resume/mark)
 * @SuppressWarnings(PHPMD.TooManyMethods)           Outbound sms/whatsapp escalation legs add small single-purpose private helpers
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Policy + deadline + escalation logic, split into helpers, still one cohesive engine
 */
class SlaEngineService {
	use EntityAccessorTrait;

	public const STATUS_ON_TRACK = 'on-track';
	public const STATUS_AT_RISK = 'at-risk';
	public const STATUS_BREACHED = 'breached';
	public const STATUS_MET = 'met';

	/**
	 * Constructor.
	 *
	 * @param HolidayCalendarService $holidays Holiday calendar service.
	 * @param BusinessHoursCalculator $businessHours Business-hours math service.
	 * @param ContainerInterface $container DI container (OR ObjectService).
	 * @param IAppConfig $appConfig App config.
	 * @param INotificationManager $notifications Notification manager.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private HolidayCalendarService $holidays,
		private BusinessHoursCalculator $businessHours,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Expose the configured holiday-calendar service.
	 *
	 * Used by the repair step + admin diagnostics — also keeps the
	 * injected service from being flagged as a dead field by static
	 * analysis (it is referenced indirectly through BusinessHoursCalculator,
	 * which is its primary consumer).
	 *
	 * @return HolidayCalendarService Holiday calendar.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function getHolidayService(): HolidayCalendarService {
		return $this->holidays;
	}//end getHolidayService()

	/**
	 * Resolve the most-specific active policy for an object.
	 *
	 * Matching: appliesTo IN [$objectType, '*']; customerTier IN
	 * [$metadata.tier, '*']; customerScope (contract first, then
	 * organisation) when present. Tie-breaks: lower priority, then
	 * newer validFrom.
	 *
	 * Returns null when nothing matches. Errors are logged and yield
	 * null (fail-safe per REQ-007).
	 *
	 * @param string $objectType Tracked object type.
	 * @param string $objectId Object UUID (currently unused; reserved).
	 * @param array<string, mixed> $metadata Resolution metadata (`tier`, `organisationId`, `contractId`).
	 *
	 * @return ?array<string, mixed> The matched policy array, or null.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function resolvePolicyForObject(string $objectType, string $objectId, array $metadata): ?array {
		unset($objectId);
		try {
			$policies = $this->loadActivePolicies();
		} catch (Throwable $e) {
			$this->logger->error(
				'SlaEngineService: failed to load active policies',
				['error' => $e->getMessage()]
			);
			return null;
		}

		$query = new SlaPolicyQuery(objectType: $objectType, metadata: $metadata, now: $this->now());
		$candidates = $this->candidatePolicies(policies: $policies, query: $query);
		if ($candidates === []) {
			return null;
		}

		usort($candidates, $query->compare(...));

		$chosen = $candidates[0];
		$this->logger->debug(
			'SlaEngineService: policy resolved',
			[
				'policyId' => $chosen['id'] ?? $chosen['@self']['slug'] ?? null,
				'objectType' => $objectType,
				'tier' => $query->tier,
				'candidates' => count($candidates),
			]
		);
		return $chosen;
	}//end resolvePolicyForObject()

	/**
	 * Compute initial deadlines for every target on a policy.
	 *
	 * @param array<string, mixed> $policy Policy array.
	 * @param DateTimeInterface $startTime Start instant.
	 * @param ?array<string, mixed> $pausedState Optional pause state (for re-compute).
	 *
	 * @return array<int, array<string, mixed>> Targets array with `dueAt`,
	 *                                          `status`, `consumedPercentage` initialised.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface() is a value-object factory, not a collaborator to inject
	 */
	public function computeDeadlines(
		array $policy,
		DateTimeInterface $startTime,
		?array $pausedState = null,
	): array {
		$calendar = (string)($policy['holidayCalendar'] ?? 'nl-feestdagen-rijksoverheid');
		$results = [];

		foreach (($policy['targets'] ?? []) as $target) {
			$duration = $this->parseDuration(duration: (string)($target['duration'] ?? 'PT0S'));
			$calendarType = (string)($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_BUSINESS);

			$effectiveStart = DateTimeImmutable::createFromInterface($startTime);
			if ($pausedState !== null && isset($pausedState['totalPausedMs']) === true) {
				$pausedMin = (int)floor(((int)$pausedState['totalPausedMs']) / 60000);
				if ($pausedMin > 0) {
					$effectiveStart = $effectiveStart->modify('-' . $pausedMin . ' minutes');
				}
			}

			$dueAt = $this->businessHours->addDuration(
				calendarType: $calendarType,
				startTime: $effectiveStart,
				duration: $duration,
				holidayCalendar: $calendar,
			);

			$results[] = [
				'kind' => (string)($target['kind'] ?? 'resolution'),
				'duration' => (string)($target['duration'] ?? ''),
				'calendar' => $calendarType,
				'dueAt' => $dueAt->format(DateTimeInterface::ATOM),
				'startedAt' => $effectiveStart->format(DateTimeInterface::ATOM),
				'status' => self::STATUS_ON_TRACK,
				'consumedPercentage' => 0.0,
				'breachEventIds' => [],
			];
		}//end foreach

		return $results;
	}//end computeDeadlines()

	/**
	 * Re-evaluate target statuses given a current instant and the policy.
	 *
	 * Updates `consumedPercentage` (using business minutes if the
	 * target's calendar is non-24x7) and flips the status field across
	 * on-track → at-risk (>=80%) → breached (>=100%). Targets already
	 * `met` are left untouched.
	 *
	 * @param array<int, array<string, mixed>> $currentTargets Targets snapshot.
	 * @param array<string, mixed> $policy Resolved policy.
	 * @param DateTimeInterface $now Evaluation instant.
	 *
	 * @return array<int, array<string, mixed>> Updated targets array.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function evaluateTargets(array $currentTargets, array $policy, DateTimeInterface $now): array {
		return $this->evaluateTargetsAt(targets: $currentTargets, at: $this->slaInstant(policy: $policy, now: $now));
	}//end evaluateTargets()

	/**
	 * Re-evaluate every target at one instant; met and unparseable targets
	 * stay as they are.
	 *
	 * @param array<int|string, array<string, mixed>> $targets Targets snapshot.
	 * @param SlaInstant                              $at      The evaluation instant and calendar.
	 *
	 * @return array<int|string, array<string, mixed>> Updated targets, keys kept.
	 */
	private function evaluateTargetsAt(array $targets, SlaInstant $at): array {
		foreach ($targets as $idx => $target) {
			$targets[$idx] = $this->evaluatedTarget(target: $target, at: $at);
		}

		return $targets;
	}//end evaluateTargetsAt()

	/**
	 * One target with its consumed share and status brought up to date.
	 *
	 * @param array<string, mixed> $target The target.
	 * @param SlaInstant           $at     The evaluation instant and calendar.
	 *
	 * @return array<string, mixed>
	 */
	private function evaluatedTarget(array $target, SlaInstant $at): array {
		if (($target['status'] ?? '') === self::STATUS_MET) {
			return $target;
		}

		$consumed = $this->consumedShare(target: $target, at: $at);
		if ($consumed === null) {
			return $target;
		}

		$target['consumedPercentage'] = round($consumed, 4);
		$target['status'] = $this->statusForShare(consumed: $consumed);

		return $target;
	}//end evaluatedTarget()

	/**
	 * The share of a target's window used up at an instant, in the target's
	 * calendar mode; null when the window does not parse.
	 *
	 * @param array<string, mixed> $target The target.
	 * @param SlaInstant           $at     The evaluation instant and calendar.
	 *
	 * @return float|null
	 */
	private function consumedShare(array $target, SlaInstant $at): ?float {
		$started = $this->parseIsoOrNull(value: ($target['startedAt'] ?? null));
		$dueAt = $this->parseIsoOrNull(value: ($target['dueAt'] ?? null));
		if ($started === null || $dueAt === null) {
			return null;
		}

		$calendarType = (string)($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_BUSINESS);
		$totalMin = $this->businessHours->elapsedBusinessMinutes($started, $dueAt, $at->holidayCalendar, $calendarType);
		$elapsedMin = $this->businessHours->elapsedBusinessMinutes($started, $at->now, $at->holidayCalendar, $calendarType);
		if ($totalMin > 0) {
			return ($elapsedMin / $totalMin);
		}

		return 0.0;
	}//end consumedShare()

	/**
	 * The status a consumed share puts a target in: breached from 100%,
	 * at risk from 80%, otherwise on track.
	 *
	 * @param float $consumed The consumed share.
	 *
	 * @return string
	 */
	private function statusForShare(float $consumed): string {
		if ($consumed >= 1.0) {
			return self::STATUS_BREACHED;
		}

		if ($consumed >= 0.8) {
			return self::STATUS_AT_RISK;
		}

		return self::STATUS_ON_TRACK;
	}//end statusForShare()

	/**
	 * The instant and holiday calendar an evaluation under a policy runs at.
	 *
	 * @param array<string, mixed> $policy The policy.
	 * @param DateTimeInterface    $now    The instant.
	 *
	 * @return SlaInstant
	 */
	private function slaInstant(array $policy, DateTimeInterface $now): SlaInstant {
		return new SlaInstant(
			holidayCalendar: (string)($policy['holidayCalendar'] ?? 'nl-feestdagen-rijksoverheid'),
			now: $now
		);
	}//end slaInstant()

	/**
	 * Execute escalation steps whose thresholds were just crossed.
	 *
	 * Idempotent per `(objectId, escalationLevel)`. Creates an
	 * `slaBreachEvent` audit record and sends a notification on the
	 * configured channel (email / nextcloud-notification / webhook).
	 *
	 * @param array<string, mixed> $policy Policy.
	 * @param string $objectType Tracked object type.
	 * @param string $objectId Tracked object UUID.
	 * @param array<int, array<string, mixed>> $newTargets Targets after re-evaluation.
	 * @param array<int, array<string, mixed>> $priorTargets Targets before evaluation.
	 * @param int $alreadyFired The current escalation level.
	 *
	 * @return array{level:int, eventIds:array<int,string>} The new escalation level + event UUIDs.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $priorTargets is part of the public signature (positional callers exist outside this class)
	 */
	public function executeEscalations(
		array $policy,
		string $objectType,
		string $objectId,
		array $newTargets,
		array $priorTargets,
		int $alreadyFired,
	): array {
		unset($priorTargets);
		$chain = $policy['escalationChain'] ?? [];
		if ($chain === []) {
			return ['level' => $alreadyFired, 'eventIds' => []];
		}

		$heat = $this->hottestTarget(targets: $newTargets);
		$object = new SlaTrackedObject(type: $objectType, id: $objectId);
		$newLevel = $alreadyFired;
		$eventIds = [];

		foreach ($chain as $idx => $step) {
			$level = ($idx + 1);
			if ($level <= $alreadyFired) {
				continue;
			}

			$triggerAt = (float)($step['triggerAt'] ?? 1.0);
			if ($heat->consumed + 1e-9 < $triggerAt) {
				continue;
			}

			$escalation = new SlaEscalation(policy: $policy, object: $object, step: $step, level: $level);
			$eventId = $this->fireEscalation(escalation: $escalation, heat: $heat);
			if ($eventId !== null) {
				$eventIds[] = $eventId;
			}

			$newLevel = $level;
		}//end foreach

		return ['level' => $newLevel, 'eventIds' => $eventIds];
	}//end executeEscalations()

	/**
	 * The highest consumedPercentage across all targets, and the kind of the
	 * target that has it: escalation thresholds are tested against any target.
	 *
	 * @param array<int, array<string, mixed>> $targets Evaluated targets.
	 *
	 * @return SlaHeat The heat.
	 */
	private function hottestTarget(array $targets): SlaHeat {
		$maxConsumed = 0.0;
		$hottestKind = '';
		foreach ($targets as $target) {
			$consumed = (float)($target['consumedPercentage'] ?? 0.0);
			if ($consumed > $maxConsumed) {
				$maxConsumed = $consumed;
				$hottestKind = (string)($target['kind'] ?? '');
			}
		}

		return new SlaHeat(consumed: $maxConsumed, hottestKind: $hottestKind);
	}//end hottestTarget()

	/**
	 * Mark the timer as paused.
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param DateTimeInterface $now Pause instant.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function pauseTimer(array $slaStatus, DateTimeInterface $now): array {
		// `isset()` is already false for null — the `!== null` conjunct this
		// replaces was unreachable.
		if (isset($slaStatus['pausedAt']) === true) {
			return $slaStatus;
		}

		$slaStatus['pausedAt'] = $now->format(DateTimeInterface::ATOM);
		$slaStatus['lastEvaluatedAt'] = $slaStatus['pausedAt'];
		return $slaStatus;
	}//end pauseTimer()

	/**
	 * Resume the timer and shift target dueAt values by the paused
	 * duration.
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param array<string, mixed> $policy Policy (for calendar config).
	 * @param DateTimeInterface $now Resume instant.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function resumeTimer(array $slaStatus, array $policy, DateTimeInterface $now): array {
		return $this->resumeTimerAt(slaStatus: $slaStatus, at: $this->slaInstant(policy: $policy, now: $now));
	}//end resumeTimer()

	/**
	 * Resume a paused timer at an instant.
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param SlaInstant           $at        The resume instant and calendar.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 */
	private function resumeTimerAt(array $slaStatus, SlaInstant $at): array {
		$pausedAtRaw = $slaStatus['pausedAt'] ?? null;
		if ($pausedAtRaw === null || $pausedAtRaw === '') {
			return $slaStatus;
		}

		$pausedAt = $this->parseIsoOrNull(value: $pausedAtRaw);
		if ($pausedAt === null) {
			$slaStatus['pausedAt'] = null;
			return $slaStatus;
		}

		$pause = new SlaPause(pausedAt: $pausedAt, resumedAt: $at->now, holidayCalendar: $at->holidayCalendar);
		$slaStatus['targets'] = $this->extendedTargets(targets: ($slaStatus['targets'] ?? []), pause: $pause);

		return $this->closePause(slaStatus: $slaStatus, pause: $pause);
	}//end resumeTimerAt()

	/**
	 * The targets with every dueAt moved out by the paused time.
	 *
	 * @param mixed    $targets The slaStatus targets.
	 * @param SlaPause $pause   The pause being closed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extendedTargets(mixed $targets, SlaPause $pause): array {
		$newTargets = [];
		foreach ($targets as $target) {
			$newTargets[] = $this->extendedTarget(target: $target, pause: $pause);
		}

		return $newTargets;
	}//end extendedTargets()

	/**
	 * One target with its dueAt extended by the paused minutes inside its
	 * calendar mode.
	 *
	 * @param array<string, mixed> $target The target.
	 * @param SlaPause             $pause  The pause being closed.
	 *
	 * @return array<string, mixed>
	 */
	private function extendedTarget(array $target, SlaPause $pause): array {
		$calendarType = (string)($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_BUSINESS);
		$pausedMin = $this->pausedMinutes(calendarType: $calendarType, pause: $pause);
		$due = $this->parseIsoOrNull(value: ($target['dueAt'] ?? null));
		if ($pausedMin <= 0 || $due === null) {
			return $target;
		}

		// Extend dueAt by the paused business-minutes inside its calendar mode.
		$target['dueAt'] = $this->businessHours->addDuration(
			calendarType: $calendarType,
			startTime: $due,
			duration: new DateInterval('PT' . $pausedMin . 'M'),
			holidayCalendar: $pause->holidayCalendar,
		)->format(DateTimeInterface::ATOM);

		return $target;
	}//end extendedTarget()

	/**
	 * The minutes a pause charges in a calendar mode.
	 *
	 * For 24x7 we charge wall-clock minutes; for business modes, we only
	 * charge business minutes (Friday 16:00 → Monday 10:00 is 2 business
	 * hours, not 66).
	 *
	 * @param string   $calendarType The target's calendar mode.
	 * @param SlaPause $pause        The pause being closed.
	 *
	 * @return int
	 */
	private function pausedMinutes(string $calendarType, SlaPause $pause): int {
		return $this->businessHours->elapsedBusinessMinutes(
			$pause->pausedAt,
			$pause->resumedAt,
			$pause->holidayCalendar,
			$calendarType,
		);
	}//end pausedMinutes()

	/**
	 * Book the paused time and clear the pause.
	 *
	 * @param array<string, mixed> $slaStatus The slaStatus with extended targets.
	 * @param SlaPause             $pause     The pause being closed.
	 *
	 * @return array<string, mixed>
	 */
	private function closePause(array $slaStatus, SlaPause $pause): array {
		$pausedMs = $this->wallClockMs(a: $pause->pausedAt, b: $pause->resumedAt);
		$slaStatus['totalPausedMs'] = ((int)($slaStatus['totalPausedMs'] ?? 0)) + $pausedMs;
		$slaStatus['pausedAt'] = null;
		$slaStatus['lastEvaluatedAt'] = $pause->resumedAt->format(DateTimeInterface::ATOM);

		return $slaStatus;
	}//end closePause()

	/**
	 * Initialise the slaStatus envelope when a policy resolves on object
	 * creation.
	 *
	 * @param array<string, mixed> $policy Policy.
	 * @param DateTimeInterface $startTime Start instant.
	 *
	 * @return array<string, mixed> The initial slaStatus.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function initialiseStatus(array $policy, DateTimeInterface $startTime): array {
		return [
			'policyId' => $this->policyIdentity(policy: $policy),
			'startedAt' => $startTime->format(DateTimeInterface::ATOM),
			'pausedAt' => null,
			'totalPausedMs' => 0,
			'targets' => $this->computeDeadlines(policy: $policy, startTime: $startTime),
			'currentEscalationLevel' => 0,
			'lastEvaluatedAt' => $startTime->format(DateTimeInterface::ATOM),
		];
	}//end initialiseStatus()

	/**
	 * Mark an SLA target as met (e.g. on resolution).
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param string $kind Target kind to mark.
	 * @param DateTimeInterface $when Met instant.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function markTargetMet(array $slaStatus, string $kind, DateTimeInterface $when): array {
		$targets = $slaStatus['targets'] ?? [];
		foreach ($targets as $idx => $target) {
			if (($target['kind'] ?? '') !== $kind) {
				continue;
			}

			if (($target['status'] ?? '') === self::STATUS_BREACHED) {
				continue;
			}

			$targets[$idx]['status'] = self::STATUS_MET;
			$targets[$idx]['withAt'] = $when->format(DateTimeInterface::ATOM);
		}

		$slaStatus['targets'] = $targets;
		$slaStatus['lastEvaluatedAt'] = $when->format(DateTimeInterface::ATOM);
		return $slaStatus;
	}//end markTargetMet()

	/**
	 * Load all active, published policies from the sla register.
	 *
	 * Falls back to an empty list if the sla register is not configured
	 * (e.g. fresh install before the repair step has run).
	 *
	 * @return array<int, array<string, mixed>> Active policies.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function loadActivePolicies(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'sla_policy_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$objectService = $this->policyObjectService();
		if ($objectService === null) {
			return [];
		}

		// Register/schema belong inside `filters`: prepareFindAllConfig()
		// reads them from there and nowhere else, so a top-level pair
		// resolves NO context and findAll() answers []. Found by grepping
		// for the shape after fixing the same mistake twice in
		// SlaAttainmentService — this third site loads the POLICIES, so an
		// empty answer means every ticket is evaluated against no policy at
		// all, silently.
		$rows = $this->findPolicyRows(objectService: $objectService, filters: ['register' => $register, 'schema' => $schema]);

		return $this->activePolicies(rows: (array)$rows);
	}//end loadActivePolicies()

	/**
	 * OpenRegister's ObjectService for the policy read; null when unavailable.
	 *
	 * @return object|null
	 */
	private function policyObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: ObjectService not available',
				['error' => $e->getMessage()]
			);
			return null;
		}
	}//end policyObjectService()

	/**
	 * Read up to 500 policy rows; none when the read fails.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $filters       The register and schema filters.
	 *
	 * @return mixed The rows as findAll() answers them.
	 */
	private function findPolicyRows(object $objectService, array $filters): mixed {
		try {
			return $objectService->findAll(config: ['filters' => $filters, 'limit' => 500]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: ObjectService::findAll failed',
				['error' => $e->getMessage()]
			);
			return [];
		}
	}//end findPolicyRows()

	/**
	 * The policy rows that are neither archived nor switched off, normalised.
	 *
	 * @param array<int|string, mixed> $rows The stored policy rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function activePolicies(array $rows): array {
		$policies = [];
		foreach ($rows as $row) {
			$array = $this->normaliseObject(row: $row);
			if (($array['status'] ?? 'published') === 'archived') {
				continue;
			}

			if (($array['active'] ?? true) === false) {
				continue;
			}

			$policies[] = $array;
		}

		return $policies;
	}//end activePolicies()

	/**
	 * The policies that apply to the queried object, in register order.
	 *
	 * @param array<int, array<string, mixed>> $policies Active policies.
	 * @param SlaPolicyQuery                   $query    The object.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function candidatePolicies(array $policies, SlaPolicyQuery $query): array {
		$candidates = [];
		foreach ($policies as $policy) {
			if ($query->matches(policy: $policy) === true && $this->isValidAt(policy: $policy, now: $query->now) === true) {
				$candidates[] = $policy;
			}
		}

		return $candidates;
	}//end candidatePolicies()

	/**
	 * Whether `$now` falls within the policy's validFrom/validUntil window.
	 *
	 * @param array<string, mixed> $policy Policy.
	 * @param DateTimeInterface $now Resolution instant.
	 *
	 * @return bool
	 */
	private function isValidAt(array $policy, DateTimeInterface $now): bool {
		$validFrom = $this->parseIsoOrNull(value: ($policy['validFrom'] ?? null));
		$validUntil = $this->parseIsoOrNull(value: ($policy['validUntil'] ?? null));
		if ($validFrom !== null && $now < $validFrom) {
			return false;
		}

		if ($validUntil !== null && $now > $validUntil) {
			return false;
		}

		return true;
	}//end isValidAt()

	/**
	 * Fire one escalation step: notification + audit-record creation.
	 *
	 * @param SlaEscalation $escalation The step that fires.
	 * @param SlaHeat       $heat       The heat it fires at.
	 *
	 * @return ?string Breach event UUID, or null on failure.
	 */
	private function fireEscalation(SlaEscalation $escalation, SlaHeat $heat): ?string {
		try {
			$notifiedActors = $this->dispatchNotification(escalation: $escalation);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: notification dispatch failed',
				['error' => $e->getMessage(), 'channel' => $escalation->channel()]
			);
			$notifiedActors = ['failed:' . $escalation->channel()];
		}

		return $this->writeBreachEvent(event: new SlaBreachEvent(escalation: $escalation, heat: $heat, actors: $notifiedActors));
	}//end fireEscalation()

	/**
	 * Send a notification on the configured escalation channel.
	 *
	 * `nextcloud-notification` notifies the resolved NC user. `sms` and
	 * `whatsapp` dispatch through the channel adapters (transported via
	 * OpenRegister's MessageDispatchProvider leaf) and are supported for
	 * `notify: customer` only — the breached object's linked client/contact
	 * is the recipient. `email` and `webhook` remain delegated to their own
	 * capabilities and record a `deferred:` marker. Every outcome is captured
	 * in the `notifiedActors` marker vocabulary and a Throwable never escapes
	 * (the caller's try/catch is the last resort).
	 *
	 * @param SlaEscalation $escalation The step that fires (channel, notify role, `templateId`).
	 *
	 * @return array<int, string> Notified actor identifiers / markers.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
	 */
	private function dispatchNotification(SlaEscalation $escalation): array {
		$channel = $escalation->channel();
		if ($channel === 'nextcloud-notification') {
			return $this->dispatchNextcloudNotification(escalation: $escalation);
		}

		if ($channel === 'sms' || $channel === 'whatsapp') {
			return $this->dispatchChannelAdapter(escalation: $escalation);
		}

		// Email / webhook are delegated to their own capabilities; record
		// the intent so the audit trail captures it.
		return ['deferred:' . $channel . ':' . $escalation->notify()];
	}//end dispatchNotification()

	/**
	 * Dispatch a Nextcloud in-app notification to the resolved actor.
	 *
	 * @param SlaEscalation $escalation The step that fires.
	 *
	 * @return array<int, string> Notified actor identifiers / markers.
	 */
	private function dispatchNextcloudNotification(SlaEscalation $escalation): array {
		$notify = $escalation->notify();
		$actor = $this->resolveActor(role: $notify, policy: $escalation->policy);
		if ($actor === null) {
			return ['unresolved:' . $notify];
		}

		$level = $escalation->level;
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($actor)
				->setDateTime(new DateTime())
				->setObject($escalation->object->type, $escalation->object->id)
				->setSubject('sla_breach', ['level' => $level, 'policy' => (string)($escalation->policy['name'] ?? '')])
				->setMessage('sla_breach_message', ['level' => $level]);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: nextcloud-notification dispatch failed',
				['error' => $e->getMessage(), 'actor' => $actor]
			);
			return ['failed:nextcloud-notification:' . $actor];
		}

		return [$actor];
	}//end dispatchNextcloudNotification()

	/**
	 * Dispatch an sms/whatsapp escalation to the breached object's customer.
	 *
	 * Only `notify: customer` is supported (the breached object's linked
	 * client/contact is the recipient). Other roles have no phone seam and
	 * record an `unsupported:` marker without dispatching.
	 *
	 * @param SlaEscalation $escalation The step that fires on `sms` or `whatsapp`.
	 *
	 * @return array<int, string> Notified actor identifiers / markers.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
	 */
	private function dispatchChannelAdapter(SlaEscalation $escalation): array {
		$channel = $escalation->channel();
		$notify = $escalation->notify();
		if ($notify !== 'customer') {
			return ['unsupported:' . $channel . ':' . $notify];
		}

		$contact = $this->resolveCustomerContact(objectType: $escalation->object->type, objectId: $escalation->object->id);
		if ($contact === null) {
			return ['unresolved:' . $notify];
		}

		$recipient = new SlaRecipient(
			contact: $contact,
			contactId: (string)($contact['uuid'] ?? ($contact['id'] ?? '')),
			context: [
				'clientId' => (string)($contact['client'] ?? ''),
				'agent' => 'system:sla-engine',
			],
		);

		if ($channel === 'sms') {
			return $this->dispatchSmsEscalation(escalation: $escalation, recipient: $recipient);
		}

		return $this->dispatchWhatsAppEscalation(escalation: $escalation, recipient: $recipient);
	}//end dispatchChannelAdapter()

	/**
	 * Dispatch an SMS escalation through SmsAdapter.
	 *
	 * @param SlaEscalation $escalation The step that fires.
	 * @param SlaRecipient  $recipient  The customer it goes to.
	 *
	 * @return array<int, string> Notified actor identifiers / markers.
	 */
	private function dispatchSmsEscalation(SlaEscalation $escalation, SlaRecipient $recipient): array {
		$adapter = $this->resolveMessagingAdapter(class: 'OCA\\Pipelinq\\Service\\SmsAdapter');
		if ($adapter === null) {
			return ['failed:sms'];
		}

		try {
			$outcome = $adapter->send(
				contact: $recipient->contact,
				body: $this->renderBreachMessage(policy: $escalation->policy, level: $escalation->level),
				providerHint: null,
				context: $recipient->context
			);
		} catch (Throwable $e) {
			$this->logger->warning('SlaEngineService: SMS escalation dispatch threw', ['error' => $e->getMessage()]);
			return ['failed:sms'];
		}

		return $recipient->marker(channel: 'sms', outcome: $outcome);
	}//end dispatchSmsEscalation()

	/**
	 * Dispatch a WhatsApp escalation through WhatsAppAdapter.
	 *
	 * A whatsapp escalation is business-initiated: a `templateId` on the step
	 * is required unless the contact has an open 24h session window. Missing
	 * both fails closed with a `template-missing:whatsapp` marker.
	 *
	 * @param SlaEscalation $escalation The step that fires (carries `templateId`).
	 * @param SlaRecipient  $recipient  The customer it goes to.
	 *
	 * @return array<int, string> Notified actor identifiers / markers.
	 */
	private function dispatchWhatsAppEscalation(SlaEscalation $escalation, SlaRecipient $recipient): array {
		$adapter = $this->resolveMessagingAdapter(class: 'OCA\\Pipelinq\\Service\\WhatsAppAdapter');
		if ($adapter === null) {
			return ['failed:whatsapp'];
		}

		$templateId = $escalation->templateId();
		$sendTemplateId = $templateId;
		$body = '';
		if ($templateId === '') {
			if ($this->inSessionWindow(adapter: $adapter, contactId: $recipient->contactId) === false) {
				return ['template-missing:whatsapp'];
			}

			$body = $this->renderBreachMessage(policy: $escalation->policy, level: $escalation->level);
			$sendTemplateId = null;
		}

		try {
			$outcome = $adapter->send(
				contact: $recipient->contact,
				body: $body,
				templateId: $sendTemplateId,
				parameters: [],
				context: $recipient->context
			);
		} catch (Throwable $e) {
			$this->logger->warning('SlaEngineService: WhatsApp escalation dispatch threw', ['error' => $e->getMessage()]);
			return ['failed:whatsapp'];
		}

		return $recipient->marker(channel: 'whatsapp', outcome: $outcome);
	}//end dispatchWhatsAppEscalation()

	/**
	 * Whether the contact has an open WhatsApp session window.
	 *
	 * Narrows with instanceof rather than method_exists: resolveMessagingAdapter()
	 * returns a bare ?object, so method_exists leaves phpstan with stdClass and
	 * the named argument unchecked. Any other adapter has no window.
	 *
	 * @param object $adapter   The resolved WhatsApp adapter.
	 * @param string $contactId Recipient UUID.
	 *
	 * @return bool True inside the window.
	 */
	private function inSessionWindow(object $adapter, string $contactId): bool {
		if ($adapter instanceof WhatsAppAdapter) {
			return (bool)$adapter->isWithinSessionWindow(contactId: $contactId);
		}

		return false;
	}//end inSessionWindow()

	/**
	 * Resolve a messaging adapter (SmsAdapter / WhatsAppAdapter) lazily.
	 *
	 * @param string $class Adapter FQCN.
	 *
	 * @return object|null The adapter, or null when unavailable.
	 */
	private function resolveMessagingAdapter(string $class): ?object {
		try {
			$adapter = $this->container->get($class);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: messaging adapter unavailable',
				['class' => $class, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($adapter) === false || method_exists($adapter, 'send') === false) {
			return null;
		}

		return $adapter;
	}//end resolveMessagingAdapter()

	/**
	 * Resolve the breached object's customer contact (prefer contact, then client).
	 *
	 * @param string $objectType Tracked object type (schema slug).
	 * @param string $objectId Tracked object UUID.
	 *
	 * @return array<string, mixed>|null The recipient row, or null.
	 */
	private function resolveCustomerContact(string $objectType, string $objectId): ?array {
		$targetSchema = $this->configuredSchema(key: 'sla_target_schema_' . $objectType, default: $objectType);
		$target = $this->loadPipelinqObject(schema: $targetSchema, id: $objectId);
		if ($target === null) {
			return null;
		}

		$contact = $this->linkedObject(target: $target, link: 'contact');
		if ($contact !== null) {
			return $contact;
		}

		return $this->linkedObject(target: $target, link: 'client');
	}//end resolveCustomerContact()

	/**
	 * The object a tracked object links to under `contact` or `client`, read
	 * from the schema configured as `<link>_schema` (default: the link name).
	 *
	 * @param array<string, mixed> $target The tracked object.
	 * @param string               $link   `contact` or `client`.
	 *
	 * @return array<string, mixed>|null The linked object, or null when unset or not found.
	 */
	private function linkedObject(array $target, string $link): ?array {
		$linkedId = (string)($target[$link] ?? '');
		if ($linkedId === '') {
			return null;
		}

		return $this->loadPipelinqObject(
			schema: $this->configuredSchema(key: $link . '_schema', default: $link),
			id: $linkedId
		);
	}//end linkedObject()

	/**
	 * A schema slug from app config, or the default when it is not set.
	 *
	 * @param string $key     The app-config key.
	 * @param string $default The slug to fall back on.
	 *
	 * @return string
	 */
	private function configuredSchema(string $key, string $default): string {
		$schema = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($schema === '') {
			return $default;
		}

		return $schema;
	}//end configuredSchema()

	/**
	 * Load a pipelinq-register object by schema slug + id (best effort).
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Object UUID.
	 *
	 * @return array<string, mixed>|null The normalised object, or null.
	 */
	private function loadPipelinqObject(string $schema, string $id): ?array {
		if ($schema === '' || $id === '') {
			return null;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		try {
			$entity = $objectService->find(id: $id, options: new ObjectFindOptions(register: $register, schema: $schema));
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: target object load failed',
				['schema' => $schema, 'id' => $id, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->normaliseObject(row: $entity);
	}//end loadPipelinqObject()

	/**
	 * Render the customer-facing breach message for an sms/whatsapp escalation.
	 *
	 * @param array<string, mixed> $policy Policy.
	 * @param int $level Escalation level.
	 *
	 * @return string The rendered message body.
	 */
	private function renderBreachMessage(array $policy, int $level): string {
		$name = (string)($policy['name'] ?? 'SLA');
		return sprintf(
			'Uw serviceverzoek nadert de afgesproken doorlooptijd (%s, escalatieniveau %d). Ons team pakt dit met prioriteit op.',
			$name,
			$level
		);
	}//end renderBreachMessage()

	/**
	 * Resolve a notify role to a concrete Nextcloud user identifier.
	 *
	 * Configurable via per-tenant app-config keys
	 * (`sla_actor_team-lead`, `sla_actor_manager`, etc.). Falls back to
	 * the configured fallback admin.
	 *
	 * @param string $role Actor role.
	 * @param array<string, mixed> $policy Policy (reserved for future per-policy overrides).
	 *
	 * @return ?string The resolved user ID, or null.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	private function resolveActor(string $role, array $policy): ?string {
		unset($policy);
		$key = 'sla_actor_' . preg_replace('/[^a-z0-9_-]/i', '_', $role);
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($value !== '') {
			return $value;
		}

		$fallback = $this->appConfig->getValueString(Application::APP_ID, 'sla_actor_fallback', '');
		if ($fallback === '') {
			return null;
		}

		return $fallback;
	}//end resolveActor()

	/**
	 * Persist an `slaBreachEvent` audit row.
	 *
	 * @param SlaBreachEvent $event The fired escalation and who was notified.
	 *
	 * @return ?string Breach event UUID, or null on failure.
	 */
	private function writeBreachEvent(SlaBreachEvent $event): ?string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'sla_breach_event_schema', '');
		if ($register === '' || $schema === '') {
			$this->logger->debug('SlaEngineService: breach register/schema not configured; skipping audit row');
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: ObjectService unavailable for breach event write',
				['error' => $e->getMessage()]
			);
			return null;
		}

		$payload = $this->breachPayload(event: $event);

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
			);
			return $this->savedUuid(saved: $saved);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: failed to write breach event',
				['error' => $e->getMessage()]
			);
			return null;
		}
	}//end writeBreachEvent()

	/**
	 * The `slaBreachEvent` row for a fired escalation.
	 *
	 * @param SlaBreachEvent $event The fired escalation and who was notified.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function breachPayload(SlaBreachEvent $event): array {
		$escalation = $event->escalation;
		return [
			'policyId' => $this->policyIdentity(policy: $escalation->policy),
			'targetObjectType' => $escalation->object->type,
			'targetObjectId' => $escalation->object->id,
			'targetKind' => $event->heat->kind(),
			'breachedAt' => $this->now()->format(DateTimeInterface::ATOM),
			'consumedPercentage' => round($event->heat->consumed, 4),
			'escalationLevel' => $escalation->level,
			'notifiedActors' => $event->actors,
			'channel' => $escalation->channel(),
			'acknowledged' => false,
		];
	}//end breachPayload()

	/**
	 * The UUID of a saved breach event, or null when the save returned none.
	 *
	 * @param mixed $saved What saveObject() returned.
	 *
	 * @return ?string Breach event UUID, or null.
	 */
	private function savedUuid(mixed $saved): ?string {
		$uuid = '';
		if (is_array($saved) === true) {
			$uuid = (string)($saved['uuid'] ?? $saved['id'] ?? '');
		} elseif (is_object($saved) === true) {
			// SaveObject() returns an ObjectEntity whose getUuid() is served by
			// Entity::__call — method_exists() is FALSE for it, so the id is read
			// through the entity accessor (pipelinq#807).
			$uuid = $this->readEntityValue(entity: $saved, getter: 'getUuid');
		}

		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end savedUuid()

	/**
	 * Return the identity string for a policy (UUID preferred, else slug).
	 *
	 * @param array<string, mixed> $policy Policy.
	 *
	 * @return string Identity.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function policyIdentity(array $policy): string {
		if (isset($policy['id']) === true && $policy['id'] !== '') {
			return (string)$policy['id'];
		}

		if (isset($policy['uuid']) === true && $policy['uuid'] !== '') {
			return (string)$policy['uuid'];
		}

		return (string)($policy['@self']['slug'] ?? ($policy['slug'] ?? ''));
	}//end policyIdentity()

	/**
	 * Parse an ISO 8601 duration string into a DateInterval.
	 *
	 * @param string $duration ISO 8601 duration (PT4H, P1D, P3W, ...).
	 *
	 * @return DateInterval Parsed interval.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function parseDuration(string $duration): DateInterval {
		if ($duration === '') {
			return new DateInterval('PT0S');
		}

		try {
			return new DateInterval($duration);
		} catch (Exception $e) {
			$this->logger->warning(
				'SlaEngineService: invalid ISO 8601 duration; falling back to PT0S',
				['duration' => $duration, 'error' => $e->getMessage()]
			);
			return new DateInterval('PT0S');
		}
	}//end parseDuration()

	/**
	 * Parse an ISO 8601 timestamp; return null on empty/invalid.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return ?DateTimeImmutable Parsed instant.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function parseIsoOrNull($value): ?DateTimeImmutable {
		if ($value === null || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable((string)$value);
		} catch (Throwable $e) {
			return null;
		}
	}//end parseIsoOrNull()

	/**
	 * Normalise an OpenRegister object/entity to an array.
	 *
	 * @param mixed $row Raw row (entity, array, ObjectEntity).
	 *
	 * @return array<string, mixed> Plain array.
	 */
	private function normaliseObject($row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === false) {
			return [];
		}

		return ($this->entityObject(row: $row) ?? $this->serialisedObject(row: $row) ?? []);
	}//end normaliseObject()

	/**
	 * An entity's object array, with its uuid as `id` when it has none.
	 *
	 * @param object $row The entity.
	 *
	 * @return array<string, mixed>|null Null when it has no object array.
	 */
	private function entityObject(object $row): ?array {
		if (method_exists($row, 'getObject') === false) {
			return null;
		}

		$object = $row->getObject();
		if (is_array($object) === false) {
			return null;
		}

		if (method_exists($row, 'getUuid') === true) {
			$object['id'] = $object['id'] ?? (string)$row->getUuid();
		}

		return $object;
	}//end entityObject()

	/**
	 * An object's jsonSerialize() array.
	 *
	 * @param object $row The object.
	 *
	 * @return array<string, mixed>|null Null when it does not serialise to an array.
	 */
	private function serialisedObject(object $row): ?array {
		if (method_exists($row, 'jsonSerialize') === false) {
			return null;
		}

		$json = $row->jsonSerialize();
		if (is_array($json) === false) {
			return null;
		}

		return $json;
	}//end serialisedObject()

	/**
	 * Wall-clock milliseconds between two instants.
	 *
	 * @param DateTimeInterface $a Earlier instant.
	 * @param DateTimeInterface $b Later instant.
	 *
	 * @return int Milliseconds (>=0).
	 */
	private function wallClockMs(DateTimeInterface $a, DateTimeInterface $b): int {
		$delta = $b->getTimestamp() - $a->getTimestamp();
		if ($delta > 0) {
			return ($delta * 1000);
		}

		return 0;
	}//end wallClockMs()

	/**
	 * Current instant in UTC.
	 *
	 * Wrapped for test override.
	 *
	 * @return DateTimeImmutable Now.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}//end now()
}//end class
