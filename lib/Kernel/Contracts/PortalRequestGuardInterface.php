<?php

/**
 * Contract of PortalRequestGuard
 *
 * The public surface of PortalRequestGuard that other components call, so they depend
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
use OCP\IRequest;

/**
 * Contract of PortalRequestGuard.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalRequestGuardInterface {

	/**
	 * Authenticate the customer for a protected request.
	 *
	 * Returns a context bundle {account, accountId, session, tenantId} on
	 * success, or throws a 401 PortalException — never returns null and never
	 * falls open.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return array{account: array<string, mixed>, accountId: string, session: array<string, mixed>, tenantId: string}
	 *
	 * @throws PortalException On any authentication failure.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function authenticate(IRequest $request): array;

	/**
	 * Hash of the client IP (for audit; never stores the raw IP).
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string The IP hash.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function ipHash(IRequest $request): string;

	/**
	 * Resolve the tenant id for a request from server-trusted signals only.
	 *
	 * In widget mode (tenant asserted via the X-Portal-Tenant header) the
	 * request's Origin MUST be in the tenant's widget-origin allow-list, or a
	 * 403 PortalException is thrown — the single cross-origin embedding gate.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return string The resolved tenant id.
	 *
	 * @throws PortalException When a widget-mode request's Origin is not allow-listed for the tenant.
	 *
	 * @spec openspec/specs/customer-portal/spec.md#requirement-widget-mode-requests-are-gated-by-the-tenant-s-origin-allow-list-req-portal-origin
	 */
	public function resolveTenant(IRequest $request): string;

	/**
	 * Hash of the client user agent (for audit).
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string The user-agent hash.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function userAgentHash(IRequest $request): string;
}//end interface
