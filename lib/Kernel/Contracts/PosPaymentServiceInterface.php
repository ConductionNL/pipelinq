<?php

/**
 * Contract of PosPaymentService
 *
 * The public surface of PosPaymentService that other components call, so they depend
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
 * Contract of PosPaymentService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PosPaymentServiceInterface {

	/**
	 * IAppConfig key prefix for stored credentials.
	 *
	 * @var string
	 */
	public const CONFIG_PREFIX = 'payment_provider.';

	/**
	 * Provider names supported by this app.
	 *
	 * @var array<int, string>
	 */
	public const PROVIDERS = ['mollie', 'ccv', 'adyen', 'stripe'];

	/**
	 * Credential-field names that used to be stored here and no longer may be.
	 *
	 * Kept only so the repair step and the update path can recognise — and refuse —
	 * them. Nothing reads their values.
	 *
	 * @var array<int, string>
	 */
	public const RETIRED_SECRET_FIELDS = ['apiKey', 'apiSecret'];

	/**
	 * Capture an authorized payment.
	 *
	 * @param string $transactionId The transaction id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
	 */
	public function capturePayment(string $transactionId): array;

	/**
	 * Get a single provider config (credentials masked).
	 *
	 * @param string $name The provider name.
	 * @param bool $includeMaskedSecrets When true, sensitive fields are returned as MASK if set, '' if not.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) toggles whether sensitive
	 *  fields are present at all (mask vs omit), not a behavioural branch
	 *  worth splitting into two public methods.
	 */
	public function getProviderConfig(string $name, bool $includeMaskedSecrets = true): array;

	/**
	 * Validate + route a provider webhook.
	 *
	 * Signature failure returns `{ status: invalid }` → controller maps to
	 * STATUS_BAD_REQUEST (gate-9 webhook convention).
	 *
	 * The status vocabulary is the controller's HTTP contract, so each value
	 * states whether the delivery was consumed:
	 *   - `ok` / `duplicate` — consumed, answered 2xx, provider stops.
	 *   - `invalid`          — the delivery is not ours (bad signature or an
	 *                          unknown provider); 400, never retried.
	 *   - `ignored`          — well-formed but carries no session id, so there
	 *                          is nothing to match and a redelivery of the same
	 *                          bytes can never succeed; 2xx by design.
	 *   - `unmatched`        — signed, carries a session id, and nothing was
	 *                          persisted. MUST be answered with a retryable
	 *                          5xx (pipelinq#799).
	 *
	 * @param string $providerName The provider name.
	 * @param string $rawPayload The raw request body.
	 * @param string $signature The signature header value.
	 *
	 * @return array<string, mixed> { status: ok|invalid|ignored|unmatched|duplicate, transactionId?, error? }
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 */
	public function handleWebhook(string $providerName, string $rawPayload, string $signature): array;

	/**
	 * Initiate a payment for a transaction.
	 *
	 * @param string $transactionId The transaction id.
	 * @param string $providerName The provider name.
	 * @param string $paymentMethod The method (ideal, card, ...).
	 *
	 * @return array<string, mixed> { transaction, payment: { sessionId, redirectUrl, status } }
	 *
	 * @throws OCSNotFoundException When the transaction is not found.
	 * @throws OCSBadRequestException When provider or amount is invalid.
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
	 */
	public function initiatePayment(string $transactionId, string $providerName, string $paymentMethod): array;

	/**
	 * The payment methods a cashier can choose: one entry per method of every
	 * active provider. Carries no credential and no config beyond the names, so
	 * any signed-in user may read it.
	 *
	 * A provider's methods are the `methods` list in its config, else
	 * {@see self::DEFAULT_METHODS}.
	 *
	 * @return array<int, array{provider: string, providerLabel: string, type: string, method: string}>
	 *
	 * @spec openspec/specs/pos-payment-methods/spec.md#requirement-a-cashier-reads-the-payment-methods-of-the-active-providers
	 */
	public function listPaymentMethods(): array;

	/**
	 * List all providers with masked credentials.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function listProviders(): array;

	/**
	 * Refund a settled (or captured) payment — manager only.
	 *
	 * @param string $transactionId The transaction id.
	 * @param string $reason The refund reason.
	 * @param string $userId The acting user id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OCSForbiddenException When the caller is not a POS manager.
	 * @throws OCSBadRequestException When the transaction is not refundable, or no reason is given.
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
	 * @spec openspec/specs/pos-payment-methods/spec.md#requirement-a-payment-is-reversed-only-with-a-reason
	 */
	public function refundPayment(string $transactionId, string $reason, string $userId): array;

	/**
	 * Test the configured connection for a provider.
	 *
	 * @param string $name The provider name.
	 *
	 * @return array{status: string, message: string}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function testConnection(string $name): array;

	/**
	 * Update a provider configuration (admin-only — controller enforces).
	 *
	 * Sensitive fields are encrypted before storage; non-sensitive scalars
	 * and the config object are stored verbatim. The MASK value is ignored
	 * so an admin saving the form without re-entering the secret does not
	 * blank it.
	 *
	 * @param string $name The provider name.
	 * @param array<string, mixed> $data The form payload.
	 *
	 * @return array<string, mixed> The updated, masked config.
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-002
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function updateProvider(string $name, array $data): array;
}//end interface
