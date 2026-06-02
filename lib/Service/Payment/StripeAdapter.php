<?php

/**
 * Pipelinq StripeAdapter.
 *
 * Payment provider adapter for Stripe (card, Apple Pay / Google Pay). Creates a
 * PaymentIntent on initiate (amount in minor units); webhooks are verified with
 * Stripe's `t=,v1=` scheme (HMAC-SHA256 over "timestamp.payload").
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
 * Stripe payment provider adapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
class StripeAdapter extends AbstractPaymentAdapter
{
    /**
     * Stripe API base URL.
     *
     * @var string
     */
    private const API_BASE = 'https://api.stripe.com/v1';

    /**
     * {@inheritDoc}
     *
     * @return string The provider name.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
     */
    public function getName(): string
    {
        return 'stripe';
    }//end getName()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $transactionData The transaction payload.
     * @param float                $amount          The amount in euros.
     * @param string               $paymentMethod   The normalized method.
     *
     * @return array{sessionId: string, redirectUrl: string|null, status: string, error: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
     */
    public function initiate(array $transactionData, float $amount, string $paymentMethod): array
    {
        $form = [
            'amount'                  => $this->toCents(amount: $amount),
            'currency'                => 'eur',
            'description'             => 'POS '.((string) ($transactionData['reference'] ?? '')),
            'metadata[transactionId]' => (string) ($transactionData['id'] ?? ''),
            'capture_method'          => 'automatic',
        ];

        try {
            $response = $this->jsonRequest(
                method: 'POST',
                url: self::API_BASE.'/payment_intents',
                headers: $this->authHeaders(),
                body: [],
                form: $form
            );
        } catch (PaymentApiException $e) {
            return ['sessionId' => '', 'redirectUrl' => null, 'status' => 'failed', 'error' => $e->getMessage()];
        }

        return [
            'sessionId'   => (string) ($response['id'] ?? ''),
            'redirectUrl' => null,
            'status'      => 'pending',
            'error'       => null,
        ];
    }//end initiate()

    /**
     * {@inheritDoc}
     *
     * @param string $sessionId The Stripe PaymentIntent id.
     *
     * @return array{sessionId: string, status: string, error: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
     */
    public function capture(string $sessionId): array
    {
        try {
            $response = $this->jsonRequest(
                method: 'POST',
                url: self::API_BASE.'/payment_intents/'.rawurlencode($sessionId).'/capture',
                headers: $this->authHeaders(),
                body: [],
                form: []
            );
        } catch (PaymentApiException $e) {
            return ['sessionId' => $sessionId, 'status' => 'failed', 'error' => $e->getMessage()];
        }

        $mapped = $this->mapStatus(providerStatus: (string) ($response['status'] ?? ''));
        $status = 'captured';
        if ($mapped === 'failed') {
            $status = 'failed';
        }

        return ['sessionId' => $sessionId, 'status' => $status, 'error' => null];
    }//end capture()

    /**
     * {@inheritDoc}
     *
     * @param string $sessionId The Stripe PaymentIntent id.
     * @param string $reason    The refund reason.
     *
     * @return array{sessionId: string, refundId: string, status: string, error: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
     */
    public function refund(string $sessionId, string $reason): array
    {
        try {
            $response = $this->jsonRequest(
                method: 'POST',
                url: self::API_BASE.'/refunds',
                headers: $this->authHeaders(),
                body: [],
                form: [
                    'payment_intent'   => $sessionId,
                    'metadata[reason]' => $reason,
                ]
            );
        } catch (PaymentApiException $e) {
            return ['sessionId' => $sessionId, 'refundId' => '', 'status' => 'failed', 'error' => $e->getMessage()];
        }

        return [
            'sessionId' => $sessionId,
            'refundId'  => (string) ($response['id'] ?? ''),
            'status'    => 'refunded',
            'error'     => null,
        ];
    }//end refund()

    /**
     * {@inheritDoc}
     *
     * Verifies Stripe's `Stripe-Signature` header: parses `t=` (timestamp) and
     * `v1=` (signature) parts, recomputes HMAC-SHA256 over "t.rawBody" with the
     * webhook secret and compares constant-time. Fails closed without a secret.
     *
     * @param string $rawBody   The raw request body.
     * @param string $signature The Stripe-Signature header value.
     *
     * @return bool Whether the signature is valid.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    public function validateWebhook(string $rawBody, string $signature): bool
    {
        $secret = $this->webhookSecret();
        if ($secret === '') {
            return false;
        }

        [$timestamp, $provided] = $this->parseStripeSignature(header: $signature);
        if ($timestamp === '' || $provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return $this->signatureEquals(expected: $expected, provided: $provided);
    }//end validateWebhook()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return array{sessionId: string, status: string, eventId: string}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    public function parseWebhook(array $payload): array
    {
        $object    = ($payload['data']['object'] ?? []);
        $sessionId = (string) ($object['payment_intent'] ?? $object['id'] ?? '');
        $eventType = (string) ($payload['type'] ?? '');
        $eventId   = (string) ($payload['id'] ?? ($sessionId.':'.$eventType));

        return [
            'sessionId' => $sessionId,
            'status'    => $this->mapEvent(eventType: $eventType, object: $object),
            'eventId'   => $eventId,
        ];
    }//end parseWebhook()

    /**
     * {@inheritDoc}
     *
     * @return array{status: string, message: string}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
     */
    public function testConnection(): array
    {
        if ($this->apiSecret() === '') {
            return ['status' => 'error', 'message' => 'Geen secret key ingesteld.'];
        }

        try {
            $this->jsonRequest(method: 'GET', url: self::API_BASE.'/balance', headers: $this->authHeaders());
        } catch (PaymentApiException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        return ['status' => 'ok', 'message' => 'Verbinding met Stripe geslaagd.'];
    }//end testConnection()

    /**
     * Map a Stripe PaymentIntent status to the normalized vocabulary.
     *
     * @param string $providerStatus The Stripe status.
     *
     * @return string The normalized status.
     */
    protected function mapStatus(string $providerStatus): string
    {
        switch ($providerStatus) {
            case 'succeeded':
                return 'settled';
            case 'requires_capture':
                return 'captured';
            case 'processing':
            case 'requires_action':
            case 'requires_confirmation':
            case 'requires_payment_method':
                return 'pending';
            default:
                return 'failed';
        }
    }//end mapStatus()

    /**
     * Map a Stripe event type to the normalized vocabulary.
     *
     * @param string               $eventType The Stripe event type.
     * @param array<string, mixed> $object    The event data object.
     *
     * @return string The normalized status.
     */
    private function mapEvent(string $eventType, array $object): string
    {
        switch ($eventType) {
            case 'payment_intent.succeeded':
            case 'charge.succeeded':
                return 'settled';
            case 'payment_intent.amount_capturable_updated':
                return 'captured';
            case 'charge.refunded':
            case 'refund.created':
                return 'refunded';
            case 'payment_intent.payment_failed':
            case 'charge.failed':
                return 'failed';
            default:
                return $this->mapStatus(providerStatus: (string) ($object['status'] ?? ''));
        }
    }//end mapEvent()

    /**
     * Parse a Stripe-Signature header into its timestamp and v1 signature.
     *
     * @param string $header The Stripe-Signature header value.
     *
     * @return array{0: string, 1: string} The [timestamp, v1Signature].
     */
    private function parseStripeSignature(string $header): array
    {
        $timestamp = '';
        $signature = '';
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }

            if ($pair[0] === 't') {
                $timestamp = $pair[1];
            }

            if ($pair[0] === 'v1') {
                $signature = $pair[1];
            }
        }

        return [$timestamp, $signature];
    }//end parseStripeSignature()

    /**
     * Build the Stripe Bearer-auth headers (form-encoded API).
     *
     * @return array<string, string> The headers.
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiSecret(),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ];
    }//end authHeaders()
}//end class
