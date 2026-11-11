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
 * As of retire-mdm-sync-queue this projection is driven EVENT-first from
 * ObjectsMergedSyncListener (a merge/reversal is exactly when the survivor's
 * golden record changed), replacing the retired hourly MdmOpenRegisterSyncJob
 * poller. OpenRegister materialises the masterEntity's own golden record on
 * save (x-openregister-survivorship), but not these pipelinq-specific schema
 * projections (account→client mapping, isMasterRecord markers), so the service
 * is kept rather than deleted.
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
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use Psr\Log\LoggerInterface;

/**
 * Service for syncing golden records to OpenRegister schema instances.
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
 */
class OpenRegisterSyncService {
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
	 * @param LoggerInterface $logger The logger.
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
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
	 */
	public function schemaForEntityType(string $entityType): ?string {
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
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
	 */
	public function syncMasterToRegister(string $masterId): ?array {
		$entity = $this->repository->findMasterEntity($masterId);
		if ($entity === null) {
			return null;
		}

		$sync = new MasterSync(masterId: $masterId, entity: $entity);
		$schemaSlug = $this->mappedSchema(sync: $sync);
		if ($schemaSlug === null) {
			return null;
		}

		$target = $this->canonicalObject(sync: $sync, schemaSlug: $schemaSlug);
		$uuid = self::objectUuid(target: $target);

		$saved = $this->repository->save($schemaSlug, self::syncedObject(sync: $sync, target: $target), $uuid);

		$this->logger->info(
			'Pipelinq MDM: golden record synced to OpenRegister',
			['master' => $masterId, 'schema' => $schemaSlug, 'created' => ($uuid === null)]
		);

		return $saved;
	}//end syncMasterToRegister()

	/**
	 * The OR schema the entity's type projects onto; null (logged) when unmapped.
	 *
	 * @param MasterSync $sync The sync.
	 *
	 * @return string|null
	 */
	private function mappedSchema(MasterSync $sync): ?string {
		$entityType = $sync->entityType();
		$schemaSlug = $this->schemaForEntityType(entityType: $entityType);
		if ($schemaSlug === null) {
			$this->logger->debug(
				'Pipelinq MDM: no OR schema mapped for entity type; skipping sync',
				['master' => $sync->masterId, 'entityType' => $entityType]
			);
		}

		return $schemaSlug;
	}//end mappedSchema()

	/**
	 * The canonical OR object already linked to this master entity, if any.
	 *
	 * @param MasterSync $sync       The sync.
	 * @param string     $schemaSlug The OR schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function canonicalObject(MasterSync $sync, string $schemaSlug): ?array {
		$existing = $this->repository->findAll($schemaSlug, $sync->markers());

		$target = ($existing[0] ?? null);
		if (is_array($target) === true) {
			return $target;
		}

		return null;
	}//end canonicalObject()

	/**
	 * The OR object to write: the linked object, the golden record over it, the markers last.
	 *
	 * @param MasterSync                $sync   The sync.
	 * @param array<string, mixed>|null $target The linked canonical object.
	 *
	 * @return array<string, mixed>
	 */
	private static function syncedObject(MasterSync $sync, ?array $target): array {
		return array_merge(
			($target ?? []),
			$sync->golden(),
			$sync->markers()
		);
	}//end syncedObject()

	/**
	 * The linked object's uuid (id, else @self.id); null creates a new object.
	 *
	 * @param array<string, mixed>|null $target The linked canonical object.
	 *
	 * @return string|null
	 */
	private static function objectUuid(?array $target): ?string {
		if ($target === null) {
			return null;
		}

		$resolvedUuid = (string)($target['id'] ?? ($target['@self']['id'] ?? ''));
		if ($resolvedUuid === '') {
			return null;
		}

		return $resolvedUuid;
	}//end objectUuid()

	/**
	 * Mark a previously-canonical OR object as non-canonical after a merge.
	 *
	 * @param string $schemaSlug The OR schema slug.
	 * @param string $objectId The OR object uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
	 */
	public function demoteRecord(string $schemaSlug, string $objectId): void {
		$object = $this->repository->find($schemaSlug, $objectId);
		if ($object === null) {
			return;
		}

		$object['isMasterRecord'] = false;
		$this->repository->save($schemaSlug, $object, $objectId);
	}//end demoteRecord()
}//end class
