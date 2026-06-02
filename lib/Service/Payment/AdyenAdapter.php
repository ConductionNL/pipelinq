<?php

/**
 * Pipelinq AdyenAdapter.
 *
 * Payment provider adapter for Adyen (multi-method terminal and online). Calls
 * the Adyen Checkout /payments endpoint; settlement arrives via Adyen
 * notifications whose HMAC signature (base64 HMAC-SHA256) is verified before the
 * transaction is touched.
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
 * Adyen payment provider adapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
class AdyenAdapter extends AbstractPaymentAdapter
{
    /**
     * {@inheritDoc}
     *
     * @return string The provider name.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
     */
    public function getName(): string
    {
        return 'adyen';
    }//end getName()

    /**
     * The Adyen Checkout API base URL for the active environment.
     *
     * @return string The base URL.
     */
    private function apiBase(): string
    {
        if ($this->isLive() === true) {
            return 'https://checkout-live.adyen.com/v71';
        }

        return 'https://checkout-test.adyen.com/v71';
    }//end apiBase()

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
        $body = [
            'amount'          => ['currency' => 'EUR', 'value' => $this->toCents(amount: $amount)],
            'reference'       => (string) ($transactionData['reference'] ?? ''),
            'merchantAccount' => $this->configValue(key: 'merchantAccount'),
            'paymentMethod'   => ['type' => 'scheme'],
            'metadata'        => ['transactionId' => (string) ($transactionData['id'] ?? '')],
        ];

        try {
            $response = $this->jsonRequest(
                method: 'POST',
                url: $this->apiBase().'/payments',
                headers: $this->authHeaders(),
                body: $body
            );
        } catch (PaymentApiException $e) {
            return ['sessionId' => '', 'redirectUrl' => null, 'status' => 'failed', 'error' => $e->getMessage()];
        }

        $actionUrl   = (string) ($response['action']['url'] ?? '');
        $redirectUrl = null;
        if ($actionUrl !== '') {
            $redirectUrl = $actionUrl;
        }

        return [
            'sessionId'   => (string) ($response['pspReference'] ?? ''),
            'redirectUrl' => $redirectUrl,
            'status'      => 'pending',
            'error'       => null,
        ];
    }//end initiate()

    /**
     * {@inheritDoc}
     *
     * @param string $sessionId The Adyen pspReference.
     *
     * @return array{sessionId: string, status: string, error: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
     */
    public function capture(string $sessionId): array
    {
        try {
            $this->jsonRequest(
                method: 'POST',
                url: $this->apiBase().'/payments/'.rawurlencode($sessionId).'/captures',
                headers: $this->authHeaders(),
                body: ['merchantAccount' => $this->configValue(key: 'merchantAccount')]
            );
        } catch (PaymentApiException $e) {
            return ['sessionId' => $sessionId, 'status' => 'failed', 'error' => $e->getMessage()];
        }

        return ['sessionId' => $sessionId, 'status' => 'captured', 'error' => null];
    }//end capture()

    /**
     * {@inheritDoc}
     *
     * @param string $sessionId The Adyen pspReference.
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
                url: $this->apiBase().'/payments/'.rawurlencode($sessionId).'/refunds',
                headers: $this->authHeaders(),
                body: [
                    'merchantAccount'      => $this->configValue(key: 'merchantAccount'),
                    'merchantRefundReason' => $reason,
                ]
            );
        } catch (PaymentApiException $e) {
            return ['sessionId' => $sessionId, 'refundId' => '', 'status' => 'failed', 'error' => $e->getMessage()];
        }

        return [
            'sessionId' => $sessionId,
            'refundId'  => (string) ($response['pspReference'] ?? ''),
            'status'    => 'refunded',
            'error'     => null,
        ];
    }//end refund()

    /**
     * {@inheritDoc}
     *
     * Adyen signs its notifications with a base64 HMAC-SHA256 over a packed,
     * pipe-delimited string of key notification fields. Fails closed without a
     * configured HMAC key.
     *
     * @param string $rawBody   The raw request body.
     * @param string $signature The presented signature (base64).
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

        $payload = json_decode($rawBody, true);
        if (is_array($payload) === false) {
            return false;
        }

        $payload          = $this->firstNotificationItem(payload: $payload);
        $payloadSignature = (string) ($payload['additionalData']['hmacSignature'] ?? '');
        $signing          = $this->buildSigningString(item: $payload);

        // Adyen's HMAC key is supplied hex-encoded.
        $binaryKey = (string) hex2bin($secret);
        if ($binaryKey === '') {
            $binaryKey = $secret;
        }

        $expected = base64_encode((string) hash_hmac('sha256', $signing, $binaryKey, true));

        // Accept the signature from the header or the one embedded in the item.
        if ($this->signatureEquals(expected: $expected, provided: $signature) === true) {
            return true;
        }

        return $this->signatureEquals(expected: $expected, provided: $payloadSignature);
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
        $item      = $this->firstNotificationItem(payload: $payload);
        $sessionId = (string) ($item['pspReference'] ?? '');
        $eventCode = (string) ($item['eventCode'] ?? '');
        $success   = (string) ($item['success'] ?? 'false');

        return [
            'sessionId' => $sessionId,
            'status'    => $this->mapEvent(eventCode: $eventCode, success: $success),
            'eventId'   => $sessionId.':'.$eventCode,
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
        if ($this->apiKey() === '' || $this->configValue(key: 'merchantAccount') === '') {
            return ['status' => 'error', 'message' => 'API-sleutel of merchant account ontbreekt.'];
        }

        try {
            $this->jsonRequest(
                method: 'POST',
                url: $this->apiBase().'/paymentMethods',
                headers: $this->authHeaders(),
                body: ['merchantAccount' => $this->configValue(key: 'merchantAccount')]
            );
        } catch (PaymentApiException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        return ['status' => 'ok', 'message' => 'Verbinding met Adyen geslaagd.'];
    }//end testConnection()

    /**
     * Map an Adyen status string to the normalized vocabulary.
     *
     * @param string $providerStatus The Adyen resultCode / status.
     *
     * @return string The normalized status.
     */
    protected function mapStatus(string $providerStatus): string
    {
        switch ($providerStatus) {
            case 'Authorised':
            case 'authorised':
                return 'captured';
            case 'Settled':
            case 'settled':
                return 'settled';
            case 'Received':
            case 'pending':
                return 'pending';
            case 'Refunded':
            case 'refunded':
                return 'refunded';
            default:
                return 'failed';
        }
    }//end mapStatus()

    /**
     * Map an Adyen notification eventCode + success flag to the normalized vocab.
     *
     * @param string $eventCode The Adyen eventCode (AUTHORISATION, CAPTURE, ...).
     * @param string $success   The success flag ('true'|'false').
     *
     * @return string The normalized status.
     */
    private function mapEvent(string $eventCode, string $success): string
    {
        if ($success !== 'true') {
            return 'failed';
        }

        switch ($eventCode) {
            case 'AUTHORISATION':
                return 'captured';
            case 'CAPTURE':
                return 'settled';
            case 'REFUND':
                return 'refunded';
            default:
                return 'pending';
        }
    }//end mapEvent()

    /**
     * Build the Adyen HMAC signing string from a notification item.
     *
     * @param array<string, mixed> $item The notification item.
     *
     * @return string The pipe-delimited signing payload.
     */
    private function buildSigningString(array $item): string
    {
        $parts = [
            (string) ($item['pspReference'] ?? ''),
            (string) ($item['originalReference'] ?? ''),
            (string) ($item['merchantAccountCode'] ?? ''),
            (string) ($item['merchantReference'] ?? ''),
            (string) ($item['amount']['value'] ?? ''),
            (string) ($item['amount']['currency'] ?? ''),
            (string) ($item['eventCode'] ?? ''),
            (string) ($item['success'] ?? ''),
        ];

        return implode(':', $parts);
    }//end buildSigningString()

    /**
     * Extract the first notification item from an Adyen webhook envelope.
     *
     * Accepts either the full `{ notificationItems: [ { NotificationRequestItem } ] }`
     * envelope or a bare item.
     *
     * @param array<string, mixed> $payload The webhook body.
     *
     * @return array<string, mixed> The first notification item.
     */
    private function firstNotificationItem(array $payload): array
    {
        $items = ($payload['notificationItems'] ?? null);
        if (is_array($items) === true && isset($items[0]) === true) {
            $first = $items[0];
            if (is_array($first) === true) {
                $inner = ($first['NotificationRequestItem'] ?? $first);
                if (is_array($inner) === true) {
                    return $inner;
                }
            }
        }

        return $payload;
    }//end firstNotificationItem()

    /**
     * Build the Adyen API-key headers.
     *
     * @return array<string, string> The headers.
     */
    private function authHeaders(): array
    {
        return [
            'X-API-Key'    => $this->apiKey(),
            'Content-Type' => 'application/json',
        ];
    }//end authHeaders()
}//end class
