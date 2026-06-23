<?php

/**
 * Pipelinq MasterEntityService.
 *
 * Owns the golden record per Master Entity: CRUD, the trust-tier survivorship
 * recomputation that resolves each attribute from the linked source records,
 * and source-record linkage / unlinkage. The survivorship core is a pure
 * function so the conflict-resolution rules (gold always wins, fall back to
 * silver then bronze, freshness decay, deterministic tie-break) are unit-tested
 * without OpenRegister.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use Psr\Log\LoggerInterface;

/**
 * Service for Master Entity golden-record management.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MasterEntityService
{
    /**
     * The masterEntity schema slug.
     *
     * @var string
     */
    public const SCHEMA = 'masterEntity';

    /**
     * The sourceRecord schema slug.
     *
     * @var string
     */
    public const SOURCE_SCHEMA = 'sourceRecord';

    /**
     * Constructor.
     *
     * @param MdmObjectRepository       $repository The MDM object repository.
     * @param TrustConfigurationService $trust      The trust-tier service.
     * @param LoggerInterface           $logger     The logger.
     */
    public function __construct(
        private MdmObjectRepository $repository,
        private TrustConfigurationService $trust,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Fetch a Master Entity by uuid.
     *
     * @param string $masterId The master entity uuid.
     *
     * @return array<string, mixed>|null The entity, or null if absent.
     */
    public function find(string $masterId): ?array
    {
        return $this->repository->find(self::SCHEMA, $masterId);
    }//end find()

    /**
     * List Master Entities, optionally filtered by entityType and status.
     *
     * @param string|null $entityType Optional entity-type filter.
     * @param string|null $status     Optional status filter.
     *
     * @return array<int, array<string, mixed>> The matching entities.
     */
    public function findAll(?string $entityType=null, ?string $status=null): array
    {
        $filters = [];
        if ($entityType !== null && $entityType !== '') {
            $filters['entityType'] = $entityType;
        }

        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }

        return $this->repository->findAll(self::SCHEMA, $filters);
    }//end findAll()

    /**
     * Fetch all source records currently linked to a Master Entity.
     *
     * @param string $masterId The master entity uuid.
     *
     * @return array<int, array<string, mixed>> The linked source records.
     */
    public function linkedSourceRecords(string $masterId): array
    {
        return $this->repository->findAll(
            self::SOURCE_SCHEMA,
            ['currentMasterEntity' => $masterId]
        );
    }//end linkedSourceRecords()

    /**
     * Recompute and persist the golden record for a Master Entity.
     *
     * Loads the linked source records, resolves each attribute via the
     * trust-tier survivorship rules, and writes back goldenRecord +
     * attributeProvenance. The OR built-in auditTrail records the mutation.
     *
     * @param string $masterId The master entity uuid.
     *
     * @return array<string, mixed>|null The updated entity, or null if absent.
     */
    public function recomputeGoldenRecord(string $masterId): ?array
    {
        $entity = $this->find(masterId: $masterId);
        if ($entity === null) {
            return null;
        }

        $entityType    = (string) ($entity['entityType'] ?? '');
        $sourceRecords = $this->linkedSourceRecords(masterId: $masterId);

        $resolution = $this->resolveGoldenRecord(
            entityType: $entityType,
            sourceRecords: $sourceRecords
        );

        $entity['goldenRecord']        = $resolution['goldenRecord'];
        $entity['attributeProvenance'] = $resolution['attributeProvenance'];

        // Materialise the most-recent provenance date so OpenRegister's
        // freshness quality rule (x-openregister-quality) has a single date
        // field to decay against; OR's per-object scorer cannot traverse the
        // attributeProvenance map itself.
        $entity['lastSourceUpdate'] = $this->latestProvenanceDate(
            provenance: $resolution['attributeProvenance']
        );

        // Materialise flattened top-level match fields so OpenRegister's
        // duplicate-detection similarity (x-openregister-dedup) can read them;
        // OR's SimilarityCalculator reads top-level keys only, not nested
        // goldenRecord.* paths.
        $golden = $resolution['goldenRecord'];
        $entity['matchName']      = (string) ($golden['name'] ?? '');
        $entity['matchEmail']     = (string) ($golden['email'] ?? '');
        $entity['matchKvkNumber'] = (string) ($golden['kvkNumber'] ?? '');
        $entity['matchPhone']     = (string) ($golden['phone'] ?? '');

        return $this->repository->save(self::SCHEMA, $entity, $masterId);
    }//end recomputeGoldenRecord()

    /**
     * Pure survivorship resolver: pick the winning value per attribute.
     *
     * For each attribute present in any source record's mappedAttributes:
     *   1. Look up the trust tier for (entityType, attribute, sourceSystem),
     *      with freshness decay applied from the source's lastChange.
     *   2. The highest tier wins (gold > silver > bronze). `discard` and unknown
     *      tiers are never selected.
     *   3. Ties on tier are broken by the more recently changed source.
     *
     * Sources whose value is null/empty do not compete for that attribute.
     *
     * @param string                           $entityType    The entity type.
     * @param array<int, array<string, mixed>> $sourceRecords The linked source records.
     *
     * @return array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function resolveGoldenRecord(string $entityType, array $sourceRecords): array
    {
        // Collect the candidate values per attribute across all source records.
        $candidates = [];
        foreach ($sourceRecords as $record) {
            if (($record['withdrawn'] ?? false) === true) {
                continue;
            }

            $mapped = ($record['mappedAttributes'] ?? []);
            if (is_array($mapped) === false) {
                continue;
            }

            $sourceSystem = (string) ($record['sourceSystem'] ?? '');
            $lastChange   = ($record['lastChange'] ?? null);
            if (is_string($lastChange) === false) {
                $lastChange = null;
            }

            foreach ($mapped as $attribute => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $tier = $this->trust->getTrustTier(
                    entityType: $entityType,
                    attribute: (string) $attribute,
                    sourceSystem: $sourceSystem,
                    lastChange: $lastChange
                );

                // No configured tier defaults to bronze so a single uncontested
                // source can still populate the golden record.
                $effectiveTier = ($tier ?? 'bronze');
                if ($effectiveTier === 'discard') {
                    continue;
                }

                $candidates[$attribute][] = [
                    'value'        => $value,
                    'sourceSystem' => $sourceSystem,
                    'trustTier'    => $effectiveTier,
                    'lastUpdated'  => ($lastChange ?? ''),
                ];
            }//end foreach
        }//end foreach

        $goldenRecord = [];
        $provenance   = [];
        foreach ($candidates as $attribute => $options) {
            $winner = $this->pickWinner(options: $options);
            if ($winner === null) {
                continue;
            }

            $goldenRecord[$attribute] = $winner['value'];
            $provenance[$attribute]   = [
                'value'        => $winner['value'],
                'sourceSystem' => $winner['sourceSystem'],
                'trustTier'    => $winner['trustTier'],
                'lastUpdated'  => $winner['lastUpdated'],
            ];
        }

        return [
            'goldenRecord'        => $goldenRecord,
            'attributeProvenance' => $provenance,
        ];
    }//end resolveGoldenRecord()

    /**
     * Find the most recent `lastUpdated` across all attribute provenance.
     *
     * Materialised onto the entity as `lastSourceUpdate` so OpenRegister's
     * freshness quality rule can decay a single date field.
     *
     * @param array<string, mixed> $provenance The per-attribute provenance map.
     *
     * @return string The latest ISO timestamp, or empty string when none.
     */
    public function latestProvenanceDate(array $provenance): string
    {
        $latest = '';
        foreach ($provenance as $meta) {
            if (is_array($meta) === false) {
                continue;
            }

            $lastUpdated = (string) ($meta['lastUpdated'] ?? '');
            if ($lastUpdated > $latest) {
                $latest = $lastUpdated;
            }
        }

        return $latest;
    }//end latestProvenanceDate()

    /**
     * Pick the winning candidate: highest tier, then most recent lastUpdated.
     *
     * @param array<int, array<string, mixed>> $options The candidate values.
     *
     * @return array<string, mixed>|null The winner, or null when empty.
     */
    private function pickWinner(array $options): ?array
    {
        $winner     = null;
        $winnerRank = -1;
        foreach ($options as $option) {
            $rank = $this->trust->tierRank((string) $option['trustTier']);
            if ($rank < 0) {
                continue;
            }

            if ($winner === null
                || $rank > $winnerRank
                || ($rank === $winnerRank
                && (string) $option['lastUpdated'] > (string) $winner['lastUpdated'])
            ) {
                $winner     = $option;
                $winnerRank = $rank;
            }
        }

        return $winner;
    }//end pickWinner()

    /**
     * Link a source record to a Master Entity and recompute the golden record.
     *
     * @param string $sourceRecordId The source-record uuid.
     * @param string $masterId       The master entity uuid.
     *
     * @return array<string, mixed>|null The updated master entity.
     */
    public function linkSourceRecord(string $sourceRecordId, string $masterId): ?array
    {
        $sourceRecord = $this->repository->find(self::SOURCE_SCHEMA, $sourceRecordId);
        if ($sourceRecord === null) {
            $this->logger->warning('Pipelinq MDM: linkSourceRecord — source not found', ['id' => $sourceRecordId]);
            return null;
        }

        $sourceRecord['currentMasterEntity'] = $masterId;
        $this->repository->save(self::SOURCE_SCHEMA, $sourceRecord, $sourceRecordId);

        return $this->recomputeGoldenRecord(masterId: $masterId);
    }//end linkSourceRecord()

    /**
     * Unlink a source record and recompute its former Master Entity.
     *
     * @param string $sourceRecordId The source-record uuid.
     *
     * @return array<string, mixed>|null The recomputed former master entity.
     */
    public function unlinkSourceRecord(string $sourceRecordId): ?array
    {
        $sourceRecord = $this->repository->find(self::SOURCE_SCHEMA, $sourceRecordId);
        if ($sourceRecord === null) {
            return null;
        }

        $masterId = (string) ($sourceRecord['currentMasterEntity'] ?? '');

        $sourceRecord['currentMasterEntity'] = '';
        $sourceRecord['withdrawn']           = true;
        $this->repository->save(self::SOURCE_SCHEMA, $sourceRecord, $sourceRecordId);

        if ($masterId === '') {
            return null;
        }

        return $this->recomputeGoldenRecord(masterId: $masterId);
    }//end unlinkSourceRecord()
}//end class
