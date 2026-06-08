<?php

/**
 * Pipelinq StripeAdapter.
 *
 * Stripe integration for the POS payment provider seam: card and wallet
 * (Apple Pay / Google Pay) payments via the PaymentIntents API. Money is
 * integer-cent on the wire (Stripe's smallest-currency-unit contract);
 * webhook validation uses the `t=` / `v1=` signature header per Stripe's
 * `constructEvent` algorithm.
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
 * Stripe payment adapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
 */
class StripeAdapter extends AbstractPaymentAdapter
{
    /**
     * Stripe API base.
     *
     * @var string
     */
    private const BASE_URL = 'https://api.stripe.com/v1';

    /**
     * Replay window for webhook signatures (5 minutes).
     *
     * @var int
     */
    private const REPLAY_WINDOW = 300;

    /**
     * The canonical provider name.
     *
     * @return string
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
     */
    public function getName(): string
    {
        return 'stripe';
    }//end getName()

    /**
     * Create a Stripe PaymentIntent.
     *
     * @param array<string, mixed> $transactionData The transaction data.
     * @param float                $amount          The amount in EUR.
     * @param string               $paymentMethod   The method (card).
     *
     * @return array{sessionId: string, redirectUrl: string|null, status: string, error?: string}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
     */
    public function initiate(array $transactionData, float $amount, string $paymentMethod): array
    {
        $apiKey = $this->credential(key: 'apiSecret');
        if ($apiKey === '') {
            $apiKey = $this->credential(key: 'apiKey');
        }

        if ($apiKey === '') {
            return $this->failedInitiate(message: 'Stripe API secret not configured');
        }

        $cents = $this->toCents(amount: $amount);
        if ($cents <= 0) {
            return $this->failedInitiate(message: 'Invalid amount');
        }

        $reference = (string) ($transactionData['reference'] ?? '');

        $form = [
            'amount'                  => (string) $cents,
            'currency'                => 'eur',
            'metadata[reference]'     => $reference,
            'metadata[transactionId]' => (string) ($transactionData['id'] ?? ''),
            'metadata[app]'           => 'pipelinq',
        ];

        $body = http_build_query($form);

        try {
            $response = $this->transport()->request(
                method: 'POST',
                url: self::BASE_URL.'/payment_intents',
                headers: [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                body: $body
            );
        } catch (Throwable $e) {
            $this->logProviderError(context: 'initiate failed', e: $e);
            return $this->failedInitiate(message: 'Verbinding met betalingsprovider verbroken');
        }//end try

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->failedInitiate(message: 'Stripe returned HTTP '.$response['status']);
        }

        $sessionId    = (string) ($response['body']['id'] ?? '');
        $clientSecret = (string) ($response['body']['client_secret'] ?? '');
        $finalSession = $clientSecret;
        if ($sessionId !== '') {
            $finalSession = $sessionId;
        }

        return [
            'sessionId'   => $finalSession,
            'redirectUrl' => null,
            'status'      => 'pending',
        ];
    }//end initiate()

    /**
     * Capture a Stripe PaymentIntent.
     *
     * @param string $sessionId The PaymentIntent id.
     *
     * @return array{sessionId: string, status: string, error?: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
     */
    public function capture(string $sessionId): array
    {
        $apiKey = $this->credential(key: 'apiSecret');
        if ($apiKey === '') {
            $apiKey = $this->credential(key: 'apiKey');
        }

        if ($apiKey === '' || $sessionId === '') {
            return $this->failedCapture(sessionId: $sessionId, message: 'Stripe API key or session missing');
        }

        try {
            $response = $this->transport()->request(
                method: 'POST',
                url: self::BASE_URL.'/payment_intents/'.urlencode($sessionId).'/capture',
                headers: [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                body: ''
            );
        } catch (Throwable $e) {
            $this->logProviderError(context: 'capture failed', e: $e);
            return $this->failedCapture(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
        }//end try

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->failedCapture(sessionId: $sessionId, message: 'Stripe returned HTTP '.$response['status']);
        }

        $status = (string) ($response['body']['status'] ?? 'pending');

        return [
            'sessionId' => $sessionId,
            'status'    => $this->mapStatus(providerStatus: $status),
            'error'     => null,
        ];
    }//end capture()

    /**
     * Refund a Stripe PaymentIntent.
     *
     * @param string $sessionId The PaymentIntent id.
     * @param string $reason    The reason.
     *
     * @return array{sessionId: string, refundId: string, status: string, error?: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
     */
    public function refund(string $sessionId, string $reason): array
    {
        $apiKey = $this->credential(key: 'apiSecret');
        if ($apiKey === '') {
            $apiKey = $this->credential(key: 'apiKey');
        }

        if ($apiKey === '' || $sessionId === '') {
            return $this->failedRefund(sessionId: $sessionId, message: 'Stripe API key or session missing');
        }

        $refundReason = 'Refund via Pipelinq POS';
        if ($reason !== '') {
            $refundReason = $reason;
        }

        $form = [
            'payment_intent'   => $sessionId,
            'reason'           => 'requested_by_customer',
            'metadata[reason]' => $refundReason,
            'metadata[app]'    => 'pipelinq',
        ];

        $body = http_build_query($form);

        try {
            $response = $this->transport()->request(
                method: 'POST',
                url: self::BASE_URL.'/refunds',
                headers: [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                body: $body
            );
        } catch (Throwable $e) {
            $this->logProviderError(context: 'refund failed', e: $e);
            return $this->failedRefund(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
        }//end try

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->failedRefund(sessionId: $sessionId, message: 'Stripe returned HTTP '.$response['status']);
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
     * Validate a Stripe webhook — t=/v1= header per constructEvent.
     *
     * @param string $rawPayload The raw body.
     * @param string $signature  The Stripe-Signature header.
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

        $parsed    = $this->parseStripeSignature(signature: $signature);
        $timestamp = (int) ($parsed['t'] ?? 0);
        $provided  = (string) ($parsed['v1'] ?? '');

        if ($timestamp === 0 || $provided === '') {
            return false;
        }

        // Anti-replay — drop signatures older than the replay window.
        $now = time();
        if (($now - $timestamp) > self::REPLAY_WINDOW) {
            return false;
        }

        $signedPayload = $timestamp.'.'.$rawPayload;
        $expected      = hash_hmac('sha256', $signedPayload, $secret);

        return $this->safeEquals(expected: $expected, provided: $provided);
    }//end validateWebhook()

    /**
     * Parse a Stripe webhook envelope.
     *
     * @param array<string, mixed> $payload The decoded payload.
     *
     * @return array{sessionId: string, status: string, eventId: string, error: string|null}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    public function parseWebhook(array $payload): array
    {
        $eventId   = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['type'] ?? '');
        $object    = ($payload['data']['object'] ?? []);
        $sessionId = (string) ($object['payment_intent'] ?? ($object['id'] ?? ''));

        return [
            'sessionId' => $sessionId,
            'status'    => $this->mapEventType(eventType: $eventType),
            'eventId'   => $eventId,
            'error'     => null,
        ];
    }//end parseWebhook()

    /**
     * Test Stripe connection by listing payment methods.
     *
     * @return array{status: string, message: string}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
     */
    public function testConnection(): array
    {
        $apiKey = $this->credential(key: 'apiSecret');
        if ($apiKey === '') {
            $apiKey = $this->credential(key: 'apiKey');
        }

        if ($apiKey === '') {
            return [
                'status'  => 'error',
                'message' => 'API secret niet ingesteld',
            ];
        }

        try {
            $response = $this->transport()->request(
                method: 'GET',
                url: self::BASE_URL.'/balance',
                headers: ['Authorization' => 'Bearer '.$apiKey],
                body: null
            );
        } catch (Throwable $e) {
            $this->logProviderError(context: 'testConnection failed', e: $e);
            return [
                'status'  => 'error',
                'message' => 'Verbinding met Stripe mislukt',
            ];
        }//end try

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return [
                'status'  => 'ok',
                'message' => 'Verbinding met Stripe succesvol',
            ];
        }

        return [
            'status'  => 'error',
            'message' => 'Stripe gaf HTTP '.$response['status'].' terug',
        ];
    }//end testConnection()

    /**
     * Parse the Stripe-Signature header into a t= / v1= map.
     *
     * @param string $signature The header value.
     *
     * @return array<string, string>
     */
    private function parseStripeSignature(string $signature): array
    {
        $out = [];
        foreach (explode(',', $signature) as $segment) {
            $pair = explode('=', trim($segment), 2);
            if (count($pair) === 2) {
                $out[$pair[0]] = $pair[1];
            }
        }

        return $out;
    }//end parseStripeSignature()

    /**
     * Map a Stripe PaymentIntent status to the pipelinq lifecycle.
     *
     * @param string $providerStatus The Stripe status.
     *
     * @return string
     */
    private function mapStatus(string $providerStatus): string
    {
        $map = [
            'succeeded'               => 'settled',
            'requires_capture'        => 'captured',
            'requires_confirmation'   => 'pending',
            'requires_action'         => 'pending',
            'processing'              => 'pending',
            'requires_payment_method' => 'failed',
            'canceled'                => 'failed',
        ];

        return ($map[strtolower($providerStatus)] ?? 'pending');
    }//end mapStatus()

    /**
     * Map a Stripe webhook event type to the pipelinq lifecycle.
     *
     * @param string $eventType The event type.
     *
     * @return string
     */
    private function mapEventType(string $eventType): string
    {
        $map = [
            'payment_intent.succeeded'                 => 'settled',
            'payment_intent.amount_capturable_updated' => 'captured',
            'payment_intent.payment_failed'            => 'failed',
            'payment_intent.canceled'                  => 'failed',
            'charge.refunded'                          => 'refunded',
            'refund.created'                           => 'refunded',
        ];

        return ($map[strtolower($eventType)] ?? 'pending');
    }//end mapEventType()
}//end class
