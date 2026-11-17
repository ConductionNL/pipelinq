<?php

/**
 * Contract of AbstractPortalReadFacade
 *
 * The public surface of AbstractPortalReadFacade that other components call, so they depend
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
 * Contract of AbstractPortalReadFacade.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
interface PortalReadFacadeInterface {

	/**
	 * List the account's accessible rows, paginated and newest-first.
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
	 * Fetch a single accessible row by id, or null when not visible.
	 *
	 * Returns null (the caller maps to 404) for both "does not exist" and "not
	 * yours", so an id from another customer/tenant cannot be distinguished
	 * from a non-existent one (no existence leak).
	 *
	 * @param array<string, mixed> $account The authenticated account.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getOneForAccount(array $account, string $id): ?array;
}//end interface
