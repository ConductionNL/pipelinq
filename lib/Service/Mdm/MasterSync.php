<?php

/**
 * Pipelinq MasterSync.
 *
 * One golden-record sync: the master entity id and the entity row, with its
 * type and golden record read off the row.
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

/**
 * A master entity being synced to its OR schema instance.
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
 */
final class MasterSync {
	/**
	 * Constructor.
	 *
	 * @param string               $masterId The master entity uuid.
	 * @param array<string, mixed> $entity   The master entity.
	 */
	public function __construct(
		public readonly string $masterId,
		public readonly array $entity,
	) {
	}//end __construct()

	/**
	 * The master entity's type ('' when it has none).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
	 */
	public function entityType(): string {
		return (string)($this->entity['entityType'] ?? '');
	}//end entityType()

	/**
	 * The golden-record attributes (an empty list when malformed).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
	 */
	public function golden(): array {
		$golden = ($this->entity['goldenRecord'] ?? []);
		if (is_array($golden) === false) {
			return [];
		}

		return $golden;
	}//end golden()

	/**
	 * The markers every canonical OR object carries.
	 *
	 * @return array{masterEntityRef: string, isMasterRecord: true}
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
	 */
	public function markers(): array {
		return ['masterEntityRef' => $this->masterId, 'isMasterRecord' => true];
	}//end markers()
}//end class
