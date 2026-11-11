<?php

/**
 * Pipelinq IdentityTally.
 *
 * What one identity pass linked, skipped and left unresolved.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/unify-client-contact/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

/**
 * The outcome counts of one identity pass, the contactsUid each linked
 * client reference resolved to, and the audit map of object id to
 * contactsUid. Lives for one migrate() call.
 *
 * @spec openspec/specs/unify-client-contact/spec.md
 */
final class IdentityTally {
	/**
	 * Clients linked.
	 *
	 * @var integer
	 */
	private int $clientsLinked = 0;

	/**
	 * Contacts linked.
	 *
	 * @var integer
	 */
	private int $contactsLinked = 0;

	/**
	 * Objects already linked on a prior run.
	 *
	 * @var integer
	 */
	private int $skipped = 0;

	/**
	 * Objects that could not be resolved.
	 *
	 * @var integer
	 */
	private int $unresolved = 0;

	/**
	 * Maps a client's own id/uuid/slug to its resolved contactsUid, so
	 * contactmoment.client (a client UUID back-reference) can be re-keyed.
	 *
	 * @var array<string, string>
	 */
	private array $clientUidByRef = [];

	/**
	 * Object id to resolved contactsUid.
	 *
	 * @var array<string, string>
	 */
	private array $auditMap = [];

	/**
	 * Count one resolve outcome.
	 *
	 * @param string       $status One of linked/skipped/unresolved.
	 * @param IdentityKind $kind   The kind of the object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function count(string $status, IdentityKind $kind): void {
		if ($status === 'linked') {
			$this->countLinked(kind: $kind);
			return;
		}

		if ($status === 'skipped') {
			$this->skipped++;
			return;
		}

		$this->unresolved++;
	}//end count()

	/**
	 * Remember the contactsUid a client resolved to under every reference.
	 *
	 * @param array<int, string> $refs        The client's references.
	 * @param string             $contactsUid The resolved contactsUid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function mapClientRefs(array $refs, string $contactsUid): void {
		foreach ($refs as $ref) {
			$this->clientUidByRef[$ref] = $contactsUid;
		}
	}//end mapClientRefs()

	/**
	 * Record the contactsUid an object resolved to in the audit map.
	 *
	 * @param array<string, mixed> $object      The object.
	 * @param string               $contactsUid The resolved contactsUid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function audit(array $object, string $contactsUid): void {
		$this->auditMap[(string)($object['id'] ?? $object['uuid'] ?? '')] = $contactsUid;
	}//end audit()

	/**
	 * The client reference to contactsUid map.
	 *
	 * @return array<string, string> The map.
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function clientUidByRef(): array {
		return $this->clientUidByRef;
	}//end clientUidByRef()

	/**
	 * The pass summary.
	 *
	 * @param int $momentsRekeyed The contactmomenten re-keyed.
	 *
	 * @return array{clientsLinked:int, contactsLinked:int, skipped:int, unresolved:int, momentsRekeyed:int, auditMap:array<string,string>}
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function summary(int $momentsRekeyed): array {
		return [
			'clientsLinked' => $this->clientsLinked,
			'contactsLinked' => $this->contactsLinked,
			'skipped' => $this->skipped,
			'unresolved' => $this->unresolved,
			'momentsRekeyed' => $momentsRekeyed,
			'auditMap' => $this->auditMap,
		];
	}//end summary()

	/**
	 * Count a linked client or contact.
	 *
	 * @param IdentityKind $kind The kind of the object.
	 *
	 * @return void
	 */
	private function countLinked(IdentityKind $kind): void {
		if ($kind->isClient() === true) {
			$this->clientsLinked++;
			return;
		}

		$this->contactsLinked++;
	}//end countLinked()
}//end class
