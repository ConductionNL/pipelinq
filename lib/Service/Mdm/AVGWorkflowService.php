<?php

/**
 * Pipelinq AVGWorkflowService.
 *
 * Orchestrates the AVG (GDPR art. 17) right-of-deletion workflow over a Master
 * Entity: steward-gated initiation, an atomic approve-and-execute that
 * soft-deletes the entity, anonymises every linked source record, queues
 * soft-delete sync to downstream apps and redacts attribute values in the audit
 * trail while preserving event structure for legal provability, and a hard
 * delete after the 30-day cooling-off period.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for the AVG right-of-deletion workflow.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AVGWorkflowService
{
    /**
     * Redaction token written over anonymised attribute values.
     *
     * @var string
     */
    public const REDACTED = '[verwijderd]';

    /**
     * Cooling-off period (days) before a hard delete is permitted.
     *
     * @var int
     */
    public const COOLING_OFF_DAYS = 30;

    /**
     * Downstream apps notified on a right-of-deletion.
     *
     * @var array<int, string>
     */
    private const DOWNSTREAM_SYSTEMS = ['shillinq', 'procest', 'scholiq', 'opencatalogi', 'decidesk'];

    /**
     * Constructor.
     *
     * @param MdmObjectRepository $repository The MDM object repository (also the
     *                                        re-homed master-entity read helpers).
     * @param SyncQueueService    $syncQueue  The sync queue service.
     * @param LoggerInterface     $logger     The logger.
     */
    public function __construct(
        private MdmObjectRepository $repository,
        private SyncQueueService $syncQueue,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Initiate a right-of-deletion request (records intent for steward review).
     *
     * @param string $masterEntityId The target master entity.
     * @param string $gdprRequestId  The external GDPR request reference.
     *
     * @return array<string, mixed> The pending-review request summary.
     *
     * @throws RuntimeException If the entity is missing.
     */
    public function initiateRightOfDeletion(string $masterEntityId, string $gdprRequestId): array
    {
        $entity = $this->repository->findMasterEntity($masterEntityId);
        if ($entity === null) {
            throw new RuntimeException('Master entity not found.');
        }

        $sources = $this->repository->linkedSourceRecords($masterEntityId);
        $now     = $this->repository->now();

        $note = sprintf('[%s] AVG right-of-deletion requested (request %s); pending steward review.', $now, $gdprRequestId);
        $entity['gdprNotes'] = trim((string) ($entity['gdprNotes'] ?? '')."\n".$note);
        $this->repository->save(MdmObjectRepository::SCHEMA_MASTER_ENTITY, $entity, $masterEntityId);

        $this->logger->info(
            'Pipelinq MDM: AVG right-of-deletion initiated',
            ['master' => $masterEntityId, 'request' => $gdprRequestId, 'sources' => count($sources)]
        );

        return [
            'masterEntityId'    => $masterEntityId,
            'gdprRequestId'     => $gdprRequestId,
            'status'            => 'pending-review',
            'sourceRecordCount' => count($sources),
            'requestedAt'       => $now,
        ];
    }//end initiateRightOfDeletion()

    /**
     * Approve and execute a right-of-deletion (atomic, server-authoritative).
     *
     * @param string $masterEntityId The target master entity.
     * @param string $gdprRequestId  The GDPR request reference.
     * @param string $approvedBy     The approving steward UID.
     *
     * @return array<string, mixed> The execution summary.
     *
     * @throws RuntimeException If the entity is missing.
     */
    public function approveAndExecuteRightOfDeletion(
        string $masterEntityId,
        string $gdprRequestId,
        string $approvedBy
    ): array {
        $entity = $this->repository->findMasterEntity($masterEntityId);
        if ($entity === null) {
            throw new RuntimeException('Master entity not found.');
        }

        $now     = $this->repository->now();
        $sources = $this->repository->linkedSourceRecords($masterEntityId);

        // 1. Anonymise every linked source record.
        $anonymised = 0;
        foreach ($sources as $record) {
            $recordId = (string) ($record['sourceRecordId'] ?? ($record['id'] ?? ''));
            $record['rawAttributes']    = $this->redactMap(map: $record['rawAttributes'] ?? []);
            $record['mappedAttributes'] = $this->redactMap(map: $record['mappedAttributes'] ?? []);
            $record['withdrawn']        = true;
            $this->repository->save(
                schemaSlug: MdmObjectRepository::SCHEMA_SOURCE_RECORD,
                object: $record,
                uuid: $this->nullableId(id: $recordId)
            );
            $anonymised++;
        }

        // 2. Soft-delete the master entity and redact its golden record.
        $entity['goldenRecord']        = $this->redactMap(map: $entity['goldenRecord'] ?? []);
        $entity['attributeProvenance'] = $this->redactProvenance(provenance: $entity['attributeProvenance'] ?? []);
        $entity['status'] = 'soft-deleted';
        $note = sprintf(
            '[%s] AVG right-of-deletion executed by %s (request %s). Source records anonymised: %d. Hard delete eligible after %s.',
            $now,
            $approvedBy,
            $gdprRequestId,
            $anonymised,
            $this->hardDeleteEligibleAt(softDeletedAt: $now)
        );
        $entity['gdprNotes'] = trim((string) ($entity['gdprNotes'] ?? '')."\n".$note);
        $this->repository->save(MdmObjectRepository::SCHEMA_MASTER_ENTITY, $entity, $masterEntityId);

        // 3. Queue soft-delete sync for every downstream app.
        foreach (self::DOWNSTREAM_SYSTEMS as $system) {
            $this->syncQueue->enqueueSync(
                masterEntityId: $masterEntityId,
                targetSystem: $system,
                changeType: 'soft-delete',
                payload: ['gdprRequestId' => $gdprRequestId, 'reason' => 'right-of-deletion']
            );
        }

        $this->logger->info(
            'Pipelinq MDM: AVG right-of-deletion executed',
            ['master' => $masterEntityId, 'request' => $gdprRequestId, 'by' => $approvedBy, 'anonymised' => $anonymised]
        );

        return [
            'masterEntityId'    => $masterEntityId,
            'gdprRequestId'     => $gdprRequestId,
            'status'            => 'soft-deleted',
            'anonymisedSources' => $anonymised,
            'executedAt'        => $now,
            'hardDeleteAt'      => $this->hardDeleteEligibleAt(softDeletedAt: $now),
        ];
    }//end approveAndExecuteRightOfDeletion()

    /**
     * Confirm and execute the hard delete after the cooling-off period.
     *
     * @param string $masterEntityId The target master entity.
     * @param string $confirmedBy    The admin UID confirming the hard delete.
     *
     * @return array<string, mixed> The hard-delete summary.
     *
     * @throws RuntimeException If the entity is missing, not soft-deleted, or
     *                          still within the cooling-off period.
     */
    public function confirmHardDelete(string $masterEntityId, string $confirmedBy): array
    {
        $entity = $this->repository->findMasterEntity($masterEntityId);
        if ($entity === null) {
            throw new RuntimeException('Master entity not found.');
        }

        if ((string) ($entity['status'] ?? '') !== 'soft-deleted') {
            throw new RuntimeException('Master entity is not soft-deleted; hard delete is not permitted.');
        }

        if ($this->isCoolingOffElapsed(entity: $entity) === false) {
            throw new RuntimeException('Cooling-off period has not yet elapsed.');
        }

        // Permanently delete all source records, then the master entity.
        $deleted = 0;
        foreach ($this->repository->linkedSourceRecords($masterEntityId) as $record) {
            $recordId = (string) ($record['sourceRecordId'] ?? ($record['id'] ?? ''));
            if ($recordId !== '') {
                $this->repository->delete(MdmObjectRepository::SCHEMA_SOURCE_RECORD, $recordId);
                $deleted++;
            }
        }

        $this->repository->delete(MdmObjectRepository::SCHEMA_MASTER_ENTITY, $masterEntityId);

        $this->logger->info(
            'Pipelinq MDM: AVG hard delete executed',
            ['master' => $masterEntityId, 'by' => $confirmedBy, 'sourcesDeleted' => $deleted]
        );

        return [
            'masterEntityId' => $masterEntityId,
            'status'         => 'hard-deleted',
            'sourcesDeleted' => $deleted,
            'confirmedBy'    => $confirmedBy,
            'confirmedAt'    => $this->repository->now(),
        ];
    }//end confirmHardDelete()

    /**
     * List soft-deleted entities whose cooling-off period has elapsed.
     *
     * @return array<int, array<string, mixed>> The eligible entities.
     */
    public function listHardDeleteCandidates(): array
    {
        $entities = $this->repository->findMasterEntities(null, 'soft-deleted');

        return array_values(
            array_filter(
                $entities,
                fn (array $e): bool => $this->isCoolingOffElapsed(entity: $e)
            )
        );
    }//end listHardDeleteCandidates()

    /**
     * Whether the cooling-off period has elapsed for a soft-deleted entity.
     *
     * @param array<string, mixed> $entity The master entity.
     * @param string|null          $asOf   The as-of timestamp (null = now).
     *
     * @return bool True when hard delete is permitted.
     */
    public function isCoolingOffElapsed(array $entity, ?string $asOf=null): bool
    {
        $softDeletedAt = $this->extractSoftDeleteTimestamp(entity: $entity);
        if ($softDeletedAt === '') {
            return false;
        }

        $now = ($asOf ?? $this->repository->now());

        try {
            $deletedAt = new DateTimeImmutable($softDeletedAt);
            $current   = new DateTimeImmutable($now);
            $eligible  = $deletedAt->add(new DateInterval('P'.self::COOLING_OFF_DAYS.'D'));
        } catch (Exception $e) {
            return false;
        }

        return $current >= $eligible;
    }//end isCoolingOffElapsed()

    /**
     * Compute the hard-delete eligibility date from a soft-delete timestamp.
     *
     * @param string $softDeletedAt The soft-delete timestamp.
     *
     * @return string The eligibility date (ISO date), or empty on parse error.
     */
    public function hardDeleteEligibleAt(string $softDeletedAt): string
    {
        try {
            $deletedAt = new DateTimeImmutable($softDeletedAt);
        } catch (Exception $e) {
            return '';
        }

        return $deletedAt->add(new DateInterval('P'.self::COOLING_OFF_DAYS.'D'))->format('Y-m-d');
    }//end hardDeleteEligibleAt()

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
     * Redact every value in a flat attribute map (pure).
     *
     * @param mixed $map The attribute map.
     *
     * @return array<string, mixed> The redacted map.
     */
    public function redactMap(mixed $map): array
    {
        if (is_array($map) === false) {
            return [];
        }

        $redacted = [];
        foreach (array_keys($map) as $key) {
            $redacted[$key] = self::REDACTED;
        }

        return $redacted;
    }//end redactMap()

    /**
     * Redact attribute provenance values while keeping the structure (pure).
     *
     * @param mixed $provenance The provenance map.
     *
     * @return array<string, mixed> The redacted provenance.
     */
    private function redactProvenance(mixed $provenance): array
    {
        if (is_array($provenance) === false) {
            return [];
        }

        $redacted = [];
        foreach ($provenance as $attribute => $meta) {
            if (is_array($meta) === false) {
                $redacted[$attribute] = ['value' => self::REDACTED];
                continue;
            }

            $meta['value']        = self::REDACTED;
            $redacted[$attribute] = $meta;
        }

        return $redacted;
    }//end redactProvenance()

    /**
     * Best-effort extraction of the soft-delete timestamp from gdprNotes,
     * falling back to the OR built-in updatedAt.
     *
     * @param array<string, mixed> $entity The master entity.
     *
     * @return string The timestamp, or empty string.
     */
    private function extractSoftDeleteTimestamp(array $entity): string
    {
        $notes = (string) ($entity['gdprNotes'] ?? '');
        if (preg_match('/\[([0-9T:\-Z]+)\] AVG right-of-deletion executed/', $notes, $matches) === 1) {
            return $matches[1];
        }

        return (string) ($entity['updatedAt'] ?? ($entity['@self']['updated'] ?? ''));
    }//end extractSoftDeleteTimestamp()
}//end class
