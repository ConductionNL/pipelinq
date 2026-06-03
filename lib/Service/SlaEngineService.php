<?php

/**
 * Pipelinq SlaEngineService.
 *
 * Core cross-cutting SLA engine: resolves a unique policy per tracked object,
 * computes holiday-aware deadlines, evaluates target status, pauses/resumes the
 * timer, and executes the escalation chain with an immutable breach-event trail.
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
 * @link https://codeberg.org/Conduction/pipelinq
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
use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The SLA engine — stateless business logic invoked by listeners and the
 * deadline sweep job (REQ-001..REQ-004).
 *
 * Deadlines and breach status are computed server-side only; the engine never
 * trusts a client-supplied slaStatus. The chosen policy is captured as an
 * immutable snapshot on the tracked object, so a later policy edit or customer
 * tier change does not retroactively move an in-flight deadline (REQ-009).
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class SlaEngineService
{
    /**
     * At-risk threshold: a target is "at-risk" once consumption reaches 80%.
     *
     * @var float
     */
    private const AT_RISK_THRESHOLD = 0.8;

    /**
     * Constructor.
     *
     * @param BusinessHoursCalculator $calculator The calendar-aware date math.
     * @param SlaEscalationDispatcher $dispatcher The escalation dispatcher.
     * @param IAppConfig              $appConfig  The app configuration.
     * @param ContainerInterface      $container  Container for ObjectService lookup.
     * @param LoggerInterface         $logger     The logger.
     */
    public function __construct(
        private BusinessHoursCalculator $calculator,
        private SlaEscalationDispatcher $dispatcher,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve exactly one policy for a tracked object (REQ-001).
     *
     * Matches `appliesTo` (or `*`), then `customerTier` (or `*`), then optional
     * `customerScope` (contract beats organisation beats tier-wide). Ties break
     * on most-specific scope, then `priority` (lower wins), then `validFrom`
     * (newest wins). Returns null when nothing matches (fail-safe).
     *
     * @param string                                $objectType The tracked object type.
     * @param array<string, mixed>                  $metadata   Resolution context: customerTier, organisationId, contractId.
     * @param DateTimeImmutable                     $now        The evaluation instant (validFrom/validUntil gate).
     * @param array<int, array<string, mixed>>|null $policies   Optional explicit policy set (else loaded from OR).
     *
     * @return array<string, mixed>|null The winning policy, or null.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
     */
    public function resolvePolicyForObject(
        string $objectType,
        array $metadata,
        DateTimeImmutable $now,
        ?array $policies=null
    ): ?array {
        $tier = $this->normaliseTier(tier: (string) ($metadata['customerTier'] ?? ''));

        $candidates = ($policies ?? $this->loadPolicies());
        $matches    = [];
        foreach ($candidates as $policy) {
            if ($this->policyMatches(policy: $policy, objectType: $objectType, tier: $tier, metadata: $metadata, now: $now) === true) {
                $matches[] = $policy;
            }
        }

        if (count($matches) === 0) {
            $this->logger->info('SlaEngineService: no SLA policy matched', ['objectType' => $objectType, 'tier' => $tier]);
            return null;
        }

        usort($matches, fn (array $first, array $second): int => $this->comparePolicies(first: $first, second: $second));

        $winner = $matches[0];
        $this->logger->debug(
            'SlaEngineService: policy resolved',
            [
                'objectType' => $objectType,
                'tier'       => $tier,
                'policy'     => ($winner['name'] ?? ''),
                'priority'   => ($winner['priority'] ?? null),
                'candidates' => count($matches),
            ]
        );

        return $winner;
    }//end resolvePolicyForObject()

    /**
     * Compute the deadline for each target of a policy (REQ-002).
     *
     * @param array<string, mixed> $policy    The resolved policy.
     * @param DateTimeImmutable    $startTime The SLA start instant.
     *
     * @return array<int, array<string, mixed>> Targets with computed dueAt.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
     */
    public function computeDeadlines(array $policy, DateTimeImmutable $startTime): array
    {
        $holidayCalendar = (string) ($policy['holidayCalendar'] ?? 'none');
        $result          = [];

        foreach (($policy['targets'] ?? []) as $target) {
            $duration = $this->parseDuration(spec: (string) ($target['duration'] ?? ''));
            if ($duration === null) {
                $this->logger->warning('SlaEngineService: invalid target duration, skipping', ['target' => $target]);
                continue;
            }

            $calendar = (string) ($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_24X7);
            $dueAt    = $this->calculator->addDuration(
                calendarType: $calendar,
                startTime: $startTime,
                duration: $duration,
                holidayCalendar: $holidayCalendar
            );

            $result[] = [
                'kind'               => (string) ($target['kind'] ?? ''),
                'calendar'           => $calendar,
                'duration'           => (string) ($target['duration'] ?? ''),
                'dueAt'              => $dueAt->format('Y-m-d\TH:i:sP'),
                'consumedPercentage' => 0.0,
                'status'             => 'on-track',
                'metAt'              => null,
                'breachEventIds'     => [],
            ];
        }//end foreach

        return $result;
    }//end computeDeadlines()

    /**
     * Build the initial slaStatus sub-object for a freshly tracked object.
     *
     * @param array<string, mixed> $policy    The resolved policy.
     * @param string               $policyId  The policy UUID to snapshot.
     * @param DateTimeImmutable    $startTime The SLA start instant.
     *
     * @return array<string, mixed> The slaStatus sub-object.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
     */
    public function buildInitialStatus(array $policy, string $policyId, DateTimeImmutable $startTime): array
    {
        return [
            'policyId'               => $policyId,
            'startedAt'              => $startTime->format('Y-m-d\TH:i:sP'),
            'pausedAt'               => null,
            'totalPausedMs'          => 0,
            'targets'                => $this->computeDeadlines(policy: $policy, startTime: $startTime),
            'currentEscalationLevel' => 0,
            'lastEvaluatedAt'        => $startTime->format('Y-m-d\TH:i:sP'),
        ];
    }//end buildInitialStatus()

    /**
     * Evaluate target status against the current time (REQ-002).
     *
     * Each target's consumed fraction is elapsed/total. While paused the status
     * is frozen and never advances. A target marked `met` (resolution recorded)
     * is left untouched.
     *
     * @param array<string, mixed> $slaStatus The current slaStatus.
     * @param DateTimeImmutable    $now       The evaluation instant.
     * @param bool                 $resolved  Whether the object reached its goal state now.
     *
     * @return array<string, mixed> The updated slaStatus.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $resolved is an evaluation mode, not a behavioural toggle.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-003
     */
    public function evaluateTargets(array $slaStatus, DateTimeImmutable $now, bool $resolved=false): array
    {
        $paused = (($slaStatus['pausedAt'] ?? null) !== null);

        $started = $this->toDate(value: ($slaStatus['startedAt'] ?? null));
        foreach (($slaStatus['targets'] ?? []) as $idx => $target) {
            if (($target['status'] ?? '') === 'met') {
                continue;
            }

            if ($resolved === true) {
                $slaStatus['targets'][$idx]['status'] = 'met';
                $slaStatus['targets'][$idx]['metAt']  = $now->format('Y-m-d\TH:i:sP');
                continue;
            }

            if ($paused === true) {
                // Timer frozen: keep status as-is, no advancement (REQ-003).
                continue;
            }

            $due = $this->toDate(value: ($target['dueAt'] ?? null));
            if ($due === null || $started === null) {
                continue;
            }

            $consumed = $this->consumedFraction(started: $started, due: $due, now: $now);
            $slaStatus['targets'][$idx]['consumedPercentage'] = round($consumed, 4);
            $slaStatus['targets'][$idx]['status'] = $this->statusForConsumption(consumed: $consumed);
        }//end foreach

        $slaStatus['lastEvaluatedAt'] = $now->format('Y-m-d\TH:i:sP');
        return $slaStatus;
    }//end evaluateTargets()

    /**
     * Execute escalation steps whose threshold was just crossed (REQ-004).
     *
     * A step fires at most once per object: only steps with index greater than
     * the recorded `currentEscalationLevel` whose `triggerAt` is now satisfied by
     * any (non-met, non-paused) target are dispatched, in ascending order. Each
     * firing writes an sla_breach_event and advances `currentEscalationLevel`.
     *
     * @param array<string, mixed> $policy     The resolved policy.
     * @param string               $policyId   The policy UUID.
     * @param string               $objectType The tracked object type.
     * @param string               $objectId   The tracked object UUID.
     * @param array<string, mixed> $objectData The tracked object data (assignee, client, title).
     * @param array<string, mixed> $slaStatus  The current slaStatus (already evaluated).
     * @param DateTimeImmutable    $now        The evaluation instant.
     *
     * @return array<string, mixed> The slaStatus with advanced escalation level and breach-event ids.
     *
     * @SuppressWarnings(PHPMD.CountInLoopExpression) — chain length is bounded and read once per short loop.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-004
     */
    public function executeEscalations(
        array $policy,
        string $policyId,
        string $objectType,
        string $objectId,
        array $objectData,
        array $slaStatus,
        DateTimeImmutable $now
    ): array {
        if (($slaStatus['pausedAt'] ?? null) !== null) {
            // No escalation while paused (REQ-003).
            return $slaStatus;
        }

        $chain = array_values($policy['escalationChain'] ?? []);
        if (count($chain) === 0) {
            return $slaStatus;
        }

        $level    = (int) ($slaStatus['currentEscalationLevel'] ?? 0);
        $maxRatio = $this->highestConsumption(slaStatus: $slaStatus);

        for ($step = $level; $step < count($chain); $step++) {
            $trigger = (float) ($chain[$step]['triggerAt'] ?? 1.0);
            if ($maxRatio < $trigger) {
                break;
            }

            $targetKind    = $this->targetKindAtConsumption(slaStatus: $slaStatus, step: $chain[$step]);
            $breachContext = [
                'targetKind'         => $targetKind,
                'consumedPercentage' => $maxRatio,
                'breachedAt'         => $this->breachTime(slaStatus: $slaStatus, ratio: $trigger, now: $now),
            ];

            $notified = $this->dispatcher->dispatch(
                step: $chain[$step],
                objectType: $objectType,
                objectId: $objectId,
                objectData: $objectData,
                breachContext: $breachContext
            );

            $eventId = $this->recordBreachEvent(
                policyId: $policyId,
                objectType: $objectType,
                objectId: $objectId,
                targetKind: $targetKind,
                breachContext: $breachContext,
                escalationLevel: ($step + 1),
                notifiedActors: $notified
            );

            $slaStatus['currentEscalationLevel'] = ($step + 1);
            if ($eventId !== null) {
                $slaStatus = $this->attachBreachEventId(slaStatus: $slaStatus, targetKind: $targetKind, eventId: $eventId);
            }
        }//end for

        return $slaStatus;
    }//end executeEscalations()

    /**
     * Pause the timer (REQ-003).
     *
     * @param array<string, mixed> $slaStatus The current slaStatus.
     * @param DateTimeImmutable    $now       The pause instant.
     *
     * @return array<string, mixed> The updated slaStatus.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-003
     */
    public function pauseTimer(array $slaStatus, DateTimeImmutable $now): array
    {
        if (($slaStatus['pausedAt'] ?? null) !== null) {
            return $slaStatus;
        }

        $slaStatus['pausedAt']        = $now->format('Y-m-d\TH:i:sP');
        $slaStatus['lastEvaluatedAt'] = $now->format('Y-m-d\TH:i:sP');
        return $slaStatus;
    }//end pauseTimer()

    /**
     * Resume the timer, extending each deadline by the paused working time (REQ-003).
     *
     * The paused window is converted to business minutes (per each target's own
     * calendar) so resumption extends deadlines by working time only.
     *
     * @param array<string, mixed> $slaStatus       The current slaStatus (paused).
     * @param DateTimeImmutable    $now             The resume instant.
     * @param string               $holidayCalendar The policy holiday calendar spec.
     *
     * @return array<string, mixed> The updated slaStatus.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-003
     */
    public function resumeTimer(array $slaStatus, DateTimeImmutable $now, string $holidayCalendar): array
    {
        $pausedAt = $this->toDate(value: ($slaStatus['pausedAt'] ?? null));
        if ($pausedAt === null) {
            return $slaStatus;
        }

        $wallMs = max(0, ($now->getTimestamp() - $pausedAt->getTimestamp()) * 1000);
        $slaStatus['totalPausedMs'] = (int) (($slaStatus['totalPausedMs'] ?? 0) + $wallMs);

        foreach (($slaStatus['targets'] ?? []) as $idx => $target) {
            if (($target['status'] ?? '') === 'met') {
                continue;
            }

            $due = $this->toDate(value: ($target['dueAt'] ?? null));
            if ($due === null) {
                continue;
            }

            $calendar      = (string) ($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_24X7);
            $pausedMinutes = $this->calculator->elapsedBusinessMinutes(
                calendarType: $calendar,
                startTime: $pausedAt,
                endTime: $now,
                holidayCalendar: $holidayCalendar
            );

            $newDue = $this->calculator->addDuration(
                calendarType: $calendar,
                startTime: $due,
                duration: new DateInterval('PT'.$pausedMinutes.'M'),
                holidayCalendar: $holidayCalendar
            );

            $slaStatus['targets'][$idx]['dueAt'] = $newDue->format('Y-m-d\TH:i:sP');
        }//end foreach

        $slaStatus['pausedAt']        = null;
        $slaStatus['lastEvaluatedAt'] = $now->format('Y-m-d\TH:i:sP');
        return $slaStatus;
    }//end resumeTimer()

    /**
     * Whether a status value is one of the policy's pause conditions.
     *
     * @param array<string, mixed> $policy The resolved policy.
     * @param string               $status The status value to test.
     *
     * @return bool True when the status pauses the timer.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-003
     */
    public function isPauseStatus(array $policy, string $status): bool
    {
        return in_array($status, ($policy['pauseConditions'] ?? []), true);
    }//end isPauseStatus()

    // ---------------------------------------------------------------------
    // Internal helpers.
    // ---------------------------------------------------------------------

    /**
     * Load all active, published policies from OpenRegister.
     *
     * @return array<int, array<string, mixed>> The policies.
     */
    private function loadPolicies(): array
    {
        [$register, $schema] = $this->registerAndSchema(slugKey: 'slaPolicy_schema');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $items = $this->objectService()->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema]]
            );
        } catch (Throwable $e) {
            $this->logger->error('SlaEngineService: failed to load policies', ['exception' => $e->getMessage()]);
            return [];
        }

        $policies = [];
        foreach ($items as $item) {
            $policies[] = $this->toArray(object: $item);
        }

        return $policies;
    }//end loadPolicies()

    /**
     * Test whether a policy matches the object type, tier, scope and validity window.
     *
     * @param array<string, mixed> $policy     The candidate policy.
     * @param string               $objectType The tracked object type.
     * @param string               $tier       The normalised customer tier.
     * @param array<string, mixed> $metadata   The resolution context.
     * @param DateTimeImmutable    $now        The evaluation instant.
     *
     * @return bool True when the policy matches.
     */
    private function policyMatches(array $policy, string $objectType, string $tier, array $metadata, DateTimeImmutable $now): bool
    {
        if (($policy['active'] ?? true) === false || ($policy['status'] ?? 'published') === 'archived') {
            return false;
        }

        $appliesTo = (string) ($policy['appliesTo'] ?? '');
        if ($appliesTo !== '*' && $this->appliesToMatches(appliesTo: $appliesTo, objectType: $objectType) === false) {
            return false;
        }

        $policyTier = (string) ($policy['customerTier'] ?? '*');
        if ($policyTier !== '*' && $policyTier !== $tier) {
            return false;
        }

        if ($this->withinValidity(policy: $policy, now: $now) === false) {
            return false;
        }

        return $this->scopeMatches(policy: $policy, metadata: $metadata);
    }//end policyMatches()

    /**
     * Match appliesTo, treating "klacht" and "complaint" as synonyms.
     *
     * @param string $appliesTo  The policy applies-to value.
     * @param string $objectType The tracked object type.
     *
     * @return bool True when they refer to the same workstream.
     */
    private function appliesToMatches(string $appliesTo, string $objectType): bool
    {
        if ($appliesTo === $objectType) {
            return true;
        }

        $klacht = ['klacht', 'complaint'];
        return (in_array($appliesTo, $klacht, true) === true && in_array($objectType, $klacht, true) === true);
    }//end appliesToMatches()

    /**
     * Check the policy's validFrom/validUntil window against now.
     *
     * @param array<string, mixed> $policy The candidate policy.
     * @param DateTimeImmutable    $now    The evaluation instant.
     *
     * @return bool True when now is inside the window.
     */
    private function withinValidity(array $policy, DateTimeImmutable $now): bool
    {
        $from = $this->toDate(value: ($policy['validFrom'] ?? null));
        if ($from !== null && $now < $from) {
            return false;
        }

        $until = $this->toDate(value: ($policy['validUntil'] ?? null));
        if ($until !== null && $now > $until) {
            return false;
        }

        return true;
    }//end withinValidity()

    /**
     * Check whether the policy's customerScope admits the object's customer.
     *
     * A null/empty scope matches all customers in the tier.
     *
     * @param array<string, mixed> $policy   The candidate policy.
     * @param array<string, mixed> $metadata The resolution context.
     *
     * @return bool True when the scope admits the customer.
     */
    private function scopeMatches(array $policy, array $metadata): bool
    {
        $scope = ($policy['customerScope'] ?? null);
        if (is_array($scope) === false || count($scope) === 0) {
            return true;
        }

        $contractIds     = ($scope['contractIds'] ?? []);
        $organisationIds = ($scope['organisationIds'] ?? []);
        $contractId      = (string) ($metadata['contractId'] ?? '');
        $organisationId  = (string) ($metadata['organisationId'] ?? '');

        if (is_array($contractIds) === true && count($contractIds) > 0) {
            return ($contractId !== '' && in_array($contractId, $contractIds, true) === true);
        }

        if (is_array($organisationIds) === true && count($organisationIds) > 0) {
            return ($organisationId !== '' && in_array($organisationId, $organisationIds, true) === true);
        }

        return true;
    }//end scopeMatches()

    /**
     * Order matched policies: most-specific scope, then priority, then newest validFrom.
     *
     * @param array<string, mixed> $first  The first policy.
     * @param array<string, mixed> $second The second policy.
     *
     * @return int Comparison result for usort.
     */
    private function comparePolicies(array $first, array $second): int
    {
        $scopeCmp = ($this->scopeSpecificity(policy: $second) <=> $this->scopeSpecificity(policy: $first));
        if ($scopeCmp !== 0) {
            return $scopeCmp;
        }

        $priorityCmp = ((int) ($first['priority'] ?? 100) <=> (int) ($second['priority'] ?? 100));
        if ($priorityCmp !== 0) {
            return $priorityCmp;
        }

        $aFrom = $this->toDate(value: ($first['validFrom'] ?? null));
        $bFrom = $this->toDate(value: ($second['validFrom'] ?? null));

        $aTs = 0;
        if ($aFrom !== null) {
            $aTs = $aFrom->getTimestamp();
        }

        $bTs = 0;
        if ($bFrom !== null) {
            $bTs = $bFrom->getTimestamp();
        }

        return ($bTs <=> $aTs);
    }//end comparePolicies()

    /**
     * Score a policy's scope specificity (contract beats organisation beats tier-wide).
     *
     * @param array<string, mixed> $policy The policy.
     *
     * @return int The specificity score (higher = more specific).
     */
    private function scopeSpecificity(array $policy): int
    {
        $scope = ($policy['customerScope'] ?? null);
        if (is_array($scope) === false) {
            return 0;
        }

        if (count(($scope['contractIds'] ?? [])) > 0) {
            return 2;
        }

        if (count(($scope['organisationIds'] ?? [])) > 0) {
            return 1;
        }

        return 0;
    }//end scopeSpecificity()

    /**
     * Compute the consumed fraction of a target window.
     *
     * @param DateTimeImmutable $started The start instant.
     * @param DateTimeImmutable $due     The deadline instant.
     * @param DateTimeImmutable $now     The evaluation instant.
     *
     * @return float The consumed fraction (0..n).
     */
    private function consumedFraction(DateTimeImmutable $started, DateTimeImmutable $due, DateTimeImmutable $now): float
    {
        $total = ($due->getTimestamp() - $started->getTimestamp());
        if ($total <= 0) {
            if ($now >= $due) {
                return 1.0;
            }

            return 0.0;
        }

        $elapsed = ($now->getTimestamp() - $started->getTimestamp());
        if ($elapsed <= 0) {
            return 0.0;
        }

        return ($elapsed / $total);
    }//end consumedFraction()

    /**
     * Map a consumed fraction to a status label.
     *
     * @param float $consumed The consumed fraction.
     *
     * @return string The status label.
     */
    private function statusForConsumption(float $consumed): string
    {
        if ($consumed >= 1.0) {
            return 'breached';
        }

        if ($consumed >= self::AT_RISK_THRESHOLD) {
            return 'at-risk';
        }

        return 'on-track';
    }//end statusForConsumption()

    /**
     * Highest consumption fraction across all non-met targets.
     *
     * @param array<string, mixed> $slaStatus The slaStatus.
     *
     * @return float The maximum consumed fraction.
     */
    private function highestConsumption(array $slaStatus): float
    {
        $max = 0.0;
        foreach (($slaStatus['targets'] ?? []) as $target) {
            if (($target['status'] ?? '') === 'met') {
                continue;
            }

            $max = max($max, (float) ($target['consumedPercentage'] ?? 0.0));
        }

        return $max;
    }//end highestConsumption()

    /**
     * Identify which target kind is driving an escalation at a ratio.
     *
     * Honours a step's optional `target` restriction; otherwise returns the kind
     * of the most-consumed non-met target.
     *
     * @param array<string, mixed> $slaStatus The slaStatus.
     * @param array<string, mixed> $step      The escalation step.
     *
     * @return string The driving target kind.
     */
    private function targetKindAtConsumption(array $slaStatus, array $step): string
    {
        $restrict = (string) ($step['target'] ?? '');
        $bestKind = '';
        $bestVal  = -1.0;
        foreach (($slaStatus['targets'] ?? []) as $target) {
            if (($target['status'] ?? '') === 'met') {
                continue;
            }

            $kind = (string) ($target['kind'] ?? '');
            if ($restrict !== '' && $restrict !== $kind) {
                continue;
            }

            $val = (float) ($target['consumedPercentage'] ?? 0.0);
            if ($val > $bestVal) {
                $bestVal  = $val;
                $bestKind = $kind;
            }
        }

        return $bestKind;
    }//end targetKindAtConsumption()

    /**
     * Compute the true crossing time for a threshold on the driving target.
     *
     * For a linear consumption model breachedAt = started + ratio * window. This
     * yields the moment the deadline fraction was actually reached rather than
     * the (later) detection time, as REQ-008 requires.
     *
     * @param array<string, mixed> $slaStatus The slaStatus.
     * @param float                $ratio     The trigger ratio.
     * @param DateTimeImmutable    $now       The detection instant (fallback).
     *
     * @return string The ISO 8601 crossing time.
     */
    private function breachTime(array $slaStatus, float $ratio, DateTimeImmutable $now): string
    {
        $started = $this->toDate(value: ($slaStatus['startedAt'] ?? null));
        $kind    = $this->targetKindAtConsumption(slaStatus: $slaStatus, step: []);

        foreach (($slaStatus['targets'] ?? []) as $target) {
            if ((string) ($target['kind'] ?? '') !== $kind) {
                continue;
            }

            $due = $this->toDate(value: ($target['dueAt'] ?? null));
            if ($started === null || $due === null) {
                break;
            }

            $window  = ($due->getTimestamp() - $started->getTimestamp());
            $crossTs = ($started->getTimestamp() + (int) round($window * $ratio));
            $cross   = (new DateTimeImmutable('@'.$crossTs))->setTimezone($now->getTimezone());

            // Never report a crossing in the future relative to detection.
            if ($cross > $now) {
                $cross = $now;
            }

            return $cross->format('Y-m-d\TH:i:sP');
        }//end foreach

        return $now->format('Y-m-d\TH:i:sP');
    }//end breachTime()

    /**
     * Persist an sla_breach_event audit record. Returns its id or null.
     *
     * @param string               $policyId        The policy UUID.
     * @param string               $objectType      The tracked object type.
     * @param string               $objectId        The tracked object UUID.
     * @param string               $targetKind      The breached target kind.
     * @param array<string, mixed> $breachContext   The breach context.
     * @param int                  $escalationLevel The 1-indexed escalation level.
     * @param array<int, string>   $notifiedActors  The notified actors.
     *
     * @return string|null The created breach-event id.
     */
    private function recordBreachEvent(
        string $policyId,
        string $objectType,
        string $objectId,
        string $targetKind,
        array $breachContext,
        int $escalationLevel,
        array $notifiedActors
    ): ?string {
        [$register, $schema] = $this->registerAndSchema(slugKey: 'slaBreachEvent_schema');
        if ($register === '' || $schema === '') {
            return null;
        }

        $data = [
            'policyId'           => $policyId,
            'targetObjectType'   => $objectType,
            'targetObjectId'     => $objectId,
            'targetKind'         => $targetKind,
            'breachedAt'         => (string) ($breachContext['breachedAt'] ?? ''),
            'consumedPercentage' => (float) ($breachContext['consumedPercentage'] ?? 0.0),
            'escalationLevel'    => $escalationLevel,
            'notifiedActors'     => array_values($notifiedActors),
            'acknowledged'       => false,
        ];

        try {
            $saved = $this->objectService()->saveObject($data, [], $register, $schema, null);
            $arr   = $this->toArray(object: $saved);
            $id    = ($arr['id'] ?? ($arr['@self']['id'] ?? null));
            if ($id === null) {
                return null;
            }

            return (string) $id;
        } catch (Throwable $e) {
            $this->logger->error('SlaEngineService: failed to record breach event', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end recordBreachEvent()

    /**
     * Attach a breach-event id to the matching target's breachEventIds.
     *
     * @param array<string, mixed> $slaStatus  The slaStatus.
     * @param string               $targetKind The breached target kind.
     * @param string               $eventId    The breach-event id.
     *
     * @return array<string, mixed> The updated slaStatus.
     */
    private function attachBreachEventId(array $slaStatus, string $targetKind, string $eventId): array
    {
        foreach (($slaStatus['targets'] ?? []) as $idx => $target) {
            if ((string) ($target['kind'] ?? '') === $targetKind) {
                $slaStatus['targets'][$idx]['breachEventIds'][] = $eventId;
                break;
            }
        }

        return $slaStatus;
    }//end attachBreachEventId()

    /**
     * Normalise a customer tier to a canonical value (empty => bronze).
     *
     * @param string $tier The raw tier value.
     *
     * @return string The normalised tier.
     */
    private function normaliseTier(string $tier): string
    {
        $tier = strtolower(trim($tier));
        if (in_array($tier, ['bronze', 'silver', 'gold', 'platinum'], true) === true) {
            return $tier;
        }

        return 'bronze';
    }//end normaliseTier()

    /**
     * Parse an ISO 8601 duration string to a DateInterval.
     *
     * @param string $spec The ISO 8601 duration (e.g. PT4H, P1D, P3W).
     *
     * @return DateInterval|null The interval, or null when invalid.
     */
    private function parseDuration(string $spec): ?DateInterval
    {
        $spec = trim($spec);
        if ($spec === '') {
            return null;
        }

        // DateInterval rejects week specifiers combined with others; PnW alone
        // is fine. Normalise a bare PnW to days for predictable math.
        if (preg_match('/^P(\d+)W$/', $spec, $weeks) === 1) {
            return new DateInterval('P'.((int) $weeks[1] * 7).'D');
        }

        try {
            return new DateInterval($spec);
        } catch (Exception $e) {
            return null;
        }
    }//end parseDuration()

    /**
     * Convert a value to a DateTimeImmutable, or null.
     *
     * @param mixed $value The candidate date value.
     *
     * @return DateTimeImmutable|null The parsed date or null.
     */
    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Exception $e) {
            return null;
        }
    }//end toDate()

    /**
     * Read the configured register id and a schema id by its config slug key.
     *
     * @param string $slugKey The `<slug>_schema` app-config key.
     *
     * @return array{0: string, 1: string} [register, schema] tuple.
     */
    private function registerAndSchema(string $slugKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $slugKey, '');

        return [$register, $schema];
    }//end registerAndSchema()

    /**
     * Lazily resolve the OpenRegister ObjectService.
     *
     * @return object The ObjectService instance.
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
    }//end objectService()

    /**
     * Normalise an ObjectEntity-or-array to a plain array.
     *
     * @param mixed $object The raw object.
     *
     * @return array<string, mixed> The array form.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return [];
    }//end toArray()
}//end class
