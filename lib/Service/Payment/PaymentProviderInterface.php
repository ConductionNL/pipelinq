<?php

/**
 * Pipelinq PaymentProviderInterface.
 *
 * Abstract contract every POS payment provider adapter implements (Mollie,
 * CCV, Adyen, Stripe). Money is always integer-cent on the wire to the
 * provider — implementations convert the canonical decimal-EUR amount that
 * PosPaymentService passes in. Validation methods MUST return booleans and
 * never throw; the controller layer maps a false return to STATUS_BAD_REQUEST.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Payment
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

/**
 * Payment provider adapter contract.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
interface PaymentProviderInterface {
	/**
	 * Inject the HTTP transport this provider makes its outbound calls through.
	 *
	 * Part of the contract, not an implementation detail: `PosPaymentService` attaches a
	 * {@see BrokerHttpTransport} here, and that attachment is the ONLY thing standing
	 * between an adapter and a direct, app-authenticated PSP call. An implementation that
	 * did not honour it would quietly keep its own transport — and its own key.
	 *
	 * @param HttpTransport|null $transport The transport, or null to reset to the default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-1-brokerhttptransport
	 */
	public function setHttpTransport(?HttpTransport $transport): void;

	/**
	 * Initiate a payment session with the provider.
	 *
	 * @param array<string, mixed> $transactionData The posTransaction data (reference, total etc.).
	 * @param float $amount The amount in EUR (decimal — implementation converts to cents).
	 * @param string $paymentMethod The normalised method name (ideal, card, bancontact, ...).
	 *
	 * @return array{sessionId: string, redirectUrl: string|null, status: string, error?: string} The session.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
	 */
	public function initiate(array $transactionData, float $amount, string $paymentMethod): array;

	/**
	 * Capture a previously-authorized payment session.
	 *
	 * @param string $sessionId The provider session id from initiate().
	 *
	 * @return array{sessionId: string, status: string, error?: string|null} The capture result.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
	 */
	public function capture(string $sessionId): array;

	/**
	 * Refund a settled payment session.
	 *
	 * @param string $sessionId The provider session id.
	 * @param string $reason Human-readable refund reason.
	 *
	 * @return array{sessionId: string, refundId: string, status: string, error?: string|null} The refund result.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
	 */
	public function refund(string $sessionId, string $reason): array;

	/**
	 * Validate an inbound webhook payload against the provider's signature.
	 *
	 * Implementations MUST return a boolean — never throw — so the webhook
	 * controller can map a false return to STATUS_BAD_REQUEST without leaking
	 * crypto details. The webhook secret is decrypted by PosPaymentService and
	 * injected via the constructor.
	 *
	 * @param string $rawPayload The raw request body (signed bytes).
	 * @param string $signature The signature header value from the provider.
	 *
	 * @return bool True when the signature validates.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 */
	public function validateWebhook(string $rawPayload, string $signature): bool;

	/**
	 * Parse a validated webhook payload into a normalised settlement update.
	 *
	 * @param array<string, mixed> $payload The decoded webhook JSON.
	 *
	 * @return array{sessionId: string, status: string, eventId: string, error: string|null} Normalised settlement data.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 */
	public function parseWebhook(array $payload): array;

	/**
	 * Test the provider connection (sandbox or live).
	 *
	 * @return array{status: string, message: string} The test result.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function testConnection(): array;

	/**
	 * The canonical provider name (mollie, ccv, adyen, stripe).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	public function getName(): string;
}//end interface
