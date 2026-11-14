<?php

/**
 * Contract of PosRoleService
 *
 * The public surface of PosRoleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Contract of PosRoleService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PosRoleServiceInterface {

	/**
	 * Delete a posRole.
	 *
	 * Refuses the deletion when the role is still referenced by any active
	 * posStaff record (REQ-PSP-001 scenario "Cannot delete a role assigned to
	 * active staff"). The check runs over the posStaff schema and counts
	 * non-soft-deleted entries with the matching posRole UUID.
	 *
	 * @param string $id The role UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException If the role is not found.
	 * @throws OCSForbiddenException If the role is still assigned to active staff.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#2.1
	 */
	public function deleteRole(string $id): void;

	/**
	 * Fetch a single role by id.
	 *
	 * @param string $id The role UUID.
	 *
	 * @return array<string, mixed> The role object.
	 *
	 * @throws OCSNotFoundException If the role is not found.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#2.1
	 */
	public function getRole(string $id): array;

	/**
	 * List all posRole objects.
	 *
	 * @return array<int, array<string, mixed>> The role objects.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#2.1
	 */
	public function listRoles(): array;

	/**
	 * Create or update a posRole.
	 *
	 * Validates maxDiscountPercent is bounded to [0, 100] (REQ-PSP-001) before
	 * persisting via ObjectService::saveObject. When id is empty a new UUID is
	 * generated.
	 *
	 * @param array<string, mixed> $data The role data.
	 * @param string $id Optional UUID for updates.
	 *
	 * @return array<string, mixed> The saved role.
	 *
	 * @throws OCSBadRequestException If maxDiscountPercent is out of bounds or name is empty.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#2.1
	 */
	public function saveRole(array $data, string $id = ''): array;
}//end interface
