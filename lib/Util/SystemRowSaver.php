<?php

/**
 * Pipelinq SystemRowSaver.
 *
 * A writer that saves one row as the acting admin with RBAC and multitenancy off.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;

/**
 * Builds the writer a repair step uses to save rows it already owns: RBAC and
 * multitenancy are off and the save runs as the given admin.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait SystemRowSaver {

	/**
	 * Build the writer that saves one row as the acting admin.
	 *
	 * @param ObjectServiceInterface $objectService The OR object service.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param IUser|null $actingAdmin The admin to act as, or null when none exists.
	 *
	 * @return callable(array<string, mixed>): mixed
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function rowSaver(ObjectServiceInterface $objectService, string $register, string $schema, IUser|null $actingAdmin): callable {
		// Bypass RBAC here — and ONLY here: this is a system context
		// reshaping data it already owns. TicketService::save() keeps
		// RBAC on, because controllers call it.
		return static fn (array $data): mixed => $objectService->saveObject(
			object: $data,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: (string)($data['id'] ?? ''),
			_rbac: false,
			_multitenancy: false,
			currentUser: $actingAdmin,
		);
	}//end rowSaver()
}//end trait
