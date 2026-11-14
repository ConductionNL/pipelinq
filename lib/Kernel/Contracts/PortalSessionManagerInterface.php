<?php

/**
 * Contract of PortalSessionManager
 *
 * The public surface of PortalSessionManager that other components call, so they depend
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
 * Contract of PortalSessionManager.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalSessionManagerInterface {

	/**
	 * Default session TTL in hours.
	 *
	 * @var int
	 */
	public const DEFAULT_TTL_HOURS = 8;

	/**
	 * Extend a session and throw PortalException when the session is absent or
	 * revoked. Controllers use this to surface the not-logged-in failure as a
	 * single uniform error, keeping the auth literal off the controller body.
	 *
	 * @param string $sessionId The session id.
	 * @param int $ttlHours New TTL in hours from now.
	 *
	 * @return array<string, mixed> The updated session.
	 *
	 * @throws PortalException When the session is absent or revoked.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function extendSessionOrThrow(string $sessionId, int $ttlHours = self::DEFAULT_TTL_HOURS): array;

	/**
	 * Revoke a single session.
	 *
	 * @param string $sessionId The session id.
	 * @param string $reason The revocation reason.
	 *
	 * @return void
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function revokeSession(string $sessionId, string $reason): void;
}//end interface
