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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
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
     * @param HolidayCalendarService  $holidays         Holiday calendar service.
     * @param BusinessHoursCalculator $businessHours    Business-hours math service.
     * @param ContainerInterface      $container        DI container (OR ObjectService).
     * @param IAppConfig              $appConfig        App config.
     * @param INotificationManager    $notifications    Notification manager.
     * @param LoggerInterface         $logger           PSR logger.
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
     * @param string                $objectType Tracked object type.
     * @param string                $objectId   Object UUID (currently unused; reserved).
     * @param array<string, mixed>  $metadata   Resolution metadata (`tier`, `organisationId`, `contractId`).
     *
     * @return ?array<string, mixed> The matched policy array, or null.
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
            if ($this->policyApplies($policy, $objectType, $tier, $organisationId, $contractId, $now) === false) {
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
                $scopeA = $this->scopeSpecificity($a, $contractId, $organisationId);
                $scopeB = $this->scopeSpecificity($b, $contractId, $organisationId);
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
     * @param array<string, mixed>      $policy     Policy array.
     * @param DateTimeInterface         $startTime  Start instant.
     * @param ?array<string, mixed>     $pausedState Optional pause state (for re-compute).
     *
     * @return array<int, array<string, mixed>> Targets array with `dueAt`,
     *         `status`, `consumedPercentage` initialised.
     */
    public function computeDeadlines(
        array $policy,
        DateTimeInterface $startTime,
        ?array $pausedState = null,
    ): array {
        $calendar = (string) ($policy['holidayCalendar'] ?? 'nl-feestdagen-rijksoverheid');
        $results  = [];

        foreach (($policy['targets'] ?? []) as $target) {
            $duration     = $this->parseDuration((string) ($target['duration'] ?? 'PT0S'));
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
        }

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
     */
    public function evaluateTargets(array $currentTargets, array $policy, DateTimeInterface $now): array
    {
        $calendar = (string) ($policy['holidayCalendar'] ?? 'nl-feestdagen-rijksoverheid');

        foreach ($currentTargets as $idx => $target) {
            if (($target['status'] ?? '') === self::STATUS_MET) {
                continue;
            }

            $started = $this->parseIsoOrNull($target['startedAt'] ?? null);
            $dueAt   = $this->parseIsoOrNull($target['dueAt'] ?? null);
            if ($started === null || $dueAt === null) {
                continue;
            }

            $calendarType = (string) ($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_BUSINESS);

            $totalMin    = $this->businessHours->elapsedBusinessMinutes($started, $dueAt, $calendar, $calendarType);
            $elapsedMin  = $this->businessHours->elapsedBusinessMinutes($started, $now, $calendar, $calendarType);
            $consumed    = $totalMin > 0 ? ($elapsedMin / $totalMin) : 0.0;

            $currentTargets[$idx]['consumedPercentage'] = round($consumed, 4);
            if ($consumed >= 1.0) {
                $currentTargets[$idx]['status'] = self::STATUS_BREACHED;
            } else if ($consumed >= 0.8) {
                $currentTargets[$idx]['status'] = self::STATUS_AT_RISK;
            } else {
                $currentTargets[$idx]['status'] = self::STATUS_ON_TRACK;
            }
        }

        return $currentTargets;
    }//end evaluateTargets()

    /**
     * Execute escalation steps whose thresholds were just crossed.
     *
     * Idempotent per `(objectId, escalationLevel)`. Creates an
     * `slaBreachEvent` audit record and sends a notification on the
     * configured channel (email / nextcloud-notification / webhook).
     *
     * @param array<string, mixed>             $policy         Policy.
     * @param string                           $objectType     Tracked object type.
     * @param string                           $objectId       Tracked object UUID.
     * @param array<int, array<string, mixed>> $newTargets     Targets after re-evaluation.
     * @param array<int, array<string, mixed>> $priorTargets   Targets before evaluation.
     * @param int                              $alreadyFired   The current escalation level.
     *
     * @return array{level:int, eventIds:array<int,string>} The new escalation level + event UUIDs.
     */
    public function executeEscalations(
        array $policy,
        string $objectType,
        string $objectId,
        array $newTargets,
        array $priorTargets,
        int $alreadyFired,
    ): array {
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
        }

        return ['level' => $newLevel, 'eventIds' => $eventIds];
    }//end executeEscalations()

    /**
     * Mark the timer as paused.
     *
     * @param array<string, mixed> $slaStatus  Current slaStatus.
     * @param DateTimeInterface    $now        Pause instant.
     *
     * @return array<string, mixed> Updated slaStatus.
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
     */
    public function resumeTimer(array $slaStatus, array $policy, DateTimeInterface $now): array
    {
        $pausedAtRaw = $slaStatus['pausedAt'] ?? null;
        if ($pausedAtRaw === null || $pausedAtRaw === '') {
            return $slaStatus;
        }

        $pausedAt = $this->parseIsoOrNull($pausedAtRaw);
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
            $pausedMin    = $this->businessHours->elapsedBusinessMinutes(
                $pausedAt,
                $now,
                $calendar,
                $calendarType,
            );

            if ($pausedMin > 0 && isset($target['dueAt']) === true) {
                $due = $this->parseIsoOrNull($target['dueAt']);
                if ($due !== null) {
                    // Extend dueAt by the paused business-minutes inside its calendar mode.
                    $extended           = $this->businessHours->addDuration(
                        calendarType: $calendarType,
                        startTime: $due,
                        duration: new DateInterval('PT'.$pausedMin.'M'),
                        holidayCalendar: $calendar,
                    );
                    $target['dueAt']    = $extended->format(DateTimeInterface::ATOM);
                }
            }

            $newTargets[] = $target;
        }

        $slaStatus['targets']         = $newTargets;
        $slaStatus['totalPausedMs']   = ((int) ($slaStatus['totalPausedMs'] ?? 0)) + ($this->wallClockMs($pausedAt, $now));
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
     */
    public function initialiseStatus(array $policy, DateTimeInterface $startTime): array
    {
        return [
            'policyId'               => $this->policyIdentity($policy),
            'startedAt'              => $startTime->format(DateTimeInterface::ATOM),
            'pausedAt'               => null,
            'totalPausedMs'          => 0,
            'targets'                => $this->computeDeadlines($policy, $startTime),
            'currentEscalationLevel' => 0,
            'lastEvaluatedAt'        => $startTime->format(DateTimeInterface::ATOM),
        ];
    }//end initialiseStatus()

    /**
     * Mark an SLA target as met (e.g. on resolution).
     *
     * @param array<string, mixed> $slaStatus  Current slaStatus.
     * @param string               $kind       Target kind to mark.
     * @param DateTimeInterface    $when       Met instant.
     *
     * @return array<string, mixed> Updated slaStatus.
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
            $array = $this->normaliseObject($row);
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
        $applies = (string) ($policy['appliesTo'] ?? '*');
        if ($applies !== '*' && $applies !== $objectType) {
            return false;
        }

        $policyTier = (string) ($policy['customerTier'] ?? '*');
        $effectiveTier = $tier !== '' ? $tier : 'bronze';
        if ($policyTier !== '*' && $policyTier !== $effectiveTier) {
            return false;
        }

        $scope = $policy['customerScope'] ?? null;
        if (is_array($scope) === true) {
            $orgs      = (array) ($scope['organisationIds'] ?? []);
            $contracts = (array) ($scope['contractIds'] ?? []);
            if ($orgs !== [] && in_array($organisationId, $orgs, true) === false) {
                return false;
            }

            if ($contracts !== [] && in_array($contractId, $contracts, true) === false) {
                return false;
            }
        }

        $validFrom  = $this->parseIsoOrNull($policy['validFrom'] ?? null);
        $validUntil = $this->parseIsoOrNull($policy['validUntil'] ?? null);
        if ($validFrom !== null && $now < $validFrom) {
            return false;
        }

        if ($validUntil !== null && $now > $validUntil) {
            return false;
        }

        return true;
    }//end policyApplies()

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
            return ($policy['customerTier'] ?? '*') === '*' ? 0 : 1;
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
     * @param float                $consumed   Consumed percentage at firing.
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
            $notifiedActors = $this->dispatchNotification($channel, $notify, $policy, $objectType, $objectId, $level);
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaEngineService: notification dispatch failed',
                ['error' => $e->getMessage(), 'channel' => $channel]
            );
            $notifiedActors = ['failed:'.$channel];
        }

        return $this->writeBreachEvent(
            policy: $policy,
            objectType: $objectType,
            objectId: $objectId,
            kind: $hottestKind === '' ? 'resolution' : $hottestKind,
            level: $level,
            consumed: $consumed,
            channel: $channel,
            actors: $notifiedActors,
        );
    }//end fireEscalation()

    /**
     * Send a notification on the configured channel.
     *
     * Only `nextcloud-notification` is wired here directly; the other
     * channels (email, sms, whatsapp, webhook) are documented in spec
     * and delegated to either an OpenConnector outgoing webhook or the
     * upcoming `whatsapp-sms-channel-adapter` integration.
     *
     * @param string                $channel    Channel slug.
     * @param string                $notify     Actor role.
     * @param array<string, mixed>  $policy     Policy.
     * @param string                $objectType Object type.
     * @param string                $objectId   Object UUID.
     * @param int                   $level      Escalation level.
     *
     * @return array<int, string> Notified actor identifiers.
     */
    private function dispatchNotification(
        string $channel,
        string $notify,
        array $policy,
        string $objectType,
        string $objectId,
        int $level,
    ): array {
        if ($channel !== 'nextcloud-notification') {
            // Email / sms / whatsapp / webhook are delegated to outgoing
            // adapters in their respective specs. Record the intent so
            // the audit trail captures it.
            return ['deferred:'.$channel.':'.$notify];
        }

        $actor = $this->resolveActor($notify, $policy);
        if ($actor === null) {
            return ['unresolved:'.$notify];
        }

        try {
            $notification = $this->notifications->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($actor)
                ->setDateTime(new \DateTime())
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
    }//end dispatchNotification()

    /**
     * Resolve a notify role to a concrete Nextcloud user identifier.
     *
     * Configurable via per-tenant app-config keys
     * (`sla_actor_team-lead`, `sla_actor_manager`, etc.). Falls back to
     * the configured fallback admin.
     *
     * @param string                $role   Actor role.
     * @param array<string, mixed>  $policy Policy (reserved for future per-policy overrides).
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
        return $fallback === '' ? null : $fallback;
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
            'policyId'           => $this->policyIdentity($policy),
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

            return $uuid === '' ? null : $uuid;
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaEngineService: failed to write breach event',
                ['error' => $e->getMessage()]
            );
            return null;
        }
    }//end writeBreachEvent()

    /**
     * Return the identity string for a policy (UUID preferred, else slug).
     *
     * @param array<string, mixed> $policy Policy.
     *
     * @return string Identity.
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
        return $delta > 0 ? ($delta * 1000) : 0;
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
