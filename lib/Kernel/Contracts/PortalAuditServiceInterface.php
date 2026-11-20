<?php

/**
 * Contract of PortalAuditService
 *
 * The public surface of PortalAuditService that other components call, so they depend
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
 * Contract of PortalAuditService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAuditServiceInterface {

	/**
	 * List audit events for a single account (the account's own trail).
	 *
	 * @param string $accountId The account id.
	 *
	 * @return array<int, array<string, mixed>> The events, newest first.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getForAccount(string $accountId): array;

	/**
	 * List all audit events for a tenant (DPO / admin access).
	 *
	 * @param string $tenantId The tenant id.
	 *
	 * @return array<int, array<string, mixed>> The events, newest first.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getForTenant(string $tenantId): array;

	/**
	 * The latest audit events of every tenant, newest first, and how many
	 * there are in all.
	 *
	 * @param int $limit The most events to return.
	 *
	 * @return array{events: array<int, array<string, mixed>>, total: int} The events and the total.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-shows-the-latest-portal-actions-across-portals
	 */
	public function getRecent(int $limit): array;

	/**
	 * Append a single audit event. Never throws: an audit-write failure must
	 * not break the action being audited, but it is logged to the server log
	 * so the gap is visible.
	 *
	 * @param string|null $accountId The acting portal account id.
	 * @param string $tenantId The tenant id.
	 * @param string $eventType The event type (e.g. login-success).
	 * @param string $outcome The outcome (success|denied|error|pending-verification).
	 * @param array<string, mixed> $details Event-specific structured detail.
	 *
	 * @return void
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function log(
		?string $accountId,
		string $tenantId,
		string $eventType,
		string $outcome,
		array $details = [],
	): void;
}//end interface
