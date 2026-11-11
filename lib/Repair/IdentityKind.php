<?php

/**
 * Pipelinq IdentityKind.
 *
 * Which kind of pipelinq identity the identity pass resolves.
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
 * The MDM entity type and the ContactVcardService object type of the
 * identities in one collection: organisations (clients) or persons (contacts).
 *
 * @spec openspec/specs/unify-client-contact/spec.md
 */
final class IdentityKind {
	/**
	 * Constructor.
	 *
	 * @param string $entityType MDM entityType ('account' or 'contact').
	 * @param string $objectType ContactVcardService object type ('client' or 'contact').
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $entityType,
		public readonly string $objectType,
	) {
	}//end __construct()

	/**
	 * Whether these identities are clients, whose contactmomenten are re-keyed.
	 *
	 * @return bool True for clients.
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function isClient(): bool {
		return $this->objectType === 'client';
	}//end isClient()
}//end class
