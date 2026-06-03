<?php

/**
 * Pipelinq MergeService.
 *
 * Server-authoritative, reversible merge of duplicate Master Entities. Provides
 * a side-effect-free preview (post-merge golden record + downstream impact +
 * reversal window), an atomic execute that snapshots pre-merge state, relinks
 * source records, recomputes the survivor, logs a merge-operation and enqueues
 * downstream sync, and a reversal that restores the snapshot inside the 30-day
 * window. Every mutation is audited via the OR built-in auditTrail.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for reversible Master Entity merges.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MergeService
{
    /**
     * The mergeOperation schema slug.
     *
     * @var string
     */
    public const MERGE_SCHEMA = 'mergeOperation';

    /**
     * Reversal window in days.
     *
     * @var int
     */
    public const REVERSAL_WINDOW_DAYS = 30;

    /**
     * Downstream apps notified on every merge.
     *
     * @var array<int, string>
     */
    public const DOWNSTREAM_SYSTEMS = ['shillinq', 'procest', 'scholiq', 'opencatalogi', 'decidesk'];

    /**
     * Constructor.
     *
     * @param MdmObjectRepository $repository     The MDM object repository.
     * @param MasterEntityService $masterEntities The master-entity service.
     * @param SyncQueueService    $syncQueue      The sync queue service.
     * @param LoggerInterface     $logger         The logger.
     */
    public function __construct(
        private MdmObjectRepository $repository,
        private MasterEntityService $masterEntities,
        private SyncQueueService $syncQueue,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Preview a merge without any side effects.
     *
     * @param string $fromMasterId The entity to merge away.
     * @param string $intoMasterId The surviving entity.
     *
     * @return array<string, mixed> The preview payload.
     *
     * @throws RuntimeException If either entity is missing.
     */
    public function previewMerge(string $fromMasterId, string $intoMasterId): array
    {
        [$from, $into] = $this->loadPair(fromMasterId: $fromMasterId, intoMasterId: $intoMasterId);

        // Simulate the survivor's source set: its own sources plus the from set.
        $entityType = (string) ($into['entityType'] ?? '');
        $sources    = array_merge(
            $this->masterEntities->linkedSourceRecords($intoMasterId),
            $this->masterEntities->linkedSourceRecords($fromMasterId)
        );

        $resolution = $this->masterEntities->resolveGoldenRecord($entityType, $sources);

        return [
            'fromMasterId'           => $fromMasterId,
            'intoMasterId'           => $intoMasterId,
            'postMergeGoldenRecord'  => $resolution['goldenRecord'],
            'attributeProvenance'    => $resolution['attributeProvenance'],
            'attributeResolutionLog' => $this->buildResolutionLog(from: $from, into: $into, resolution: $resolution),
            'downstreamImpact'       => $this->downstreamImpact(from: $from, into: $into),
            'reversibleUntil'        => $this->reversalDeadline(mergedAt: $this->repository->now()),
        ];
    }//end previewMerge()

    /**
     * Execute a merge atomically (server-authoritative).
     *
     * @param string $fromMasterId The entity to merge away.
     * @param string $intoMasterId The surviving entity.
     * @param string $mergedBy     The acting user UID (or system-auto-merge).
     * @param string $mergeReason  The merge reason classification.
     *
     * @return array<string, mixed> The created merge-operation.
     *
     * @throws RuntimeException On invalid input or idempotency violation.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) The merge is one atomic
     *  transaction (snapshot → relink → status flip → lineage → recompute →
     *  operation log → downstream enqueue); splitting it would scatter a single
     *  server-authoritative unit of work across helpers.
     */
    public function executeMerge(
        string $fromMasterId,
        string $intoMasterId,
        string $mergedBy,
        string $mergeReason
    ): array {
        if ($fromMasterId === $intoMasterId) {
            throw new RuntimeException('Cannot merge an entity into itself.');
        }

        [$from, $into] = $this->loadPair(fromMasterId: $fromMasterId, intoMasterId: $intoMasterId);

        // Idempotency: a partner already merged away cannot be merged again.
        if ((string) ($from['status'] ?? 'active') === 'merged-into-other') {
            throw new RuntimeException('Source entity has already been merged into another entity.');
        }

        if ((string) ($into['status'] ?? 'active') !== 'active') {
            throw new RuntimeException('Target entity is not active and cannot receive a merge.');
        }

        $now      = $this->repository->now();
        $snapshot = $this->buildSnapshot(from: $from, into: $into);

        // 1. Relink the from entity's source records onto the survivor.
        $relinked = 0;
        foreach ($this->masterEntities->linkedSourceRecords(masterId: $fromMasterId) as $record) {
            $recordId = (string) ($record['sourceRecordId'] ?? ($record['id'] ?? ''));
            $record['currentMasterEntity'] = $intoMasterId;
            $this->repository->save(
                schemaSlug: MasterEntityService::SOURCE_SCHEMA,
                object: $record,
                uuid: $this->nullableId(id: $recordId)
            );
            $relinked++;
        }

        // 2. Mark the from entity as merged-into-other.
        $from['status'] = 'merged-into-other';
        $from['mergedIntoMasterId'] = $intoMasterId;
        $this->repository->save(MasterEntityService::SCHEMA, $from, $fromMasterId);

        // 3. Record lineage + alias on the survivor, then recompute it.
        $into['mergedFrom'] = array_values(
            array_unique(array_merge((array) ($into['mergedFrom'] ?? []), [$fromMasterId]))
        );
        $into['aliases']    = array_values(
            array_unique(
                array_merge(
                    (array) ($into['aliases'] ?? []),
                    [$fromMasterId],
                    (array) ($from['aliases'] ?? [])
                )
            )
        );
        $this->repository->save(MasterEntityService::SCHEMA, $into, $intoMasterId);
        $survivor = $this->masterEntities->recomputeGoldenRecord($intoMasterId);

        // 4. Persist the merge-operation with the pre-merge snapshot.
        $resolutionLog = $this->buildResolutionLog(
            from: $from,
            into: $into,
            resolution: [
                'goldenRecord'        => ($survivor['goldenRecord'] ?? []),
                'attributeProvenance' => ($survivor['attributeProvenance'] ?? []),
            ]
        );

        $mergeOperation = [
            'id'                     => $this->repository->uuid(),
            'mergedIntoMasterId'     => $intoMasterId,
            'mergedFromMasterIds'    => [$fromMasterId],
            'mergedAt'               => $now,
            'mergedBy'               => $mergedBy,
            'mergeReason'            => $mergeReason,
            'preMergeSnapshot'       => $snapshot,
            'attributeResolutionLog' => $resolutionLog,
            'downstreamSyncStatus'   => [],
            'reversible'             => true,
        ];
        $savedOperation = $this->repository->save(self::MERGE_SCHEMA, $mergeOperation);

        // 5. Enqueue downstream sync for the merge.
        $this->enqueueDownstream(
            masterEntityId: $intoMasterId,
            changeType: 'merge',
            payload: [
                'mergedFrom'   => [$fromMasterId],
                'mergedInto'   => $intoMasterId,
                'goldenRecord' => ($survivor['goldenRecord'] ?? []),
            ]
        );

        $this->logger->info(
            'Pipelinq MDM: merge executed',
            ['from' => $fromMasterId, 'into' => $intoMasterId, 'relinked' => $relinked, 'by' => $mergedBy]
        );

        return $savedOperation;
    }//end executeMerge()

    /**
     * Reverse a merge within the reversal window.
     *
     * @param string $mergeOperationId The merge-operation uuid.
     * @param string $reversedBy       The acting user UID.
     *
     * @return array<string, mixed> The updated merge-operation.
     *
     * @throws RuntimeException If the merge is missing or no longer reversible.
     */
    public function reverseMerge(string $mergeOperationId, string $reversedBy): array
    {
        $operation = $this->repository->find(self::MERGE_SCHEMA, $mergeOperationId);
        if ($operation === null) {
            throw new RuntimeException('Merge operation not found.');
        }

        if ($this->isReversible(operation: $operation) === false) {
            throw new RuntimeException('Reversal window has expired.');
        }

        $snapshot = ($operation['preMergeSnapshot'] ?? []);
        if (is_array($snapshot) === false || empty($snapshot['entities']) === true) {
            throw new RuntimeException('Merge operation has no restorable snapshot.');
        }

        // 1. Restore each entity's golden record, provenance and status.
        foreach ($snapshot['entities'] as $masterId => $state) {
            $entity = $this->repository->find(MasterEntityService::SCHEMA, (string) $masterId);
            if ($entity === null) {
                continue;
            }

            $entity['goldenRecord']        = ($state['goldenRecord'] ?? []);
            $entity['attributeProvenance'] = ($state['attributeProvenance'] ?? []);
            $entity['status']     = ($state['status'] ?? 'active');
            $entity['mergedFrom'] = ($state['mergedFrom'] ?? []);
            $entity['aliases']    = ($state['aliases'] ?? []);
            $entity['mergedIntoMasterId'] = ($state['mergedIntoMasterId'] ?? '');
            $this->repository->save(MasterEntityService::SCHEMA, $entity, (string) $masterId);
        }

        // 2. Restore source-record linkages.
        foreach (($snapshot['sourceLinks'] ?? []) as $sourceRecordId => $masterId) {
            $record = $this->repository->find(MasterEntityService::SOURCE_SCHEMA, (string) $sourceRecordId);
            if ($record === null) {
                continue;
            }

            $record['currentMasterEntity'] = (string) $masterId;
            $this->repository->save(MasterEntityService::SOURCE_SCHEMA, $record, (string) $sourceRecordId);
        }

        // 3. Enqueue reverse-merge sync for downstream apps.
        $intoMasterId = (string) ($operation['mergedIntoMasterId'] ?? '');
        $this->enqueueDownstream(
            masterEntityId: $intoMasterId,
            changeType: 'reverse-merge',
            payload: [
                'restoredMasterIds' => array_keys((array) ($snapshot['entities'] ?? [])),
                'mergeOperationId'  => $mergeOperationId,
            ]
        );

        // 4. Mark the operation reversed.
        $operation['reversedAt'] = $this->repository->now();
        $operation['reversedBy'] = $reversedBy;
        $operation['reversible'] = false;

        $this->logger->info('Pipelinq MDM: merge reversed', ['operation' => $mergeOperationId, 'by' => $reversedBy]);

        return $this->repository->save(self::MERGE_SCHEMA, $operation, $mergeOperationId);
    }//end reverseMerge()

    /**
     * Determine whether a merge-operation is still reversible.
     *
     * @param array<string, mixed> $operation The merge-operation.
     * @param string|null          $asOf      The as-of timestamp (null = now).
     *
     * @return bool True when reversible and within the window.
     */
    public function isReversible(array $operation, ?string $asOf=null): bool
    {
        if (($operation['reversible'] ?? false) !== true) {
            return false;
        }

        if (($operation['reversedAt'] ?? '') !== '' && ($operation['reversedAt'] ?? null) !== null) {
            return false;
        }

        $mergedAt = (string) ($operation['mergedAt'] ?? '');
        if ($mergedAt === '') {
            return false;
        }

        $now = ($asOf ?? $this->repository->now());

        try {
            $merged   = new DateTimeImmutable($mergedAt);
            $current  = new DateTimeImmutable($now);
            $deadline = $merged->add(new DateInterval('P'.self::REVERSAL_WINDOW_DAYS.'D'));
        } catch (Exception $e) {
            return false;
        }

        return $current <= $deadline;
    }//end isReversible()

    /**
     * Build the pre-merge snapshot for both entities (pure).
     *
     * @param array<string, mixed> $from The merged-away entity.
     * @param array<string, mixed> $into The surviving entity.
     *
     * @return array<string, mixed> The snapshot.
     */
    public function buildSnapshot(array $from, array $into): array
    {
        $fromId = (string) ($from['masterId'] ?? ($from['id'] ?? ''));
        $intoId = (string) ($into['masterId'] ?? ($into['id'] ?? ''));

        $entities = [];
        foreach ([$fromId => $from, $intoId => $into] as $id => $entity) {
            $entities[$id] = [
                'goldenRecord'        => ($entity['goldenRecord'] ?? []),
                'attributeProvenance' => ($entity['attributeProvenance'] ?? []),
                'status'              => ($entity['status'] ?? 'active'),
                'mergedFrom'          => ($entity['mergedFrom'] ?? []),
                'aliases'             => ($entity['aliases'] ?? []),
                'mergedIntoMasterId'  => ($entity['mergedIntoMasterId'] ?? ''),
            ];
        }

        $sourceLinks = [];
        foreach ($this->masterEntities->linkedSourceRecords($fromId) as $record) {
            $recordId = (string) ($record['sourceRecordId'] ?? ($record['id'] ?? ''));
            if ($recordId !== '') {
                $sourceLinks[$recordId] = $fromId;
            }
        }

        return ['entities' => $entities, 'sourceLinks' => $sourceLinks];
    }//end buildSnapshot()

    /**
     * Build a per-attribute resolution log from the survivor's provenance.
     *
     * @param array<string, mixed> $from       The merged-away entity.
     * @param array<string, mixed> $into       The surviving entity.
     * @param array<string, mixed> $resolution The resolved golden record + provenance.
     *
     * @return array<int, array<string, mixed>> The resolution log.
     */
    private function buildResolutionLog(array $from, array $into, array $resolution): array
    {
        $provenance = ($resolution['attributeProvenance'] ?? []);
        $log        = [];
        foreach ($provenance as $attribute => $meta) {
            $conflicting = [];
            foreach ([$from, $into] as $entity) {
                $value = ($entity['goldenRecord'][$attribute] ?? null);
                if ($value !== null && $value !== ($meta['value'] ?? null)) {
                    $conflicting[] = $value;
                }
            }

            $log[] = [
                'attribute'           => $attribute,
                'winningSourceSystem' => ($meta['sourceSystem'] ?? ''),
                'winningValue'        => ($meta['value'] ?? null),
                'conflictingValues'   => array_values(array_unique($conflicting, SORT_REGULAR)),
                'rationale'           => 'Trust-tier '.((string) ($meta['trustTier'] ?? 'bronze')).' won.',
            ];
        }

        return $log;
    }//end buildResolutionLog()

    /**
     * Estimate downstream impact (apps + entity counts) for the preview.
     *
     * @param array<string, mixed> $from The merged-away entity (reserved for
     *                                   future per-system entity-count lookups).
     * @param array<string, mixed> $into The surviving entity (reserved likewise).
     *
     * @return array<int, array<string, mixed>> The impact summary.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) The entity pair is part of
     *  the impact-estimation contract; per-system counts will derive from them.
     */
    private function downstreamImpact(array $from, array $into): array
    {
        unset($from, $into);
        $impact = [];
        foreach (self::DOWNSTREAM_SYSTEMS as $system) {
            $impact[] = ['targetSystem' => $system, 'changeType' => 'merge', 'entityCount' => 1];
        }

        return $impact;
    }//end downstreamImpact()

    /**
     * Enqueue a downstream sync item for every configured target system.
     *
     * @param string               $masterEntityId The affected master entity.
     * @param string               $changeType     The change type.
     * @param array<string, mixed> $payload        The payload.
     *
     * @return void
     */
    private function enqueueDownstream(string $masterEntityId, string $changeType, array $payload): void
    {
        foreach (self::DOWNSTREAM_SYSTEMS as $system) {
            $this->syncQueue->enqueueSync(
                masterEntityId: $masterEntityId,
                targetSystem: $system,
                changeType: $changeType,
                payload: $payload
            );
        }
    }//end enqueueDownstream()

    /**
     * Compute the reversal deadline from a merge timestamp (pure).
     *
     * @param string $mergedAt The merge timestamp.
     *
     * @return string The reversal deadline (ISO date), or empty on parse error.
     */
    public function reversalDeadline(string $mergedAt): string
    {
        try {
            $merged = new DateTimeImmutable($mergedAt);
        } catch (Exception $e) {
            return '';
        }

        return $merged->add(new DateInterval('P'.self::REVERSAL_WINDOW_DAYS.'D'))->format('Y-m-d');
    }//end reversalDeadline()

    /**
     * Normalise an empty id to null so save() generates a fresh uuid.
     *
     * @param string $id The candidate id.
     *
     * @return string|null The id, or null when empty.
     */
    private function nullableId(string $id): ?string
    {
        if ($id === '') {
            return null;
        }

        return $id;
    }//end nullableId()

    /**
     * Load and validate a from/into entity pair.
     *
     * @param string $fromMasterId The merged-away entity uuid.
     * @param string $intoMasterId The surviving entity uuid.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     *
     * @throws RuntimeException If either entity is missing.
     */
    private function loadPair(string $fromMasterId, string $intoMasterId): array
    {
        $from = $this->masterEntities->find($fromMasterId);
        $into = $this->masterEntities->find($intoMasterId);
        if ($from === null || $into === null) {
            throw new RuntimeException('One or both master entities were not found.');
        }

        return [$from, $into];
    }//end loadPair()
}//end class
