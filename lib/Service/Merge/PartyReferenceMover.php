<?php

/**
 * Pipelinq PartyReferenceMover.
 *
 * Moves every pipelinq reference to a merged-away client or contact onto the
 * record that stays, and lists the rows it moved; on a reversal it moves exactly
 * those rows back. A row whose reference was changed by hand after the merge no
 * longer points at the record that stays and is left as it is, and reported.
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
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Merge;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Moves client and contact references for a merge and back for its reversal.
 *
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
 */
class PartyReferenceMover {

	/**
	 * The register every reference lives in.
	 */
	private const REGISTER = 'pipelinq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objects OpenRegister's object service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objects,
	) {
	}//end __construct()

	/**
	 * Point every reference to the merged-away record at the record that stays.
	 *
	 * @param string $partyType `client` or `contact`.
	 * @param string $fromUuid  The merged-away record.
	 * @param string $intoUuid  The record that stays.
	 *
	 * @return list<array{schema: string, uuid: string, property: string}> The rows moved.
	 *
	 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
	 */
	public function move(string $partyType, string $fromUuid, string $intoUuid): array {
		$moved = [];
		foreach ($this->referencesOf(partyType: $partyType) as [$schema, $property]) {
			foreach ($this->rowsReferring(schema: $schema, property: $property, uuid: $fromUuid) as $rowUuid) {
				$this->objects->patchObject(objectId: $rowUuid, data: [$property => $intoUuid], _rbac: false, _multitenancy: false);
				$moved[] = ['schema' => $schema, 'uuid' => $rowUuid, 'property' => $property];
			}
		}

		return $moved;
	}//end move()

	/**
	 * Point the rows a merge moved back at the merged-away record.
	 *
	 * A row that no longer points at the record that stays, or is gone, is skipped.
	 *
	 * @param array<int, mixed> $ledger   The rows the merge moved, as move() listed them.
	 * @param string            $fromUuid The merged-away record, restored.
	 * @param string            $intoUuid The record that stayed.
	 *
	 * @return array{restored: list<array<string, string>>, skipped: list<array<string, string>>}
	 *
	 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
	 */
	public function restore(array $ledger, string $fromUuid, string $intoUuid): array {
		$result = ['restored' => [], 'skipped' => []];
		foreach ($ledger as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$entry = [
				'schema' => (string)($row['schema'] ?? ''),
				'uuid' => (string)($row['uuid'] ?? ''),
				'property' => (string)($row['property'] ?? ''),
			];
			if ($entry['uuid'] === '' || $entry['property'] === '' || $this->stillPointsAt(entry: $entry, uuid: $intoUuid) === false) {
				$result['skipped'][] = $entry;
				continue;
			}

			$this->objects->patchObject(objectId: $entry['uuid'], data: [$entry['property'] => $fromUuid], _rbac: false, _multitenancy: false);
			$result['restored'][] = $entry;
		}

		return $result;
	}//end restore()

	/**
	 * The reference properties of a party type.
	 *
	 * @param string $partyType `client` or `contact`.
	 *
	 * @return list<array{0: string, 1: string}> Empty for any other type.
	 */
	private function referencesOf(string $partyType): array {
		return match ($partyType) {
			'client' => PartyReferences::CLIENT,
			'contact' => PartyReferences::CONTACT,
			default => [],
		};
	}//end referencesOf()

	/**
	 * Whether a moved row still holds the record that stayed.
	 *
	 * @param array<string, string> $entry The moved row.
	 * @param string                $uuid  The record that stayed.
	 *
	 * @return bool
	 */
	private function stillPointsAt(array $entry, string $uuid): bool {
		$row = $this->objects->find(id: $entry['uuid'], _rbac: false, _multitenancy: false, _render: false, _audit: false);
		if ($row === null) {
			return false;
		}

		return (string)($row->getObject()[$entry['property']] ?? '') === $uuid;
	}//end stillPointsAt()

	/**
	 * The uuids of the rows of a schema whose property holds the given uuid.
	 *
	 * @param string $schema   The schema slug.
	 * @param string $property The reference property.
	 * @param string $uuid     The referenced record.
	 *
	 * @return list<string>
	 */
	private function rowsReferring(string $schema, string $property, string $uuid): array {
		$rows = $this->objects->searchObjectsBySlug(
			registerSlug: self::REGISTER,
			schemaSlug: $schema,
			filters: [$property => $uuid],
			_rbac: false,
			_multitenancy: false
		);
		if (is_array($rows) === false) {
			return [];
		}

		$uuids = [];
		foreach ($rows as $row) {
			[$rowUuid, $value] = $this->uuidAndValue(row: $row, property: $property);
			// The filter is re-checked: a filter the query layer ignores must not move every row.
			if ($rowUuid !== '' && $value === $uuid) {
				$uuids[] = $rowUuid;
			}
		}

		return array_values(array_unique($uuids));
	}//end rowsReferring()

	/**
	 * A search row's uuid and the value of its reference property.
	 *
	 * @param mixed  $row      An entity or an array.
	 * @param string $property The reference property.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function uuidAndValue(mixed $row, string $property): array {
		if ($row instanceof ObjectEntityInterface) {
			return [(string)$row->getUuid(), (string)($row->getObject()[$property] ?? '')];
		}

		if (is_array($row) === false) {
			return ['', ''];
		}

		$uuid = (string)($row['@self']['id'] ?? $row['uuid'] ?? $row['id'] ?? '');
		return [$uuid, (string)($row[$property] ?? '')];
	}//end uuidAndValue()
}//end class
