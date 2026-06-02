<?php

/**
 * Pipelinq MollieAdapter.
 *
 * Payment provider adapter for Mollie (iDEAL, Bancontact, card). Online,
 * redirect-based flow: initiate creates a Mollie payment and returns the hosted
 * checkout URL; settlement arrives as a thin webhook whose body is NOT trusted —
 * parseWebhook re-fetches the authoritative status from the Mollie API.
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
 * Mollie payment provider adapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
class MollieAdapter extends AbstractPaymentAdapter
{
    /**
     * Mollie API base URL.
     *
     * @var string
     */
    private const API_BASE = 'https://api.mollie.com/v2';

    /**
     * {@inheritDoc}
     *
     * @return string The provider name.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
     */
    public function getName(): string
    {
        return 'mollie';
    }//end getName()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $transactionData The transaction payload.
     * @param float                $amount          The amount in euros.
     * @param string               $paymentMethod   The normalized method (ideal/bancontact/card).
     *
     * @return array{sessionId: string, redirectUrl: string|null, status: string, error: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
     */
    public function initiate(array $transactionData, float $amount, string $paymentMethod): array
    {
        $body = [
            'amount'      => [
                'currency' => 'EUR',
                'value'    => $this->formatAmount(amount: $amount),
            ],
            'description' => 'POS '.((string) ($transactionData['reference'] ?? 'transactie')),
            'redirectUrl' => (string) ($transactionData['redirectUrl'] ?? 'https://localhost/'),
            'method'      => $this->mapMethod(method: $paymentMethod),
            'metadata'    => ['transactionId' => (string) ($transactionData['id'] ?? '')],
        ];

        try {
            $response = $this->jsonRequest(
                method: 'POST',
                url: self::API_BASE.'/payments',
                headers: $this->authHeaders(),
                body: $body
            );
        } catch (PaymentApiException $e) {
            return ['sessionId' => '', 'redirectUrl' => null, 'status' => 'failed', 'error' => $e->getMessage()];
        }

        $checkoutUrl = (string) ($response['_links']['checkout']['href'] ?? '');
        $redirectUrl = null;
        if ($checkoutUrl !== '') {
            $redirectUrl = $checkoutUrl;
        }

        return [
            'sessionId'   => (string) ($response['id'] ?? ''),
            'redirectUrl' => $redirectUrl,
            'status'      => 'pending',
            'error'       => null,
        ];
    }//end initiate()

    /**
     * {@inheritDoc}
     *
     * Mollie settles asynchronously via webhook; an explicit capture is a status
     * re-fetch confirming the payment is at least authorized.
     *
     * @param string $sessionId The Mollie payment id.
     *
     * @return array{sessionId: string, status: string, error: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
     */
    public function capture(string $sessionId): array
    {
        try {
            $response = $this->jsonRequest(
                method: 'GET',
                url: self::API_BASE.'/payments/'.rawurlencode($sessionId),
                headers: $this->authHeaders()
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
     * @param string $sessionId The Mollie payment id.
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
                url: self::API_BASE.'/payments/'.rawurlencode($sessionId).'/refunds',
                headers: $this->authHeaders(),
                body: ['description' => $reason]
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
     * Mollie webhooks are not signed by default; this app requires a configured
     * shared webhook secret and validates an HMAC-SHA256 of the raw body so an
     * attacker cannot forge a settlement. With no secret configured, validation
     * fails closed (the webhook is rejected).
     *
     * @param string $rawBody   The raw request body.
     * @param string $signature The presented signature.
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

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return $this->signatureEquals(expected: $expected, provided: $signature);
    }//end validateWebhook()

    /**
     * {@inheritDoc}
     *
     * Mollie's webhook body only carries the payment id; the authoritative
     * status is re-fetched from the API (never trusted from the body).
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return array{sessionId: string, status: string, eventId: string}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    public function parseWebhook(array $payload): array
    {
        $sessionId = (string) ($payload['id'] ?? '');
        if ($sessionId === '') {
            return ['sessionId' => '', 'status' => 'pending', 'eventId' => ''];
        }

        try {
            $response = $this->jsonRequest(
                method: 'GET',
                url: self::API_BASE.'/payments/'.rawurlencode($sessionId),
                headers: $this->authHeaders()
            );
        } catch (PaymentApiException $e) {
            return ['sessionId' => $sessionId, 'status' => 'pending', 'eventId' => ''];
        }

        return [
            'sessionId' => $sessionId,
            'status'    => $this->mapStatus(providerStatus: (string) ($response['status'] ?? '')),
            'eventId'   => $sessionId.':'.((string) ($response['status'] ?? '')),
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
        if ($this->apiKey() === '') {
            return ['status' => 'error', 'message' => 'Geen API-sleutel ingesteld.'];
        }

        try {
            $this->jsonRequest(method: 'GET', url: self::API_BASE.'/methods', headers: $this->authHeaders());
        } catch (PaymentApiException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        return ['status' => 'ok', 'message' => 'Verbinding met Mollie geslaagd.'];
    }//end testConnection()

    /**
     * Map a Mollie payment status to the normalized paymentStatus vocabulary.
     *
     * @param string $providerStatus The Mollie status.
     *
     * @return string The normalized status.
     */
    protected function mapStatus(string $providerStatus): string
    {
        switch ($providerStatus) {
            case 'paid':
                return 'settled';
            case 'authorized':
                return 'captured';
            case 'pending':
            case 'open':
                return 'pending';
            case 'refunded':
                return 'refunded';
            default:
                // Expired, canceled, failed all map to failed.
                return 'failed';
        }
    }//end mapStatus()

    /**
     * Map a normalized method name to Mollie's method identifier.
     *
     * @param string $method The normalized method.
     *
     * @return string|null The Mollie method, or null to let Mollie present all.
     */
    private function mapMethod(string $method): ?string
    {
        switch ($method) {
            case 'ideal':
                return 'ideal';
            case 'bancontact':
                return 'bancontact';
            case 'card':
                return 'creditcard';
            default:
                return null;
        }
    }//end mapMethod()

    /**
     * Build the Mollie Bearer-auth headers.
     *
     * @return array<string, string> The headers.
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey(),
            'Content-Type'  => 'application/json',
        ];
    }//end authHeaders()
}//end class
