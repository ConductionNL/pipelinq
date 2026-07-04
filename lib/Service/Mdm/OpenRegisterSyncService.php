<?php

/**
 * Pipelinq OpenRegisterSyncService.
 *
 * Keeps the Pipelinq OpenRegister schema instances (contact, client/account,
 * product) in step with their golden records, stamping each canonical OR object
 * with masterEntityRef and isMasterRecord so the catalog resolves correctly via
 * the master entity. Non-canonical records keep their masterEntityRef but are
 * flagged isMasterRecord=false.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use Psr\Log\LoggerInterface;

/**
 * Service for syncing golden records to OpenRegister schema instances.
 */
class OpenRegisterSyncService
{
    /**
     * Map of master entityType to the Pipelinq OR schema slug it projects onto.
     *
     * `account` projects onto the existing `client` schema (the account-like
     * Pipelinq entity); `vendor` has no dedicated schema and is skipped.
     *
     * @var array<string, string>
     */
    public const ENTITY_TYPE_SCHEMA = [
        'contact' => 'contact',
        'account' => 'client',
        'product' => 'product',
    ];

    /**
     * Constructor.
     *
     * @param MdmObjectRepository $repository The MDM object repository (also the
     *                                        re-homed master-entity read helpers).
     * @param LoggerInterface     $logger     The logger.
     */
    public function __construct(
        private MdmObjectRepository $repository,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the OR schema slug for a master entityType, or null if unmapped.
     *
     * @param string $entityType The master entity type.
     *
     * @return string|null The OR schema slug.
     */
    public function schemaForEntityType(string $entityType): ?string
    {
        return (self::ENTITY_TYPE_SCHEMA[$entityType] ?? null);
    }//end schemaForEntityType()

    /**
     * Sync a Master Entity's golden record to its OR schema instance.
     *
     * Finds the canonical OR object via existing masterEntityRef linkage and
     * writes the golden-record attributes plus the MDM markers. When no OR
     * object is linked yet, a new canonical one is created.
     *
     * @param string $masterId The master entity uuid.
     *
     * @return array<string, mixed>|null The synced OR object, or null when the
     *                                   entity is missing / its type is unmapped.
     */
    public function syncMasterToRegister(string $masterId): ?array
    {
        $entity = $this->repository->findMasterEntity($masterId);
        if ($entity === null) {
            return null;
        }

        $entityType = (string) ($entity['entityType'] ?? '');
        $schemaSlug = $this->schemaForEntityType(entityType: $entityType);
        if ($schemaSlug === null) {
            $this->logger->debug(
                'Pipelinq MDM: no OR schema mapped for entity type; skipping sync',
                ['master' => $masterId, 'entityType' => $entityType]
            );
            return null;
        }

        $golden = ($entity['goldenRecord'] ?? []);
        if (is_array($golden) === false) {
            $golden = [];
        }

        // Find the canonical OR object already linked to this master entity.
        $existing = $this->repository->findAll(
            $schemaSlug,
            ['masterEntityRef' => $masterId, 'isMasterRecord' => true]
        );

        $target = ($existing[0] ?? null);

        $targetBase = [];
        if (is_array($target) === true) {
            $targetBase = $target;
        }

        $orObject = array_merge(
            $targetBase,
            $golden,
            ['masterEntityRef' => $masterId, 'isMasterRecord' => true]
        );

        $uuid = null;
        if (is_array($target) === true) {
            $resolvedUuid = (string) ($target['id'] ?? ($target['@self']['id'] ?? ''));
            if ($resolvedUuid !== '') {
                $uuid = $resolvedUuid;
            }
        }

        $saved = $this->repository->save($schemaSlug, $orObject, $uuid);

        $this->logger->info(
            'Pipelinq MDM: golden record synced to OpenRegister',
            ['master' => $masterId, 'schema' => $schemaSlug, 'created' => ($uuid === null)]
        );

        return $saved;
    }//end syncMasterToRegister()

    /**
     * Mark a previously-canonical OR object as non-canonical after a merge.
     *
     * @param string $schemaSlug The OR schema slug.
     * @param string $objectId   The OR object uuid.
     *
     * @return void
     */
    public function demoteRecord(string $schemaSlug, string $objectId): void
    {
        $object = $this->repository->find($schemaSlug, $objectId);
        if ($object === null) {
            return;
        }

        $object['isMasterRecord'] = false;
        $this->repository->save($schemaSlug, $object, $objectId);
    }//end demoteRecord()
}//end class
