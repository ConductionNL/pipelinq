<?php

/**
 * Contract of ReceiptDeliveryService
 *
 * The public surface of ReceiptDeliveryService that other components call, so they depend
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
 * Contract of ReceiptDeliveryService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReceiptDeliveryServiceInterface {

	/**
	 * Email a receipt to the transaction's customer.
	 *
	 * The recipient is NOT taken from arbitrary client input: it is the email
	 * stored on the transaction's linked customer/client, or — when the request
	 * supplies an address — only accepted if it matches that linked customer
	 * email. This prevents the endpoint being abused to send receipts (spam) to
	 * arbitrary addresses (REQ-PRE-002 / security constraint).
	 *
	 * SMTP delivery itself is environment-dependent; on a host without an SMTP
	 * relay the send throws and is recorded as a failed log entry. The send path
	 * is real — only the transport is environment-gated.
	 *
	 * @param string $transactionId The transaction UUID.
	 * @param string|null $templateId Optional template UUID.
	 * @param string|null $requestedRecipient Optional recipient the client asked for.
	 * @param string $userId The acting user UID (for the audit log).
	 *
	 * @return array<string, mixed> The result: status, emailRecipient, logId.
	 *
	 * @throws OCSNotFoundException If the transaction is not in this app's register.
	 * @throws OCSBadRequestException If the transaction is not receiptable or has no customer email.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	public function emailReceipt(
		string $transactionId,
		?string $templateId,
		?string $requestedRecipient,
		string $userId,
	): array;

	/**
	 * Render a receipt preview for a transaction (no side effects).
	 *
	 * @param string $transactionId The transaction UUID.
	 * @param string|null $templateId Optional template UUID.
	 * @param string $userId The acting user UID (must be able to access the transaction).
	 *
	 * @return array<string, mixed> The preview: text, html, isInvoice, invoiceNumber(null for preview).
	 *
	 * @throws OCSNotFoundException If the transaction is not in this app's register.
	 * @throws OCSForbiddenException If the caller may not access the transaction.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#4.1
	 */
	public function preview(string $transactionId, ?string $templateId = null, string $userId = ''): array;

	/**
	 * Produce the ESC/POS thermal byte stream for a transaction.
	 *
	 * Returns the bytes (base64-encoded for transport) plus the print log id.
	 * Live spooling to a device at a configured IP:port is environment-gated
	 * (no printer on a CI host) — the caller streams the bytes; here we generate
	 * and audit them.
	 *
	 * @param string $transactionId The transaction UUID.
	 * @param string|null $templateId Optional template UUID.
	 * @param string $userId The acting user UID (for the audit log).
	 *
	 * @return array<string, mixed> The result: status, escposBase64, byteLength, logId.
	 *
	 * @throws OCSNotFoundException If the transaction is not in this app's register.
	 * @throws OCSBadRequestException If the transaction is not receiptable.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	public function printReceipt(string $transactionId, ?string $templateId, string $userId): array;

	/**
	 * What the receipt card on a transaction page shows: the newest print and
	 * its printer, how often it was printed, the newest mail that was sent,
	 * and whether the sale prints as a receipt or an invoice.
	 *
	 * @param string $transactionId The transaction UUID.
	 * @param string $userId The acting user UID (must be able to access the transaction).
	 *
	 * @return array<string, mixed> printedAt, printerDevice, printCount, emailedAt, emailRecipient, layout, invoiceThreshold.
	 *
	 * @throws OCSNotFoundException If the transaction is not in this app's register.
	 * @throws OCSForbiddenException If the caller may not access the transaction.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md#requirement-a-transaction-page-shows-its-receipt-history
	 */
	public function receiptLog(string $transactionId, string $userId): array;
}//end interface
