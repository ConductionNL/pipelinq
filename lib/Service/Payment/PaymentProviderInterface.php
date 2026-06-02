<?php

/**
 * Pipelinq PaymentProviderInterface.
 *
 * Pluggable adapter contract for POS payment providers. Concrete adapters
 * (Mollie, CCV, Adyen, Stripe) wrap a provider's HTTP API behind a uniform,
 * normalized contract so PosPaymentService is never coupled to a single PSP.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Payment
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

/**
 * Contract every POS payment provider adapter implements.
 *
 * Adapters are server-side only: they receive already-decrypted credentials
 * from PosPaymentService (which loads them from IAppConfig), make outbound HTTP
 * calls through an injected OCP HTTP client, and return normalized result arrays.
 * They never throw on a provider error: a failed call returns a result array
 * with `status => 'failed'` and a user-safe `error` message (no secrets, no
 * stack traces). Webhook validation returns a strict bool and is the security
 * boundary for the public webhook endpoint.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
interface PaymentProviderInterface
{
    /**
     * The canonical provider name (mollie, ccv, adyen, stripe).
     *
     * @return string The provider name.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
     */
    public function getName(): string;

    /**
     * Initiate a payment for a transaction.
     *
     * @param array<string, mixed> $transactionData The posTransaction payload (reference, total, ...).
     * @param float                $amount          The amount to charge, in major units (euros).
     * @param string               $paymentMethod   The normalized method (ideal, bancontact, card, ...).
     *
     * @return array{sessionId: string, redirectUrl: string|null, status: string, error: string|null}
     *               sessionId: provider session reference; redirectUrl: hosted-payment URL or null
     *               (terminal flows); status: 'pending'|'failed'; error: user-safe message or null.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
     */
    public function initiate(array $transactionData, float $amount, string $paymentMethod): array;

    /**
     * Capture (finalize) a previously authorized payment.
     *
     * @param string $sessionId The provider session reference from initiate().
     *
     * @return array{sessionId: string, status: string, error: string|null}
     *               status: 'captured'|'failed'.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
     */
    public function capture(string $sessionId): array;

    /**
     * Refund a settled payment.
     *
     * @param string $sessionId The provider session reference.
     * @param string $reason    The refund reason (free text).
     *
     * @return array{sessionId: string, refundId: string, status: string, error: string|null}
     *               status: 'refunded'|'failed'.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
     */
    public function refund(string $sessionId, string $reason): array;

    /**
     * Validate a webhook signature against the configured webhook secret.
     *
     * MUST be constant-time and MUST return strictly true/false (never throw).
     * This is the authentication boundary for the public webhook endpoint: a
     * false result causes PosPaymentService to reject the webhook (HTTP 401)
     * without mutating any transaction.
     *
     * @param string $rawBody   The exact raw request body bytes the provider signed.
     * @param string $signature The signature presented in the provider's header.
     *
     * @return bool True when the signature is valid for the configured secret.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    public function validateWebhook(string $rawBody, string $signature): bool;

    /**
     * Parse a validated webhook body into a normalized settlement descriptor.
     *
     * Webhook bodies are NOT trusted for payment state: for thin-notification
     * providers (Mollie) the adapter re-fetches authoritative status from the
     * provider API using the id in the body. The returned status is normalized
     * to the posTransaction paymentStatus vocabulary.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return array{sessionId: string, status: string, eventId: string}
     *               sessionId: the session to match a transaction on; status one of
     *               pending|captured|settled|failed|refunded; eventId: a stable id
     *               for idempotency (empty string when the provider gives none).
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    public function parseWebhook(array $payload): array;

    /**
     * Test connectivity / credential validity with the provider.
     *
     * @return array{status: string, message: string} status: 'ok'|'error'.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
     */
    public function testConnection(): array;
}//end interface
