<?php

/**
 * Contract of PortalProfileService
 *
 * The public surface of PortalProfileService that other components call, so they depend
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

/**
 * Contract of PortalProfileService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalProfileServiceInterface {

	/**
	 * The current account's safe profile view (no secrets).
	 *
	 * @param array<string, mixed> $account The account record.
	 *
	 * @return array<string, mixed> The safe profile.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function present(array $account): array;

	/**
	 * Apply a set of profile changes, auditing each changed field, and starting
	 * an email-verification flow when the email changes.
	 *
	 * @param array<string, mixed> $account The account record.
	 * @param string $tenantId The tenant id.
	 * @param array<string, mixed> $changes The requested changes.
	 *
	 * @return array<string, mixed> The updated safe profile.
	 * @spec openspec/changes/archive/2026-06-14-customer-portal/specs.md#REQ-007
	 */
	public function update(array $account, string $tenantId, array $changes): array;

	/**
	 * Verify a pending email change with a token, swapping it into place.
	 *
	 * @param string $token The plaintext verification token.
	 * @param string $tenantId The tenant id.
	 *
	 * @return bool True when the email was verified and applied.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function verifyEmail(string $token, string $tenantId): bool;
}//end interface
