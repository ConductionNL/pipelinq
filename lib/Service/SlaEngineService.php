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
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-003
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use OCA\Pipelinq\AppInfo\Application;
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
class SlaEngineService
{
    public const STATUS_ON_TRACK = 'on-track';
    public const STATUS_AT_RISK  = 'at-risk';
    public const STATUS_BREACHED = 'breached';
    public const STATUS_MET      = 'met';

    /**
     * Constructor.
     *
     * @param HolidayCalendarService  $holidays      Holiday calendar service.
     * @param BusinessHoursCalculator $businessHours Business-hours math service.
     * @param ContainerInterface      $container     DI container (OR ObjectService).
     * @param IAppConfig              $appConfig     App config.
     * @param INotificationManager    $notifications Notification manager.
     * @param LoggerInterface         $logger        PSR logger.
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
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-010
     */
    public function getHolidayService(): HolidayCalendarService
    {
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
     * @param string               $objectType Tracked object type.
     * @param string               $objectId   Object UUID (currently unused; reserved).
     * @param array<string, mixed> $metadata   Resolution metadata (`tier`, `organisationId`, `contractId`).
     *
     * @return ?array<string, mixed> The matched policy array, or null.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function resolvePolicyForObject(string $objectType, string $objectId, array $metadata): ?array
    {
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

        $tier           = (string) ($metadata['tier'] ?? '');
        $organisationId = (string) ($metadata['organisationId'] ?? '');
        $contractId     = (string) ($metadata['contractId'] ?? '');
        $now            = $this->now();

        $candidates = [];
        foreach ($policies as $policy) {
            $matches = $this->policyApplies(
                policy: $policy,
                objectType: $objectType,
                tier: $tier,
                organisationId: $organisationId,
                contractId: $contractId,
                now: $now
            );
            if ($matches === false) {
                continue;
            }

            $candidates[] = $policy;
        }

        if ($candidates === []) {
            return null;
        }

        // Tie-break: prefer contract-scoped over org-scoped over tier-only over wildcard;
        // then lower `priority`; then newer `validFrom`.
        usort(
            $candidates,
            function (array $a, array $b) use ($contractId, $organisationId): int {
                $scopeA = $this->scopeSpecificity(policy: $a, contractId: $contractId, organisationId: $organisationId);
                $scopeB = $this->scopeSpecificity(policy: $b, contractId: $contractId, organisationId: $organisationId);
                if ($scopeA !== $scopeB) {
                    return $scopeB <=> $scopeA;
                }

                $priorityA = (int) ($a['priority'] ?? 100);
                $priorityB = (int) ($b['priority'] ?? 100);
                if ($priorityA !== $priorityB) {
                    return $priorityA <=> $priorityB;
                }

                $vfA = (string) ($a['validFrom'] ?? '');
                $vfB = (string) ($b['validFrom'] ?? '');
                return strcmp($vfB, $vfA);
            }
        );

        $chosen = $candidates[0];
        $this->logger->debug(
            'SlaEngineService: policy resolved',
            [
                'policyId'   => $chosen['id'] ?? $chosen['@self']['slug'] ?? null,
                'objectType' => $objectType,
                'tier'       => $tier,
                'candidates' => count($candidates),
            ]
        );
        return $chosen;
    }//end resolvePolicyForObject()

    /**
     * Compute initial deadlines for every target on a policy.
     *
     * @param array<string, mixed>  $policy      Policy array.
     * @param DateTimeInterface     $startTime   Start instant.
     * @param ?array<string, mixed> $pausedState Optional pause state (for re-compute).
     *
     * @return array<int, array<string, mixed>> Targets array with `dueAt`,
     *         `status`, `consumedPercentage` initialised.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
     *
     * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface() is a value-object factory, not a collaborator to inject
     */
    public function computeDeadlines(
        array $policy,
        DateTimeInterface $startTime,
        ?array $pausedState=null,
    ): array {
        $calendar = (string) ($policy['holidayCalendar'] ?? 'nl-feestdagen-rijksoverheid');
        $results  = [];

        foreach (($policy['targets'] ?? []) as $target) {
            $duration     = $this->parseDuration(duration: (string) ($target['duration'] ?? 'PT0S'));
            $calendarType = (string) ($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_BUSINESS);

            $effectiveStart = DateTimeImmutable::createFromInterface($startTime);
            if ($pausedState !== null && isset($pausedState['totalPausedMs']) === true) {
                $pausedMin = (int) floor(((int) $pausedState['totalPausedMs']) / 60000);
                if ($pausedMin > 0) {
                    $effectiveStart = $effectiveStart->modify('-'.$pausedMin.' minutes');
                }
            }

            $dueAt = $this->businessHours->addDuration(
                calendarType: $calendarType,
                startTime: $effectiveStart,
                duration: $duration,
                holidayCalendar: $calendar,
            );

            $results[] = [
                'kind'               => (string) ($target['kind'] ?? 'resolution'),
                'duration'           => (string) ($target['duration'] ?? ''),
                'calendar'           => $calendarType,
                'dueAt'              => $dueAt->format(DateTimeInterface::ATOM),
                'startedAt'          => $effectiveStart->format(DateTimeInterface::ATOM),
                'status'             => self::STATUS_ON_TRACK,
                'consumedPercentage' => 0.0,
                'breachEventIds'     => [],
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
     * @param array<string, mixed>             $policy         Resolved policy.
     * @param DateTimeInterface                $now            Evaluation instant.
     *
     * @return array<int, array<string, mixed>> Updated targets array.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
     */
    public function evaluateTargets(array $currentTargets, array $policy, DateTimeInterface $now): array
    {
        $calendar = (string) ($policy['holidayCalendar'] ?? 'nl-feestdagen-rijksoverheid');

        foreach ($currentTargets as $idx => $target) {
            if (($target['status'] ?? '') === self::STATUS_MET) {
                continue;
            }

            $started = $this->parseIsoOrNull(value: ($target['startedAt'] ?? null));
            $dueAt   = $this->parseIsoOrNull(value: ($target['dueAt'] ?? null));
            if ($started === null || $dueAt === null) {
                continue;
            }

            $calendarType = (string) ($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_BUSINESS);

            $totalMin   = $this->businessHours->elapsedBusinessMinutes($started, $dueAt, $calendar, $calendarType);
            $elapsedMin = $this->businessHours->elapsedBusinessMinutes($started, $now, $calendar, $calendarType);
            $consumed   = 0.0;
            if ($totalMin > 0) {
                $consumed = ($elapsedMin / $totalMin);
            }

            $currentTargets[$idx]['consumedPercentage'] = round($consumed, 4);

            $status = self::STATUS_ON_TRACK;
            if ($consumed >= 0.8) {
                $status = self::STATUS_AT_RISK;
            }

            if ($consumed >= 1.0) {
                $status = self::STATUS_BREACHED;
            }

            $currentTargets[$idx]['status'] = $status;
        }//end foreach

        return $currentTargets;
    }//end evaluateTargets()

    /**
     * Execute escalation steps whose thresholds were just crossed.
     *
     * Idempotent per `(objectId, escalationLevel)`. Creates an
     * `slaBreachEvent` audit record and sends a notification on the
     * configured channel (email / nextcloud-notification / webhook).
     *
     * @param array<string, mixed>             $policy       Policy.
     * @param string                           $objectType   Tracked object type.
     * @param string                           $objectId     Tracked object UUID.
     * @param array<int, array<string, mixed>> $newTargets   Targets after re-evaluation.
     * @param array<int, array<string, mixed>> $priorTargets Targets before evaluation.
     * @param int                              $alreadyFired The current escalation level.
     *
     * @return array{level:int, eventIds:array<int,string>} The new escalation level + event UUIDs.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-004
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

        // Compute the highest consumedPercentage across all targets,
        // because escalation thresholds are tested against any target.
        $maxConsumed = 0.0;
        $hottestKind = '';
        foreach ($newTargets as $target) {
            $consumed = (float) ($target['consumedPercentage'] ?? 0.0);
            if ($consumed > $maxConsumed) {
                $maxConsumed = $consumed;
                $hottestKind = (string) ($target['kind'] ?? '');
            }
        }

        $newLevel = $alreadyFired;
        $eventIds = [];

        foreach ($chain as $idx => $step) {
            $level = ($idx + 1);
            if ($level <= $alreadyFired) {
                continue;
            }

            $triggerAt = (float) ($step['triggerAt'] ?? 1.0);
            if ($maxConsumed + 1e-9 < $triggerAt) {
                continue;
            }

            $eventId = $this->fireEscalation(
                policy: $policy,
                objectType: $objectType,
                objectId: $objectId,
                step: $step,
                level: $level,
                consumed: $maxConsumed,
                hottestKind: $hottestKind,
            );

            if ($eventId !== null) {
                $eventIds[] = $eventId;
            }

            $newLevel = $level;
        }//end foreach

        return ['level' => $newLevel, 'eventIds' => $eventIds];
    }//end executeEscalations()

    /**
     * Mark the timer as paused.
     *
     * @param array<string, mixed> $slaStatus Current slaStatus.
     * @param DateTimeInterface    $now       Pause instant.
     *
     * @return array<string, mixed> Updated slaStatus.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-003
     */
    public function pauseTimer(array $slaStatus, DateTimeInterface $now): array
    {
        if (isset($slaStatus['pausedAt']) === true && $slaStatus['pausedAt'] !== null) {
            return $slaStatus;
        }

        $slaStatus['pausedAt']        = $now->format(DateTimeInterface::ATOM);
        $slaStatus['lastEvaluatedAt'] = $slaStatus['pausedAt'];
        return $slaStatus;
    }//end pauseTimer()

    /**
     * Resume the timer and shift target dueAt values by the paused
     * duration.
     *
     * @param array<string, mixed> $slaStatus Current slaStatus.
     * @param array<string, mixed> $policy    Policy (for calendar config).
     * @param DateTimeInterface    $now       Resume instant.
     *
     * @return array<string, mixed> Updated slaStatus.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-003
     */
    public function resumeTimer(array $slaStatus, array $policy, DateTimeInterface $now): array
    {
        $pausedAtRaw = $slaStatus['pausedAt'] ?? null;
        if ($pausedAtRaw === null || $pausedAtRaw === '') {
            return $slaStatus;
        }

        $pausedAt = $this->parseIsoOrNull(value: $pausedAtRaw);
        if ($pausedAt === null) {
            $slaStatus['pausedAt'] = null;
            return $slaStatus;
        }

        $calendar = (string) ($policy['holidayCalendar'] ?? 'nl-feestdagen-rijksoverheid');

        $newTargets = [];
        foreach (($slaStatus['targets'] ?? []) as $target) {
            $calendarType = (string) ($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_BUSINESS);
            // For 24x7 we charge wall-clock minutes; for business modes,
            // we only charge business minutes (Friday 16:00 → Monday 10:00
            // is 2 business hours, not 66).
            $pausedMin = $this->businessHours->elapsedBusinessMinutes(
                $pausedAt,
                $now,
                $calendar,
                $calendarType,
            );

            if ($pausedMin > 0 && isset($target['dueAt']) === true) {
                $due = $this->parseIsoOrNull(value: $target['dueAt']);
                if ($due !== null) {
                    // Extend dueAt by the paused business-minutes inside its calendar mode.
                    $extended        = $this->businessHours->addDuration(
                        calendarType: $calendarType,
                        startTime: $due,
                        duration: new DateInterval('PT'.$pausedMin.'M'),
                        holidayCalendar: $calendar,
                    );
                    $target['dueAt'] = $extended->format(DateTimeInterface::ATOM);
                }
            }

            $newTargets[] = $target;
        }//end foreach

        $slaStatus['targets']         = $newTargets;
        $slaStatus['totalPausedMs']   = ((int) ($slaStatus['totalPausedMs'] ?? 0)) + ($this->wallClockMs(a: $pausedAt, b: $now));
        $slaStatus['pausedAt']        = null;
        $slaStatus['lastEvaluatedAt'] = $now->format(DateTimeInterface::ATOM);
        return $slaStatus;
    }//end resumeTimer()

    /**
     * Initialise the slaStatus envelope when a policy resolves on object
     * creation.
     *
     * @param array<string, mixed> $policy    Policy.
     * @param DateTimeInterface    $startTime Start instant.
     *
     * @return array<string, mixed> The initial slaStatus.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
     */
    public function initialiseStatus(array $policy, DateTimeInterface $startTime): array
    {
        return [
            'policyId'               => $this->policyIdentity(policy: $policy),
            'startedAt'              => $startTime->format(DateTimeInterface::ATOM),
            'pausedAt'               => null,
            'totalPausedMs'          => 0,
            'targets'                => $this->computeDeadlines(policy: $policy, startTime: $startTime),
            'currentEscalationLevel' => 0,
            'lastEvaluatedAt'        => $startTime->format(DateTimeInterface::ATOM),
        ];
    }//end initialiseStatus()

    /**
     * Mark an SLA target as met (e.g. on resolution).
     *
     * @param array<string, mixed> $slaStatus Current slaStatus.
     * @param string               $kind      Target kind to mark.
     * @param DateTimeInterface    $when      Met instant.
     *
     * @return array<string, mixed> Updated slaStatus.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
     */
    public function markTargetMet(array $slaStatus, string $kind, DateTimeInterface $when): array
    {
        $targets = $slaStatus['targets'] ?? [];
        foreach ($targets as $idx => $target) {
            if (($target['kind'] ?? '') !== $kind) {
                continue;
            }

            if (($target['status'] ?? '') === self::STATUS_BREACHED) {
                continue;
            }

            $targets[$idx]['status'] = self::STATUS_MET;
            $targets[$idx]['metAt']  = $when->format(DateTimeInterface::ATOM);
        }

        $slaStatus['targets']         = $targets;
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
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
     */
    public function loadActivePolicies(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'sla_policy_schema', '');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaEngineService: ObjectService not available',
                ['error' => $e->getMessage()]
            );
            return [];
        }

        try {
            $rows = $objectService->findAll(
                config: [
                    'register' => $register,
                    'schema'   => $schema,
                    'limit'    => 500,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaEngineService: ObjectService::findAll failed',
                ['error' => $e->getMessage()]
            );
            return [];
        }

        $policies = [];
        foreach ((array) $rows as $row) {
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
    }//end loadActivePolicies()

    /**
     * Test whether a policy applies to the given object's metadata.
     *
     * @param array<string, mixed> $policy         Policy.
     * @param string               $objectType     Object type.
     * @param string               $tier           Customer tier.
     * @param string               $organisationId Optional organisation UUID.
     * @param string               $contractId     Optional contract UUID.
     * @param DateTimeInterface    $now            Resolution instant.
     *
     * @return bool True when this policy is a candidate.
     */
    private function policyApplies(
        array $policy,
        string $objectType,
        string $tier,
        string $organisationId,
        string $contractId,
        DateTimeInterface $now,
    ): bool {
        if ($this->matchesAppliesTo(policy: $policy, objectType: $objectType) === false) {
            return false;
        }

        if ($this->matchesTier(policy: $policy, tier: $tier) === false) {
            return false;
        }

        if ($this->matchesScope(policy: $policy, organisationId: $organisationId, contractId: $contractId) === false) {
            return false;
        }

        return $this->isValidAt(policy: $policy, now: $now);
    }//end policyApplies()

    /**
     * Whether the policy's `appliesTo` matches the object type.
     *
     * @param array<string, mixed> $policy     Policy.
     * @param string               $objectType Object type.
     *
     * @return bool
     */
    private function matchesAppliesTo(array $policy, string $objectType): bool
    {
        $applies = (string) ($policy['appliesTo'] ?? '*');
        return ($applies === '*' || $applies === $objectType);
    }//end matchesAppliesTo()

    /**
     * Whether the policy's `customerTier` matches, defaulting the
     * customer's tier to "bronze" when unspecified.
     *
     * @param array<string, mixed> $policy Policy.
     * @param string               $tier   Customer tier.
     *
     * @return bool
     */
    private function matchesTier(array $policy, string $tier): bool
    {
        $policyTier    = (string) ($policy['customerTier'] ?? '*');
        $effectiveTier = 'bronze';
        if ($tier !== '') {
            $effectiveTier = $tier;
        }

        return ($policyTier === '*' || $policyTier === $effectiveTier);
    }//end matchesTier()

    /**
     * Whether the policy's `customerScope` (contract/organisation
     * allow-lists) matches, when a scope is configured at all.
     *
     * @param array<string, mixed> $policy         Policy.
     * @param string               $organisationId Organisation UUID (or '').
     * @param string               $contractId     Contract UUID (or '').
     *
     * @return bool
     */
    private function matchesScope(array $policy, string $organisationId, string $contractId): bool
    {
        $scope = $policy['customerScope'] ?? null;
        if (is_array($scope) === false) {
            return true;
        }

        $orgs      = (array) ($scope['organisationIds'] ?? []);
        $contracts = (array) ($scope['contractIds'] ?? []);
        if ($orgs !== [] && in_array($organisationId, $orgs, true) === false) {
            return false;
        }

        if ($contracts !== [] && in_array($contractId, $contracts, true) === false) {
            return false;
        }

        return true;
    }//end matchesScope()

    /**
     * Whether `$now` falls within the policy's validFrom/validUntil window.
     *
     * @param array<string, mixed> $policy Policy.
     * @param DateTimeInterface    $now    Resolution instant.
     *
     * @return bool
     */
    private function isValidAt(array $policy, DateTimeInterface $now): bool
    {
        $validFrom  = $this->parseIsoOrNull(value: ($policy['validFrom'] ?? null));
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
     * Score the specificity of a policy's scope for sort ordering.
     *
     * @param array<string, mixed> $policy         Policy.
     * @param string               $contractId     Contract UUID (or '').
     * @param string               $organisationId Organisation UUID (or '').
     *
     * @return int Specificity (higher = more specific).
     */
    private function scopeSpecificity(array $policy, string $contractId, string $organisationId): int
    {
        $scope = $policy['customerScope'] ?? null;
        if (is_array($scope) === false) {
            // No scope = wildcard.
            if (($policy['customerTier'] ?? '*') === '*') {
                return 0;
            }

            return 1;
        }

        $contracts = (array) ($scope['contractIds'] ?? []);
        $orgs      = (array) ($scope['organisationIds'] ?? []);
        if ($contracts !== [] && $contractId !== '' && in_array($contractId, $contracts, true) === true) {
            return 4;
        }

        if ($orgs !== [] && $organisationId !== '' && in_array($organisationId, $orgs, true) === true) {
            return 3;
        }

        return 2;
    }//end scopeSpecificity()

    /**
     * Fire one escalation step: notification + audit-record creation.
     *
     * @param array<string, mixed> $policy      Policy.
     * @param string               $objectType  Tracked object type.
     * @param string               $objectId    Tracked object UUID.
     * @param array<string, mixed> $step        Escalation step.
     * @param int                  $level       1-indexed escalation level.
     * @param float                $consumed    Consumed percentage at firing.
     * @param string               $hottestKind The target kind that drove the threshold.
     *
     * @return ?string Breach event UUID, or null on failure.
     */
    private function fireEscalation(
        array $policy,
        string $objectType,
        string $objectId,
        array $step,
        int $level,
        float $consumed,
        string $hottestKind,
    ): ?string {
        $channel = (string) ($step['channel'] ?? 'nextcloud-notification');
        $notify  = (string) ($step['notify'] ?? 'assignee');

        try {
            $notifiedActors = $this->dispatchNotification(
                channel: $channel,
                notify: $notify,
                policy: $policy,
                objectType: $objectType,
                objectId: $objectId,
                level: $level,
                step: $step
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaEngineService: notification dispatch failed',
                ['error' => $e->getMessage(), 'channel' => $channel]
            );
            $notifiedActors = ['failed:'.$channel];
        }

        $kind = $hottestKind;
        if ($hottestKind === '') {
            $kind = 'resolution';
        }

        return $this->writeBreachEvent(
            policy: $policy,
            objectType: $objectType,
            objectId: $objectId,
            kind: $kind,
            level: $level,
            consumed: $consumed,
            channel: $channel,
            actors: $notifiedActors,
        );
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
     * @param string               $channel    Channel slug.
     * @param string               $notify     Actor role.
     * @param array<string, mixed> $policy     Policy.
     * @param string               $objectType Object type.
     * @param string               $objectId   Object UUID.
     * @param int                  $level      Escalation level.
     * @param array<string, mixed> $step       The escalation step (carries `templateId`).
     *
     * @return array<int, string> Notified actor identifiers / markers.
     *
     * @spec openspec/changes/outbound-messaging-provider-wiring/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
     */
    private function dispatchNotification(
        string $channel,
        string $notify,
        array $policy,
        string $objectType,
        string $objectId,
        int $level,
        array $step=[],
    ): array {
        if ($channel === 'nextcloud-notification') {
            return $this->dispatchNextcloudNotification(
                notify: $notify,
                policy: $policy,
                objectType: $objectType,
                objectId: $objectId,
                level: $level
            );
        }

        if ($channel === 'sms' || $channel === 'whatsapp') {
            return $this->dispatchChannelAdapter(
                channel: $channel,
                notify: $notify,
                policy: $policy,
                objectType: $objectType,
                objectId: $objectId,
                level: $level,
                step: $step
            );
        }

        // Email / webhook are delegated to their own capabilities; record
        // the intent so the audit trail captures it.
        return ['deferred:'.$channel.':'.$notify];
    }//end dispatchNotification()

    /**
     * Dispatch a Nextcloud in-app notification to the resolved actor.
     *
     * @param string               $notify     Actor role.
     * @param array<string, mixed> $policy     Policy.
     * @param string               $objectType Object type.
     * @param string               $objectId   Object UUID.
     * @param int                  $level      Escalation level.
     *
     * @return array<int, string> Notified actor identifiers / markers.
     */
    private function dispatchNextcloudNotification(
        string $notify,
        array $policy,
        string $objectType,
        string $objectId,
        int $level,
    ): array {
        $actor = $this->resolveActor(role: $notify, policy: $policy);
        if ($actor === null) {
            return ['unresolved:'.$notify];
        }

        try {
            $notification = $this->notifications->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($actor)
                ->setDateTime(new DateTime())
                ->setObject($objectType, $objectId)
                ->setSubject('sla_breach', ['level' => $level, 'policy' => (string) ($policy['name'] ?? '')])
                ->setMessage('sla_breach_message', ['level' => $level]);
            $this->notifications->notify($notification);
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaEngineService: nextcloud-notification dispatch failed',
                ['error' => $e->getMessage(), 'actor' => $actor]
            );
            return ['failed:nextcloud-notification:'.$actor];
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
     * @param string               $channel    `sms` or `whatsapp`.
     * @param string               $notify     Actor role.
     * @param array<string, mixed> $policy     Policy.
     * @param string               $objectType Object type.
     * @param string               $objectId   Object UUID.
     * @param int                  $level      Escalation level.
     * @param array<string, mixed> $step       The escalation step (carries `templateId`).
     *
     * @return array<int, string> Notified actor identifiers / markers.
     *
     * @spec openspec/changes/outbound-messaging-provider-wiring/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
     */
    private function dispatchChannelAdapter(
        string $channel,
        string $notify,
        array $policy,
        string $objectType,
        string $objectId,
        int $level,
        array $step,
    ): array {
        if ($notify !== 'customer') {
            return ['unsupported:'.$channel.':'.$notify];
        }

        $contact = $this->resolveCustomerContact(objectType: $objectType, objectId: $objectId);
        if ($contact === null) {
            return ['unresolved:'.$notify];
        }

        $contactId = (string) ($contact['uuid'] ?? ($contact['id'] ?? ''));
        $context   = [
            'clientId' => (string) ($contact['client'] ?? ''),
            'agent'    => 'system:sla-engine',
        ];

        if ($channel === 'sms') {
            return $this->dispatchSmsEscalation(
                contact: $contact,
                contactId: $contactId,
                policy: $policy,
                level: $level,
                context: $context
            );
        }

        return $this->dispatchWhatsAppEscalation(
            contact: $contact,
            contactId: $contactId,
            policy: $policy,
            level: $level,
            step: $step,
            context: $context
        );
    }//end dispatchChannelAdapter()

    /**
     * Dispatch an SMS escalation through SmsAdapter.
     *
     * @param array<string, mixed> $contact   Recipient contact/client row.
     * @param string               $contactId Recipient UUID (audit marker).
     * @param array<string, mixed> $policy    Policy.
     * @param int                  $level     Escalation level.
     * @param array<string, mixed> $context   Send context (clientId, agent).
     *
     * @return array<int, string> Notified actor identifiers / markers.
     */
    private function dispatchSmsEscalation(
        array $contact,
        string $contactId,
        array $policy,
        int $level,
        array $context,
    ): array {
        $adapter = $this->resolveMessagingAdapter(class: 'OCA\\Pipelinq\\Service\\SmsAdapter');
        if ($adapter === null) {
            return ['failed:sms'];
        }

        try {
            $outcome = $adapter->send(
                contact: $contact,
                body: $this->renderBreachMessage(policy: $policy, level: $level),
                providerHint: null,
                context: $context
            );
        } catch (Throwable $e) {
            $this->logger->warning('SlaEngineService: SMS escalation dispatch threw', ['error' => $e->getMessage()]);
            return ['failed:sms'];
        }

        return $this->markerFromOutcome(channel: 'sms', contactId: $contactId, outcome: $outcome);
    }//end dispatchSmsEscalation()

    /**
     * Dispatch a WhatsApp escalation through WhatsAppAdapter.
     *
     * A whatsapp escalation is business-initiated: a `templateId` on the step
     * is required unless the contact has an open 24h session window. Missing
     * both fails closed with a `template-missing:whatsapp` marker.
     *
     * @param array<string, mixed> $contact   Recipient contact/client row.
     * @param string               $contactId Recipient UUID.
     * @param array<string, mixed> $policy    Policy.
     * @param int                  $level     Escalation level.
     * @param array<string, mixed> $step      The escalation step (carries `templateId`).
     * @param array<string, mixed> $context   Send context (clientId, agent).
     *
     * @return array<int, string> Notified actor identifiers / markers.
     */
    private function dispatchWhatsAppEscalation(
        array $contact,
        string $contactId,
        array $policy,
        int $level,
        array $step,
        array $context,
    ): array {
        $adapter = $this->resolveMessagingAdapter(class: 'OCA\\Pipelinq\\Service\\WhatsAppAdapter');
        if ($adapter === null) {
            return ['failed:whatsapp'];
        }

        $templateId = trim((string) ($step['templateId'] ?? ''));
        $body       = '';
        $template   = $templateId;
        if ($templateId === '') {
            $withinWindow = false;
            if (method_exists($adapter, 'isWithinSessionWindow') === true) {
                $withinWindow = (bool) $adapter->isWithinSessionWindow(contactId: $contactId);
            }

            if ($withinWindow === false) {
                return ['template-missing:whatsapp'];
            }

            $body     = $this->renderBreachMessage(policy: $policy, level: $level);
            $template = '';
        }

        $sendTemplateId = null;
        if ($template !== '') {
            $sendTemplateId = $template;
        }

        try {
            $outcome = $adapter->send(
                contact: $contact,
                body: $body,
                templateId: $sendTemplateId,
                parameters: [],
                context: $context
            );
        } catch (Throwable $e) {
            $this->logger->warning('SlaEngineService: WhatsApp escalation dispatch threw', ['error' => $e->getMessage()]);
            return ['failed:whatsapp'];
        }

        return $this->markerFromOutcome(channel: 'whatsapp', contactId: $contactId, outcome: $outcome);
    }//end dispatchWhatsAppEscalation()

    /**
     * Map an adapter outcome envelope onto the notifiedActors marker vocabulary.
     *
     * @param string               $channel   `sms` or `whatsapp`.
     * @param string               $contactId Recipient UUID (the `sent` actor).
     * @param array<string, mixed> $outcome   Adapter outcome envelope.
     *
     * @return array<int, string> Notified actor identifiers / markers.
     */
    private function markerFromOutcome(string $channel, string $contactId, array $outcome): array
    {
        $status = (string) ($outcome['status'] ?? '');
        if ($status === 'sent') {
            $actor = $contactId;
            if ($actor === '') {
                $actor = 'customer';
            }

            return [$actor];
        }

        if ($status === 'consentMissing') {
            return ['consent-missing:'.$channel];
        }

        if ($status === 'sessionWindowExpired') {
            return ['template-missing:'.$channel];
        }

        return ['failed:'.$channel.':'.$status];
    }//end markerFromOutcome()

    /**
     * Resolve a messaging adapter (SmsAdapter / WhatsAppAdapter) lazily.
     *
     * @param string $class Adapter FQCN.
     *
     * @return object|null The adapter, or null when unavailable.
     */
    private function resolveMessagingAdapter(string $class): ?object
    {
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
     * @param string $objectId   Tracked object UUID.
     *
     * @return array<string, mixed>|null The recipient row, or null.
     */
    private function resolveCustomerContact(string $objectType, string $objectId): ?array
    {
        $targetSchema = $this->appConfig->getValueString(Application::APP_ID, 'sla_target_schema_'.$objectType, '');
        if ($targetSchema === '') {
            $targetSchema = $objectType;
        }

        $target = $this->loadPipelinqObject(schema: $targetSchema, id: $objectId);
        if ($target === null) {
            return null;
        }

        $contactSchema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
        if ($contactSchema === '') {
            $contactSchema = 'contact';
        }

        $contactId = (string) ($target['contact'] ?? '');
        if ($contactId !== '') {
            $contact = $this->loadPipelinqObject(schema: $contactSchema, id: $contactId);
            if ($contact !== null) {
                return $contact;
            }
        }

        $clientSchema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
        if ($clientSchema === '') {
            $clientSchema = 'client';
        }

        $clientId = (string) ($target['client'] ?? '');
        if ($clientId !== '') {
            return $this->loadPipelinqObject(schema: $clientSchema, id: $clientId);
        }

        return null;
    }//end resolveCustomerContact()

    /**
     * Load a pipelinq-register object by schema slug + id (best effort).
     *
     * @param string $schema Schema slug.
     * @param string $id     Object UUID.
     *
     * @return array<string, mixed>|null The normalised object, or null.
     */
    private function loadPipelinqObject(string $schema, string $id): ?array
    {
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
            $entity = $objectService->find(id: $id, register: $register, schema: $schema);
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
     * @param int                  $level  Escalation level.
     *
     * @return string The rendered message body.
     */
    private function renderBreachMessage(array $policy, int $level): string
    {
        $name = (string) ($policy['name'] ?? 'SLA');
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
     * @param string               $role   Actor role.
     * @param array<string, mixed> $policy Policy (reserved for future per-policy overrides).
     *
     * @return ?string The resolved user ID, or null.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function resolveActor(string $role, array $policy): ?string
    {
        unset($policy);
        $key   = 'sla_actor_'.preg_replace('/[^a-z0-9_-]/i', '_', $role);
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
     * @param array<string, mixed> $policy     Policy.
     * @param string               $objectType Object type.
     * @param string               $objectId   Object UUID.
     * @param string               $kind       Target kind.
     * @param int                  $level      Escalation level.
     * @param float                $consumed   Consumed percentage.
     * @param string               $channel    Channel slug.
     * @param array<int, string>   $actors     Notified actors.
     *
     * @return ?string Breach event UUID, or null on failure.
     */
    private function writeBreachEvent(
        array $policy,
        string $objectType,
        string $objectId,
        string $kind,
        int $level,
        float $consumed,
        string $channel,
        array $actors,
    ): ?string {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'sla_breach_event_schema', '');
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

        $payload = [
            'policyId'           => $this->policyIdentity(policy: $policy),
            'targetObjectType'   => $objectType,
            'targetObjectId'     => $objectId,
            'targetKind'         => $kind,
            'breachedAt'         => $this->now()->format(DateTimeInterface::ATOM),
            'consumedPercentage' => round($consumed, 4),
            'escalationLevel'    => $level,
            'notifiedActors'     => $actors,
            'channel'            => $channel,
            'acknowledged'       => false,
        ];

        try {
            $saved = $objectService->saveObject(
                object: $payload,
                extend: [],
                register: $register,
                schema: $schema,
            );
            $uuid  = '';
            if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
                $uuid = (string) $saved->getUuid();
            } else if (is_array($saved) === true) {
                $uuid = (string) ($saved['uuid'] ?? $saved['id'] ?? '');
            }

            if ($uuid === '') {
                return null;
            }

            return $uuid;
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaEngineService: failed to write breach event',
                ['error' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end writeBreachEvent()

    /**
     * Return the identity string for a policy (UUID preferred, else slug).
     *
     * @param array<string, mixed> $policy Policy.
     *
     * @return string Identity.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
     */
    public function policyIdentity(array $policy): string
    {
        if (isset($policy['id']) === true && $policy['id'] !== '') {
            return (string) $policy['id'];
        }

        if (isset($policy['uuid']) === true && $policy['uuid'] !== '') {
            return (string) $policy['uuid'];
        }

        return (string) ($policy['@self']['slug'] ?? ($policy['slug'] ?? ''));
    }//end policyIdentity()

    /**
     * Parse an ISO 8601 duration string into a DateInterval.
     *
     * @param string $duration ISO 8601 duration (PT4H, P1D, P3W, ...).
     *
     * @return DateInterval Parsed interval.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
     */
    public function parseDuration(string $duration): DateInterval
    {
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
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
     */
    public function parseIsoOrNull($value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
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
    private function normaliseObject($row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true) {
            if (method_exists($row, 'getObject') === true) {
                $object = $row->getObject();
                if (is_array($object) === true) {
                    if (method_exists($row, 'getUuid') === true) {
                        $object['id'] = $object['id'] ?? (string) $row->getUuid();
                    }

                    return $object;
                }
            }

            if (method_exists($row, 'jsonSerialize') === true) {
                $json = $row->jsonSerialize();
                if (is_array($json) === true) {
                    return $json;
                }
            }
        }

        return [];
    }//end normaliseObject()

    /**
     * Wall-clock milliseconds between two instants.
     *
     * @param DateTimeInterface $a Earlier instant.
     * @param DateTimeInterface $b Later instant.
     *
     * @return int Milliseconds (>=0).
     */
    private function wallClockMs(DateTimeInterface $a, DateTimeInterface $b): int
    {
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
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }//end now()
}//end class
