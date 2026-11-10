<?php

/**
 * Pipelinq ScopedOwner.
 *
 * The owning contact and client of one object a portal account wants to read,
 * matched against the id buckets of a resolved portal scope.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

/**
 * An object's owning contact id and client id, either of which may be unknown.
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-003
 */
final class ScopedOwner {
	/**
	 * Constructor.
	 *
	 * @param string|null $contactId The object's owning contact id.
	 * @param string|null $clientId  The object's owning client id.
	 */
	public function __construct(
		public readonly ?string $contactId,
		public readonly ?string $clientId,
	) {
	}//end __construct()

	/**
	 * Whether the owning contact is in the contact ids, or else the owning client in the client ids.
	 *
	 * An unknown id never matches; ids are compared strictly.
	 *
	 * @param array<int, string> $contactIds The contact ids of one scope bucket.
	 * @param array<int, string> $clientIds  The client ids of the same bucket.
	 *
	 * @return bool True when the object belongs to the bucket.
	 *
	 * @spec openspec/changes/customer-portal/specs.md#REQ-003
	 */
	public function isIn(array $contactIds, array $clientIds): bool {
		return ($this->contactId !== null && in_array($this->contactId, $contactIds, true) === true)
			|| ($this->clientId !== null && in_array($this->clientId, $clientIds, true) === true);
	}//end isIn()
}//end class
