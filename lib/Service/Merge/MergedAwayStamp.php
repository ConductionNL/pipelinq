<?php

/**
 * Pipelinq MergedAwayStamp.
 *
 * Writes on a client or contact that OpenRegister merged into another record
 * where it went and when, and clears that again when the merge is undone.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Merge
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Merge;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\OpenRegister\Service\Object\ObjectPatch;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The merge stamp on a merged-away client or contact: `mergedInto` (the
 * surviving record), `mergedIntoLabel` ("Name (number)") and `mergedAt`.
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
 */
class MergedAwayStamp {
	/**
	 * The fields of the stamp.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = ['mergedInto', 'mergedIntoLabel', 'mergedAt'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objects   OpenRegister's object service.
	 * @param IAppConfig             $appConfig App config, source of the client and contact schema ids.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objects,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Stamp one merged-away record, or clear its stamp when the merge is undone.
	 * Records that are not a client or contact are left alone.
	 *
	 * @param string             $uuid  The merged-away record.
	 * @param ObjectsMergedEvent $event The merge or its reversal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
	 */
	public function apply(string $uuid, ObjectsMergedEvent $event): void {
		if ($this->isParty(record: $this->load(uuid: $uuid)) === false) {
			return;
		}

		$fields = array_fill_keys(self::FIELDS, null);
		if ($event->isReversal() === false) {
			$fields = $this->stampFor(survivorUuid: $event->getSurvivorUuid());
		}

		$this->objects->patchObject(patch: new ObjectPatch(objectId: $uuid, data: $fields), _rbac: false, _multitenancy: false);
	}//end apply()

	/**
	 * Whether a record is a client or a contact.
	 *
	 * @param ObjectEntityInterface|null $record The record.
	 *
	 * @return bool True for a client or contact.
	 */
	private function isParty(?ObjectEntityInterface $record): bool {
		if ($record === null) {
			return false;
		}

		$schemas = [
			$this->appConfig->getValueString(Application::APP_ID, 'client_schema', ''),
			$this->appConfig->getValueString(Application::APP_ID, 'contact_schema', ''),
		];
		return in_array((string)$record->getSchema(), array_filter($schemas), true);
	}//end isParty()

	/**
	 * The stamp that points at the surviving record.
	 *
	 * @param string $survivorUuid The surviving record.
	 *
	 * @return array<string, string> The stamp.
	 */
	private function stampFor(string $survivorUuid): array {
		$survivor = ($this->load(uuid: $survivorUuid)?->getObject() ?? []);
		$label    = trim((string)($survivor['name'] ?? ''));
		$number   = trim((string)($survivor['customerNumber'] ?? ''));
		if ($number !== '') {
			$label = trim($label.' ('.$number.')');
		}

		return ['mergedInto' => $survivorUuid, 'mergedIntoLabel' => $label, 'mergedAt' => (new DateTimeImmutable())->format(DATE_ATOM)];
	}//end stampFor()

	/**
	 * A record by uuid, read as the system, or null.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntityInterface|null The record.
	 */
	private function load(string $uuid): ?ObjectEntityInterface {
		return $this->objects->find(id: $uuid, _rbac: false, _multitenancy: false, _render: false, _audit: false);
	}//end load()
}//end class
