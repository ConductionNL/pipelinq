<?php

/**
 * Pipelinq SlaTrackingService.
 *
 * Orchestration layer between OpenRegister object events / the sweep job and the
 * pure SlaEngineService: resolves policies, computes and persists the embedded
 * slaStatus, and applies pause/resume + escalation on status change.
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
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives SLA tracking from OpenRegister events and the sweep job (REQ-007).
 *
 * Object types that are SLA-tracked are listed in `sla_tracked_types`
 * (app-config, default request+complaint). On creation a policy is resolved and
 * an slaStatus snapshot is computed; on update the timer pauses/resumes,
 * targets are re-evaluated, and escalations fire. All slaStatus mutation is
 * server-authoritative — client-supplied slaStatus on the incoming object is
 * always overwritten by the engine's computation.
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-007
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — create/update/pause/resume/
 * escalate orchestration over several tracked types; methods are individually small.
 */
class SlaTrackingService
{
    /**
     * Statuses considered "resolved" (target met) per tracked type.
     *
     * @var array<string, array<int, string>>
     */
    private const RESOLVED_STATUSES = [
        'request'   => ['completed', 'converted'],
        'complaint' => ['resolved', 'rejected'],
        'callback'  => ['afgerond'],
    ];

    /**
     * Default SLA-tracked object types.
     *
     * @var string
     */
    private const DEFAULT_TRACKED = 'request,complaint';

    /**
     * Object IDs currently being persisted by the engine.
     *
     * Guards against event re-entrancy: persisting slaStatus through
     * ObjectService re-emits ObjectUpdatedEvent, which would otherwise re-enter
     * onUpdated() and recurse. IDs are tracked for the duration of the save.
     *
     * @var array<string, bool>
     */
    private array $inFlight = [];

    /**
     * Constructor.
     *
     * @param SlaEngineService   $engine    The pure SLA engine.
     * @param ITimeFactory       $time      The time factory (deterministic clock source).
     * @param IAppConfig         $appConfig The app configuration.
     * @param ContainerInterface $container Container for ObjectService lookup.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private SlaEngineService $engine,
        private ITimeFactory $time,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether the given object type is SLA-tracked.
     *
     * @param string $objectType The tracked object type.
     *
     * @return bool True when tracked.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-007
     */
    public function isTracked(string $objectType): bool
    {
        $configured = $this->appConfig->getValueString(Application::APP_ID, 'sla_tracked_types', self::DEFAULT_TRACKED);
        $types      = array_values(array_filter(array_map('trim', explode(',', $configured))));
        return in_array($objectType, $types, true);
    }//end isTracked()

    /**
     * Initialise slaStatus on a newly created tracked object (REQ-001/REQ-007).
     *
     * Never throws: a failure is logged and the object save proceeds without an
     * slaStatus (the sweep job reconciles later).
     *
     * @param string               $objectType The tracked object type.
     * @param string               $objectId   The tracked object UUID.
     * @param array<string, mixed> $data       The created object data.
     *
     * @return array<string, mixed>|null The computed slaStatus, or null.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-001
     */
    public function onCreated(string $objectType, string $objectId, array $data): ?array
    {
        if (isset($this->inFlight[$objectId]) === true) {
            return null;
        }

        try {
            $now    = $this->now();
            $policy = $this->engine->resolvePolicyForObject(
                objectType: $objectType,
                metadata: $this->metadataFor(data: $data),
                now: $now
            );

            if ($policy === null) {
                return null;
            }

            $policyId  = (string) ($policy['id'] ?? ($policy['@self']['id'] ?? ($policy['name'] ?? '')));
            $slaStatus = $this->engine->buildInitialStatus(policy: $policy, policyId: $policyId, startTime: $now);

            $this->persist(objectType: $objectType, objectId: $objectId, data: $data, slaStatus: $slaStatus);
            return $slaStatus;
        } catch (Throwable $e) {
            $this->logger->error(
                'SlaTrackingService: onCreated failed (non-blocking)',
                ['objectType' => $objectType, 'objectId' => $objectId, 'exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end onCreated()

    /**
     * Re-evaluate slaStatus on a tracked object update (REQ-003/REQ-004/REQ-007).
     *
     * Applies pause/resume on entering/leaving a pause-condition status, then
     * re-evaluates targets and fires any newly-crossed escalations. Never throws.
     *
     * @param string               $objectType The tracked object type.
     * @param string               $objectId   The tracked object UUID.
     * @param array<string, mixed> $newData    The updated object data.
     * @param array<string, mixed> $oldData    The prior object data.
     *
     * @return array<string, mixed>|null The updated slaStatus, or null.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-003
     */
    public function onUpdated(string $objectType, string $objectId, array $newData, array $oldData): ?array
    {
        if (isset($this->inFlight[$objectId]) === true) {
            return null;
        }

        try {
            $slaStatus = ($newData['slaStatus'] ?? null);
            if (is_array($slaStatus) === false) {
                // No tracking yet (e.g. created before the engine, or resolution
                // failed) — try to initialise now.
                return $this->onCreated(objectType: $objectType, objectId: $objectId, data: $newData);
            }

            $policy = $this->reloadPolicy(slaStatus: $slaStatus);
            if ($policy === null) {
                return null;
            }

            $now       = $this->now();
            $slaStatus = $this->applyStatusTransition(
                policy: $policy,
                slaStatus: $slaStatus,
                newData: $newData,
                oldData: $oldData,
                now: $now
            );

            $resolved  = $this->isResolved(objectType: $objectType, status: (string) ($newData['status'] ?? ''));
            $slaStatus = $this->engine->evaluateTargets(slaStatus: $slaStatus, now: $now, resolved: $resolved);

            $policyId  = (string) ($slaStatus['policyId'] ?? '');
            $slaStatus = $this->engine->executeEscalations(
                policy: $policy,
                policyId: $policyId,
                objectType: $objectType,
                objectId: $objectId,
                objectData: $newData,
                slaStatus: $slaStatus,
                now: $now
            );

            $this->persist(objectType: $objectType, objectId: $objectId, data: $newData, slaStatus: $slaStatus);
            return $slaStatus;
        } catch (Throwable $e) {
            $this->logger->error(
                'SlaTrackingService: onUpdated failed (non-blocking)',
                ['objectType' => $objectType, 'objectId' => $objectId, 'exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end onUpdated()

    /**
     * Reconcile a single object during the sweep (silent deadline crossing).
     *
     * Returns the updated slaStatus when a change occurred (so the caller can
     * batch the save), or null when nothing changed.
     *
     * @param string               $objectType The tracked object type.
     * @param string               $objectId   The tracked object UUID.
     * @param array<string, mixed> $data       The object data (incl. slaStatus).
     *
     * @return array<string, mixed>|null The updated slaStatus, or null.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-008
     */
    public function reconcile(string $objectType, string $objectId, array $data): ?array
    {
        if (isset($this->inFlight[$objectId]) === true) {
            return null;
        }

        $slaStatus = ($data['slaStatus'] ?? null);
        if (is_array($slaStatus) === false) {
            return null;
        }

        $policy = $this->reloadPolicy(slaStatus: $slaStatus);
        if ($policy === null) {
            return null;
        }

        $now       = $this->now();
        $before    = $slaStatus;
        $resolved  = $this->isResolved(objectType: $objectType, status: (string) ($data['status'] ?? ''));
        $slaStatus = $this->engine->evaluateTargets(slaStatus: $slaStatus, now: $now, resolved: $resolved);
        $policyId  = (string) ($slaStatus['policyId'] ?? '');
        $slaStatus = $this->engine->executeEscalations(
            policy: $policy,
            policyId: $policyId,
            objectType: $objectType,
            objectId: $objectId,
            objectData: $data,
            slaStatus: $slaStatus,
            now: $now
        );

        if ($this->statusUnchanged(before: $before, after: $slaStatus) === true) {
            return null;
        }

        // Persist so list badges and escalation level stay accurate; the
        // re-entrancy guard prevents the resulting event from re-processing.
        $this->persist(objectType: $objectType, objectId: $objectId, data: $data, slaStatus: $slaStatus);
        return $slaStatus;
    }//end reconcile()

    /**
     * Whether a reconciliation produced no material change worth persisting.
     *
     * @param array<string, mixed> $before The pre-reconcile slaStatus.
     * @param array<string, mixed> $after  The post-reconcile slaStatus.
     *
     * @return bool True when escalation level and all target statuses are unchanged.
     */
    private function statusUnchanged(array $before, array $after): bool
    {
        if ((int) ($before['currentEscalationLevel'] ?? 0) !== (int) ($after['currentEscalationLevel'] ?? 0)) {
            return false;
        }

        $beforeTargets = ($before['targets'] ?? []);
        $afterTargets  = ($after['targets'] ?? []);
        foreach ($afterTargets as $idx => $target) {
            $priorStatus = ($beforeTargets[$idx]['status'] ?? null);
            if (($target['status'] ?? null) !== $priorStatus) {
                return false;
            }
        }

        return true;
    }//end statusUnchanged()

    /**
     * Apply pause/resume based on the status field crossing a pause condition.
     *
     * @param array<string, mixed> $policy    The resolved policy.
     * @param array<string, mixed> $slaStatus The current slaStatus.
     * @param array<string, mixed> $newData   The updated object data.
     * @param array<string, mixed> $oldData   The prior object data.
     * @param DateTimeImmutable    $now       The evaluation instant.
     *
     * @return array<string, mixed> The (possibly) paused/resumed slaStatus.
     */
    private function applyStatusTransition(
        array $policy,
        array $slaStatus,
        array $newData,
        array $oldData,
        DateTimeImmutable $now
    ): array {
        $newStatus = (string) ($newData['status'] ?? '');
        $oldStatus = (string) ($oldData['status'] ?? '');
        if ($newStatus === $oldStatus) {
            return $slaStatus;
        }

        $wasPaused = $this->engine->isPauseStatus(policy: $policy, status: $oldStatus);
        $isPaused  = $this->engine->isPauseStatus(policy: $policy, status: $newStatus);

        if ($isPaused === true && $wasPaused === false) {
            return $this->engine->pauseTimer(slaStatus: $slaStatus, now: $now);
        }

        if ($isPaused === false && $wasPaused === true) {
            return $this->engine->resumeTimer(
                slaStatus: $slaStatus,
                now: $now,
                holidayCalendar: (string) ($policy['holidayCalendar'] ?? 'none')
            );
        }

        return $slaStatus;
    }//end applyStatusTransition()

    /**
     * Reload the bound policy for an object by its snapshotted policyId.
     *
     * @param array<string, mixed> $slaStatus The slaStatus holding policyId.
     *
     * @return array<string, mixed>|null The policy, or null.
     */
    private function reloadPolicy(array $slaStatus): ?array
    {
        $policyId = (string) ($slaStatus['policyId'] ?? '');
        if ($policyId === '') {
            return null;
        }

        [$register, $schema] = $this->registerAndSchema(slugKey: 'slaPolicy_schema');
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $object = $this->objectService()->find($policyId, [], false, $register, $schema);
            if ($object === null) {
                // Fall back to a slug match for seed policies whose UUID differs.
                return $this->findPolicyBySlug(register: $register, schema: $schema, slug: $policyId);
            }

            return $this->toArray(object: $object);
        } catch (Throwable $e) {
            $this->logger->warning('SlaTrackingService: policy reload failed', ['policyId' => $policyId, 'exception' => $e->getMessage()]);
            return null;
        }
    }//end reloadPolicy()

    /**
     * Find a policy by slug (fallback when the snapshot stored a slug).
     *
     * @param string $register The register id.
     * @param string $schema   The schema id.
     * @param string $slug     The candidate slug or name.
     *
     * @return array<string, mixed>|null The policy, or null.
     */
    private function findPolicyBySlug(string $register, string $schema, string $slug): ?array
    {
        try {
            $items = $this->objectService()->findAll(['filters' => ['register' => $register, 'schema' => $schema]]);
        } catch (Throwable $e) {
            return null;
        }

        foreach ($items as $item) {
            $arr = $this->toArray(object: $item);
            if ((string) ($arr['@self']['slug'] ?? '') === $slug || (string) ($arr['name'] ?? '') === $slug) {
                return $arr;
            }
        }

        return null;
    }//end findPolicyBySlug()

    /**
     * Build the resolution metadata for an object's data.
     *
     * @param array<string, mixed> $data The object data.
     *
     * @return array<string, mixed> The resolution metadata.
     */
    private function metadataFor(array $data): array
    {
        return [
            'customerTier'   => (string) ($data['slaTier'] ?? ''),
            'organisationId' => (string) ($data['client'] ?? ''),
            'contractId'     => (string) ($data['contractId'] ?? ''),
        ];
    }//end metadataFor()

    /**
     * Whether a status counts as resolved for the tracked type.
     *
     * @param string $objectType The tracked object type.
     * @param string $status     The current status.
     *
     * @return bool True when resolved.
     */
    private function isResolved(string $objectType, string $status): bool
    {
        $resolved = (self::RESOLVED_STATUSES[$objectType] ?? []);
        return in_array($status, $resolved, true);
    }//end isResolved()

    /**
     * Persist the slaStatus back onto the tracked object via ObjectService.
     *
     * @param string               $objectType The tracked object type.
     * @param string               $objectId   The tracked object UUID.
     * @param array<string, mixed> $data       The object data.
     * @param array<string, mixed> $slaStatus  The slaStatus to embed.
     *
     * @return void
     */
    private function persist(string $objectType, string $objectId, array $data, array $slaStatus): void
    {
        [$register, $schema] = $this->trackedRegisterAndSchema(objectType: $objectType);
        if ($register === '' || $schema === '') {
            return;
        }

        $data['slaStatus'] = $slaStatus;
        $data['id']        = $objectId;

        $this->inFlight[$objectId] = true;
        try {
            $this->objectService()->saveObject($data, [], $register, $schema, $objectId);
        } catch (Throwable $e) {
            $this->logger->error('SlaTrackingService: persist failed', ['objectId' => $objectId, 'exception' => $e->getMessage()]);
        } finally {
            unset($this->inFlight[$objectId]);
        }
    }//end persist()

    /**
     * Resolve the register + schema id for a tracked object type.
     *
     * @param string $objectType The tracked object type.
     *
     * @return array{0: string, 1: string} [register, schema] tuple.
     */
    private function trackedRegisterAndSchema(string $objectType): array
    {
        $map = ['request' => 'request_schema', 'complaint' => 'complaint_schema'];
        $key = ($map[$objectType] ?? ($objectType.'_schema'));
        return $this->registerAndSchema(slugKey: $key);
    }//end trackedRegisterAndSchema()

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
     * Current time as an immutable UTC instant.
     *
     * @return DateTimeImmutable The current instant.
     */
    private function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($this->time->getDateTime()->getTimestamp());
    }//end now()

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
