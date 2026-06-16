<?php

/**
 * Pipelinq MollieAdapter.
 *
 * Mollie integration for the POS payment provider seam: iDEAL, Bancontact and
 * credit card payments. Money is always sent as `nn.nn` EUR decimal (Mollie's
 * Orders API contract) — we keep an integer-cent internal representation and
 * format only at the wire boundary so the rounding semantics are auditable.
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

use Throwable;

/**
 * Mollie payment adapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 */
class MollieAdapter extends AbstractPaymentAdapter
{
    /**
     * Mollie API base URL.
     *
     * @var string
     */
    private const BASE_URL = 'https://api.mollie.com/v2';

    /**
     * The canonical provider name.
     *
     * @return string
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
     */
    public function getName(): string
    {
        return 'mollie';
    }//end getName()

    /**
     * Initiate a Mollie payment.
     *
     * @param array<string, mixed> $transactionData The transaction data.
     * @param float                $amount          The amount in EUR.
     * @param string               $paymentMethod   The method (ideal, bancontact, creditcard).
     *
     * @return array{sessionId: string, redirectUrl: string|null, status: string, error?: string}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
     */
    public function initiate(array $transactionData, float $amount, string $paymentMethod): array
    {
        $apiKey = $this->credential(key: 'apiKey');
        if ($apiKey === '') {
            return $this->failedInitiate(message: 'Mollie API key not configured');
        }

        $cents = $this->toCents(amount: $amount);
        if ($cents <= 0) {
            return $this->failedInitiate(message: 'Invalid amount');
        }

        $reference = (string) ($transactionData['reference'] ?? '');
        $label     = 'transactie';
        if ($reference !== '') {
            $label = $reference;
        }

        $description = sprintf('Pipelinq POS %s', $label);

        $payload = [
            'amount'      => [
                'currency' => 'EUR',
                'value'    => $this->centsToDecimal(cents: $cents),
            ],
            'description' => $description,
            'method'      => $this->mapMethod(method: $paymentMethod),
            'metadata'    => [
                'reference'     => $reference,
                'transactionId' => (string) ($transactionData['id'] ?? ''),
                'app'           => 'pipelinq',
            ],
        ];

        $rawBody = (string) json_encode($payload);

        try {
            $response = $this->transport()->request(
                method: 'POST',
                url: self::BASE_URL.'/payments',
                headers: [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type'  => 'application/json',
                ],
                body: $rawBody
            );
        } catch (Throwable $e) {
            $this->logProviderError(context: 'initiate failed', e: $e);
            return $this->failedInitiate(message: 'Verbinding met betalingsprovider verbroken');
        }//end try

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->failedInitiate(message: 'Mollie returned HTTP '.$response['status']);
        }

        $body        = $response['body'];
        $sessionId   = (string) ($body['id'] ?? '');
        $redirectUrl = null;
        if (isset($body['_links']['checkout']['href']) === true) {
            $redirectUrl = (string) $body['_links']['checkout']['href'];
        }

        return [
            'sessionId'   => $sessionId,
            'redirectUrl' => $redirectUrl,
            'status'      => 'pending',
        ];
    }//end initiate()

    /**
     * Capture is a no-op for Mollie — settles via webhook.
     *
     * @param string $sessionId The session id.
     *
     * @return array{sessionId: string, status: string, error?: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
     */
    public function capture(string $sessionId): array
    {
        // Mollie payments auto-settle on customer completion; capture is a
        // status read so the caller can mirror the latest state.
        $apiKey = $this->credential(key: 'apiKey');
        if ($apiKey === '' || $sessionId === '') {
            return $this->failedCapture(sessionId: $sessionId, message: 'Mollie API key or session missing');
        }

        try {
            $response = $this->transport()->request(
                method: 'GET',
                url: self::BASE_URL.'/payments/'.urlencode($sessionId),
                headers: ['Authorization' => 'Bearer '.$apiKey],
                body: null
            );
        } catch (Throwable $e) {
            $this->logProviderError(context: 'capture failed', e: $e);
            return $this->failedCapture(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
        }//end try

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->failedCapture(sessionId: $sessionId, message: 'Mollie returned HTTP '.$response['status']);
        }

        $status = (string) ($response['body']['status'] ?? 'pending');
        $mapped = $this->mapStatus(providerStatus: $status);

        return [
            'sessionId' => $sessionId,
            'status'    => $mapped,
            'error'     => null,
        ];
    }//end capture()

    /**
     * Refund a Mollie payment.
     *
     * @param string $sessionId The session id.
     * @param string $reason    The reason.
     *
     * @return array{sessionId: string, refundId: string, status: string, error?: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
     */
    public function refund(string $sessionId, string $reason): array
    {
        $apiKey = $this->credential(key: 'apiKey');
        if ($apiKey === '' || $sessionId === '') {
            return $this->failedRefund(sessionId: $sessionId, message: 'Mollie API key or session missing');
        }

        $description = 'Refund via Pipelinq POS';
        if ($reason !== '') {
            $description = $reason;
        }

        $payload = ['description' => $description];
        $rawBody = (string) json_encode($payload);

        try {
            $response = $this->transport()->request(
                method: 'POST',
                url: self::BASE_URL.'/payments/'.urlencode($sessionId).'/refunds',
                headers: [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type'  => 'application/json',
                ],
                body: $rawBody
            );
        } catch (Throwable $e) {
            $this->logProviderError(context: 'refund failed', e: $e);
            return $this->failedRefund(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
        }//end try

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->failedRefund(sessionId: $sessionId, message: 'Mollie returned HTTP '.$response['status']);
        }

        $refundId = (string) ($response['body']['id'] ?? '');

        return [
            'sessionId' => $sessionId,
            'refundId'  => $refundId,
            'status'    => 'refunded',
            'error'     => null,
        ];
    }//end refund()

    /**
     * Validate the Mollie webhook signature (HMAC-SHA256).
     *
     * @param string $rawPayload The raw body.
     * @param string $signature  The X-Mollie-Signature header.
     *
     * @return bool
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    public function validateWebhook(string $rawPayload, string $signature): bool
    {
        if ($rawPayload === '' || $signature === '') {
            return false;
        }

        $secret = $this->credential(key: 'webhookSecret');
        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $secret);
        return $this->safeEquals(expected: $expected, provided: $signature);
    }//end validateWebhook()

    /**
     * Parse a Mollie webhook into a normalised settlement envelope.
     *
     * @param array<string, mixed> $payload The decoded webhook payload.
     *
     * @return array{sessionId: string, status: string, eventId: string, error: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    public function parseWebhook(array $payload): array
    {
        $sessionId = (string) ($payload['id'] ?? ($payload['paymentId'] ?? ''));
        $status    = (string) ($payload['status'] ?? '');
        $eventId   = (string) ($payload['eventId'] ?? ($payload['id'] ?? ''));

        return [
            'sessionId' => $sessionId,
            'status'    => $this->mapStatus(providerStatus: $status),
            'eventId'   => $eventId,
            'error'     => null,
        ];
    }//end parseWebhook()

    /**
     * Test the Mollie connection by listing methods (cheap GET).
     *
     * @return array{status: string, message: string}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
     */
    public function testConnection(): array
    {
        $apiKey = $this->credential(key: 'apiKey');
        if ($apiKey === '') {
            return [
                'status'  => 'error',
                'message' => 'API key niet ingesteld',
            ];
        }

        try {
            $response = $this->transport()->request(
                method: 'GET',
                url: self::BASE_URL.'/methods',
                headers: ['Authorization' => 'Bearer '.$apiKey],
                body: null
            );
        } catch (Throwable $e) {
            $this->logProviderError(context: 'testConnection failed', e: $e);
            return [
                'status'  => 'error',
                'message' => 'Verbinding met Mollie mislukt',
            ];
        }//end try

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return [
                'status'  => 'ok',
                'message' => 'Verbinding met Mollie succesvol',
            ];
        }

        return [
            'status'  => 'error',
            'message' => 'Mollie gaf HTTP '.$response['status'].' terug — controleer de API key',
        ];
    }//end testConnection()

    /**
     * Map a normalised method name to Mollie's method id.
     *
     * @param string $method The normalised name.
     *
     * @return string
     */
    private function mapMethod(string $method): string
    {
        $lower = strtolower($method);
        $map   = [
            'ideal'      => 'ideal',
            'bancontact' => 'bancontact',
            'card'       => 'creditcard',
            'creditcard' => 'creditcard',
        ];

        return ($map[$lower] ?? $lower);
    }//end mapMethod()

    /**
     * Map Mollie's status to the pipelinq payment lifecycle.
     *
     * @param string $providerStatus The Mollie status.
     *
     * @return string
     */
    private function mapStatus(string $providerStatus): string
    {
        $map = [
            'paid'       => 'settled',
            'completed'  => 'settled',
            'authorized' => 'captured',
            'pending'    => 'pending',
            'open'       => 'pending',
            'canceled'   => 'failed',
            'cancelled'  => 'failed',
            'expired'    => 'failed',
            'failed'     => 'failed',
            'refunded'   => 'refunded',
        ];

        return ($map[strtolower($providerStatus)] ?? 'pending');
    }//end mapStatus()
}//end class
