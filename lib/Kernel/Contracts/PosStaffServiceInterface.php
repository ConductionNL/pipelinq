<?php

/**
 * Contract of PosStaffService
 *
 * The public surface of PosStaffService that other components call, so they depend
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
 * Contract of PosStaffService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PosStaffServiceInterface {

	/**
	 * Validate that the caller is allowed to mutate the given staff object.
	 *
	 * Per ADR-005 Rule 3, mutation endpoints MUST run a per-object
	 * authorization check. POS staff records are admin-managed: only an admin
	 * (group `admin`) is allowed to mutate. The actual `isAdmin` check is the
	 * caller controller's responsibility; this helper is the policy gate that
	 * confirms the object actually belongs to this app's posStaff schema (so
	 * an attacker cannot pass a foreign id and have an admin endpoint
	 * silently operate on someone else's data).
	 *
	 * @param string $staffId The staff UUID.
	 * @param string $userId The acting Nextcloud user UID (informational only).
	 *
	 * @return array<string, mixed> The authorised staff record.
	 *
	 * @throws OCSNotFoundException If the staff is not found in this app's schema.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#3.3
	 */
	public function authorizeStaff(string $staffId, string $userId): array;

	/**
	 * Delete a staff record.
	 *
	 * @param string $id The staff UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException If deletion fails.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function deleteStaff(string $id): void;

	/**
	 * Fetch a single staff record with the bcrypt pinHash stripped.
	 *
	 * @param string $id The staff UUID.
	 *
	 * @return array<string, mixed> The staff object without pinHash.
	 *
	 * @throws OCSNotFoundException If the staff is not found.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function getStaff(string $id): array;

	/**
	 * List all posStaff records with the bcrypt pinHash stripped.
	 *
	 * @return array<int, array<string, mixed>> The staff objects without pinHash.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function listStaff(): array;

	/**
	 * Create or update a posStaff record.
	 *
	 * On create the PIN is required; on update an empty/absent PIN preserves
	 * the existing hash. PIN format must be 4-6 digits (REQ-PSP-002). The PIN
	 * is bcrypt-hashed with PASSWORD_BCRYPT before persistence; the plain text
	 * never lands in the OR row.
	 *
	 * @param array<string, mixed> $data The staff data.
	 * @param string $id Optional UUID for updates.
	 *
	 * @return array<string, mixed> The saved staff (pinHash stripped).
	 *
	 * @throws OCSBadRequestException If displayName/role missing, or PIN format is invalid on create/update.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function saveStaff(array $data, string $id = ''): array;

	/**
	 * Validate a staff member's PIN and open a session.
	 *
	 * Enforces inactive-account block, current-lockout block, bcrypt verify
	 * (constant-time), failed-attempt counter, 5-strike 15-minute lockout, and
	 * counter reset on success. On success returns a flat envelope with the
	 * staff id, display name, and the role's permission matrix.
	 *
	 * @param string $staffId The staff UUID to authenticate.
	 * @param string $pin The submitted plain-text PIN.
	 *
	 * @return array{staffId: string, displayName: string, permissions: array<string, mixed>, expiresAt: string} The session envelope.
	 *
	 * @throws OCSNotFoundException If the staff is not found.
	 * @throws OCSForbiddenException If the account is inactive, currently locked, or the PIN does not match.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#3.1
	 */
	public function validatePin(string $staffId, string $pin): array;
}//end interface
