<?php

/**
 * Contract of PortalDelegationService
 *
 * The public surface of PortalDelegationService that other components call, so they depend
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
 * Contract of PortalDelegationService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalDelegationServiceInterface {

	/**
	 * Grant a delegation from an authenticated granter to a colleague email.
	 *
	 * @param string $granterAccountId The authenticated granter (from session).
	 * @param string $tenantId The tenant id.
	 * @param string $granteeEmail The colleague's email.
	 * @param array<int, string> $scopes The requested scopes.
	 * @param string|null $validUntil Optional ISO-8601 expiry.
	 *
	 * @return array<string, mixed> The created delegation.
	 *
	 * @throws PortalException On invalid scopes or a self-grant.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function grant(
		string $granterAccountId,
		string $tenantId,
		string $granteeEmail,
		array $scopes,
		?string $validUntil,
	): array;

	/**
	 * List delegations granted by an account (its own grants only).
	 *
	 * @param string $granterAccountId The granter id.
	 *
	 * @return array<int, array<string, mixed>> The delegations.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function listGrantedBy(string $granterAccountId): array;

	/**
	 * Revoke a delegation owned by the authenticated granter.
	 *
	 * The granter id is verified against the stored delegation so a user can
	 * never revoke another account's grant.
	 *
	 * @param string $delegationId The delegation id.
	 * @param string $granterAccountId The authenticated granter.
	 * @param string $tenantId The tenant id.
	 *
	 * @return void
	 *
	 * @throws PortalException When the delegation is not the granter's.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function revoke(string $delegationId, string $granterAccountId, string $tenantId): void;
}//end interface
