<?php

/**
 * Pipelinq CcvAdapter.
 *
 * Payment provider adapter for CCV (Dutch PIN terminal standard). Terminal
 * flow: initiate sends a payment to the configured PIN device (no redirect URL);
 * the customer completes the PIN on the device and CCV posts a settlement
 * webhook. Webhook signatures use HMAC-SHA512 over merchantId-prefixed body.
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
 * CCV PIN terminal payment provider adapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
class CcvAdapter extends AbstractPaymentAdapter
{
    /**
     * CCV Gateway API base URL.
     *
     * @var string
     */
    private const API_BASE = 'https://api.psp.ccv.eu/v1';

    /**
     * {@inheritDoc}
     *
     * @return string The provider name.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
     */
    public function getName(): string
    {
        return 'ccv';
    }//end getName()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $transactionData The transaction payload.
     * @param float                $amount          The amount in euros.
     * @param string               $paymentMethod   The normalized method (card).
     *
     * @return array{sessionId: string, redirectUrl: string|null, status: string, error: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
     */
    public function initiate(array $transactionData, float $amount, string $paymentMethod): array
    {
        $body = [
            'amount'     => (float) $this->formatAmount(amount: $amount),
            'currency'   => 'EUR',
            'method'     => 'card',
            'terminalId' => $this->configValue(key: 'terminalId', fallback: 'kassa-01'),
            'reference'  => (string) ($transactionData['reference'] ?? ''),
            'metadata'   => ['transactionId' => (string) ($transactionData['id'] ?? '')],
        ];

        try {
            $response = $this->jsonRequest(
                method: 'POST',
                url: self::API_BASE.'/payment',
                headers: $this->authHeaders(),
                body: $body
            );
        } catch (PaymentApiException $e) {
            return ['sessionId' => '', 'redirectUrl' => null, 'status' => 'failed', 'error' => $e->getMessage()];
        }

        return [
            'sessionId'   => (string) ($response['reference'] ?? $response['id'] ?? ''),
            'redirectUrl' => null,
            'status'      => 'pending',
            'error'       => null,
        ];
    }//end initiate()

    /**
     * {@inheritDoc}
     *
     * @param string $sessionId The CCV transaction reference.
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
                url: self::API_BASE.'/payment/'.rawurlencode($sessionId),
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
     * @param string $sessionId The CCV transaction reference.
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
                url: self::API_BASE.'/payment/'.rawurlencode($sessionId).'/refund',
                headers: $this->authHeaders(),
                body: ['reason' => $reason]
            );
        } catch (PaymentApiException $e) {
            return ['sessionId' => $sessionId, 'refundId' => '', 'status' => 'failed', 'error' => $e->getMessage()];
        }

        return [
            'sessionId' => $sessionId,
            'refundId'  => (string) ($response['refundId'] ?? $response['id'] ?? ''),
            'status'    => 'refunded',
            'error'     => null,
        ];
    }//end refund()

    /**
     * {@inheritDoc}
     *
     * CCV signs with HMAC-SHA512 over the merchantId-prefixed raw body, per the
     * CCV Gateway webhook specification. Fails closed when no secret is set.
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

        $merchantId = $this->configValue(key: 'merchantId');
        $expected   = hash_hmac('sha512', $merchantId.$rawBody, $secret);

        return $this->signatureEquals(expected: $expected, provided: $signature);
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
        $sessionId = (string) ($payload['reference'] ?? $payload['id'] ?? '');
        $status    = $this->mapStatus(providerStatus: (string) ($payload['status'] ?? ''));
        $eventId   = (string) ($payload['eventId'] ?? ($sessionId.':'.((string) ($payload['status'] ?? ''))));

        return ['sessionId' => $sessionId, 'status' => $status, 'eventId' => $eventId];
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
            $this->jsonRequest(method: 'GET', url: self::API_BASE.'/terminal', headers: $this->authHeaders());
        } catch (PaymentApiException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        return ['status' => 'ok', 'message' => 'Verbinding met CCV geslaagd.'];
    }//end testConnection()

    /**
     * Map a CCV payment status to the normalized paymentStatus vocabulary.
     *
     * @param string $providerStatus The CCV status.
     *
     * @return string The normalized status.
     */
    protected function mapStatus(string $providerStatus): string
    {
        switch (strtolower($providerStatus)) {
            case 'success':
            case 'settled':
            case 'completed':
                return 'settled';
            case 'authorised':
            case 'authorized':
                return 'captured';
            case 'pending':
            case 'started':
                return 'pending';
            case 'refunded':
                return 'refunded';
            default:
                return 'failed';
        }
    }//end mapStatus()

    /**
     * Build the CCV Basic-auth headers (API key as username).
     *
     * @return array<string, string> The headers.
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Basic '.base64_encode($this->apiKey().':'),
            'Content-Type'  => 'application/json',
        ];
    }//end authHeaders()
}//end class
