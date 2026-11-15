<?php

/**
 * Contract of PosCustomerLinkService
 *
 * The public surface of PosCustomerLinkService that other components call, so they depend
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
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Contract of PosCustomerLinkService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PosCustomerLinkServiceInterface {

	/**
	 * Default search-result limit when the caller does not specify one.
	 *
	 * @var int
	 */
	public const DEFAULT_SEARCH_LIMIT = 20;

	/**
	 * Default purchase-history depth (configurable via admin settings).
	 *
	 * @var int
	 */
	public const DEFAULT_HISTORY_LIMIT = 10;

	/**
	 * Attach a customer (and optionally capture marketing consent) to a draft transaction.
	 *
	 * Validates the contact exists, that the transaction is mutable (draft /
	 * parked — the lifecycle owns the rest), and writes the customer +
	 * marketingConsent fields. Consent sync to the linked contact is fired
	 * fire-and-forget after the transaction save so a consent-PATCH failure
	 * never reverts the attachment.
	 *
	 * @param string $transactionId The POS transaction UUID.
	 * @param string $contactUuid The contact UUID to attach.
	 * @param bool $marketingConsent Whether the cashier captured opt-in.
	 *
	 * @return array<string, mixed> The updated transaction.
	 *
	 * @throws OCSNotFoundException When the transaction or contact is missing.
	 * @throws OCSBadRequestException When the transaction is not in a mutable state.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) marketingConsent is the
	 *  GDPR opt-in state captured by the same UI action that attaches the
	 *  customer; splitting it into a setter would force the controller to
	 *  make two round-trips for one logical write.
	 *
	 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-002
	 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-004
	 */
	public function attachCustomer(
		string $transactionId,
		string $contactUuid,
		bool $marketingConsent = false,
	): array;

	/**
	 * Detach a customer from a draft / parked transaction.
	 *
	 * The corresponding contact is NOT mutated — detach is a local
	 * transaction-level operation only. Marketing consent stored on the
	 * transaction is reset to false because consent was given for that
	 * specific sale; the previously-captured value on the contact (if any)
	 * is preserved.
	 *
	 * @param string $transactionId The POS transaction UUID.
	 *
	 * @return array<string, mixed> The updated transaction.
	 *
	 * @throws OCSNotFoundException When the transaction is missing.
	 * @throws OCSBadRequestException When the transaction is not mutable.
	 *
	 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-002
	 */
	public function detachCustomer(string $transactionId): array;

	/**
	 * Fetch the purchase history (most recent confirmed / settled / refunded
	 * transactions) for a customer.
	 *
	 * Drafts and parked carts are excluded because they are not real sales.
	 * The list is sorted descending by createdAt and capped at the
	 * admin-configured history depth (default 10, max 50).
	 *
	 * @param string $contactUuid The contact UUID.
	 * @param int $limit Max history rows (defaults to admin setting).
	 *
	 * @return array<int, array<string, mixed>> The history rows.
	 *
	 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-003
	 */
	public function getCustomerHistory(string $contactUuid, int $limit = self::DEFAULT_HISTORY_LIMIT): array;

	/**
	 * Search the pipelinq contact register for customers matching a query.
	 *
	 * Filters by admin-configurable search fields (name, email, phone). The
	 * query is matched as a case-insensitive substring against each enabled
	 * field; the union of matches is returned. Results are decorated with
	 * `doNotContact` (privacy flag) and `marketingConsent` so the UI can
	 * surface the indicator described in REQ-PCL-007 Scenario 1.
	 *
	 * @param string $query The search query (>= 2 chars).
	 * @param int $limit Max results (defaults to DEFAULT_SEARCH_LIMIT).
	 *
	 * @return array<int, array<string, mixed>> Decorated contact rows.
	 *
	 * @throws OCSBadRequestException When the query is too short.
	 *
	 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-001
	 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-006
	 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-007
	 */
	public function searchCustomers(string $query, int $limit = self::DEFAULT_SEARCH_LIMIT): array;
}//end interface
