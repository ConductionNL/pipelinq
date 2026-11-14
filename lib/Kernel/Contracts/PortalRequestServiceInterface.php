<?php

/**
 * Contract of PortalRequestService
 *
 * The public surface of PortalRequestService that other components call, so they depend
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
 * Contract of PortalRequestService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalRequestServiceInterface {

	/**
	 * Add a customer reply note to a visible request, unpausing it when it was
	 * awaiting the customer.
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param string $tenantId The tenant id.
	 * @param string $requestId The request id.
	 * @param string $message The reply text.
	 *
	 * @return array<string, mixed> The updated request detail.
	 *
	 * @throws PortalException When the request is not visible or message empty.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function addReply(array $account, string $tenantId, string $requestId, string $message): array;

	/**
	 * Read one request's customer-safe detail (internal notes stripped), or
	 * null when the request is not visible to the account.
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param string $requestId The request id.
	 * @param bool $exposeAssigneeName Whether the tenant exposes assignees.
	 *
	 * @return array<string, mixed>|null The detail, or null.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getDetailForAccount(array $account, string $requestId, bool $exposeAssigneeName): ?array;

	/**
	 * List the account's own + delegated-submit requests, newest-first,
	 * paginated. Notes are summarised out of the list (detail only).
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param int $page The 1-based page.
	 * @param int $perPage The page size.
	 *
	 * @return array{total: int, page: int, perPage: int, items: array<int, array<string, mixed>>}
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getForAccount(array $account, int $page = 1, int $perPage = 10): array;

	/**
	 * What the resident portal shows of a request ticket the caller already
	 * read, for the back-office preview on TicketDetail.
	 *
	 * It is `presentDetail()` unchanged, so the preview cannot drift from the
	 * portal. A complaint or interaction ticket is not served by the portal
	 * and has no preview (null).
	 *
	 * @param array<string, mixed> $ticket             The ticket, as read by the caller.
	 * @param bool                 $exposeAssigneeName Whether the portal shows the handler's name.
	 *
	 * @return array<string, mixed>|null The resident's view, or null for a non-request.
	 *
	 * @spec openspec/specs/resident-view-preview/spec.md#requirement-a-request-ticket-previews-the-residents-view-req-rvp-001
	 */
	public function previewDetail(array $ticket, bool $exposeAssigneeName): ?array;

	/**
	 * Submit a new request from the portal.
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param string $tenantId The tenant id.
	 * @param string $subject The request subject.
	 * @param string $body The request body.
	 * @param array<int, array<string,mixed>> $attachments Attachment descriptors ({id, size}).
	 * @param string $categoryId The chosen category id.
	 *
	 * @return array<string, mixed> The created request summary + ETA.
	 *
	 * @throws PortalException On validation / rate-limit failure.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function submit(
		array $account,
		string $tenantId,
		string $subject,
		string $body,
		array $attachments,
		string $categoryId,
	): array;
}//end interface
