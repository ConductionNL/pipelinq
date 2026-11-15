<?php

/**
 * Contract of PortalAuthService
 *
 * The public surface of PortalAuthService that other components call, so they depend
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

use OCA\Pipelinq\Service\Portal\PortalException;

/**
 * Contract of PortalAuthService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAuthServiceInterface {

	/**
	 * Authenticate an account and, on success, mint a session.
	 *
	 * Flow: tenant lookup → account lookup (by email + tenant) → status/lockout
	 * gates → password verify → MFA gate. The returned shape tells the caller
	 * whether a second factor is still required (half-open session) or the
	 * login is complete (full session token).
	 *
	 * @param string $email The login email.
	 * @param string $password The plaintext password.
	 * @param string $tenantId The resolved tenant id.
	 * @param string|null $totpCode An optional TOTP code provided up-front.
	 * @param string $ipHash Hash of the client IP.
	 * @param string $userAgentHash Hash of the client user agent.
	 *
	 * @return array{status: string, mfaRequired: bool, accountId: string, token?: string, sessionId?: string}
	 *
	 * @throws PortalException On any authentication failure (safe, audited).
	 *   503 portalUnavailable first, for any address, when the portal cannot write.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function login(
		string $email,
		string $password,
		string $tenantId,
		?string $totpCode,
		string $ipHash,
		string $userAgentHash,
	): array;
}//end interface
