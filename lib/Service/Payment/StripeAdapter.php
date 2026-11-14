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
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;


/**
 * Stripe payment adapter.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
 */
class StripeAdapter extends AbstractPaymentAdapter {
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
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	public function getName(): string {
		return 'stripe';
	}//end getName()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The display name.
	 */
	protected function label(): string {
		return 'Stripe';
	}//end label()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string> The bearer authorisation header with the secret key.
	 */
	protected function authHeaders(): array {
		return ['Authorization' => 'Bearer ' . $this->secretKey()];
	}//end authHeaders()

	/**
	 * {@inheritDoc}
	 *
	 * @return string Stripe takes form-encoded bodies.
	 */
	protected function bodyType(): string {
		return 'application/x-www-form-urlencoded';
	}//end bodyType()

	/**
	 * Create a Stripe PaymentIntent.
	 *
	 * @param array<string, mixed> $transactionData The transaction data.
	 * @param float $amount The amount in EUR.
	 * @param string $paymentMethod The method (card).
	 *
	 * @return array{sessionId: string, redirectUrl: string|null, status: string, error?: string}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
	 */
	public function initiate(array $transactionData, float $amount, string $paymentMethod): array {
		if ($this->secretKey() === '') {
			return $this->failedInitiate(message: 'Stripe API secret not configured');
		}

		$payment = new PspPayment(transactionData: $transactionData, cents: $this->toCents(amount: $amount));
		if ($payment->cents <= 0) {
			return $this->failedInitiate(message: 'Invalid amount');
		}

		$body = http_build_query($this->intentForm(payment: $payment));
		$response = $this->send(call: new PspCall(url: self::BASE_URL . '/payment_intents', context: 'initiate failed'), body: $body);
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedInitiate(message: $error);
		}

		return $this->pending(sessionId: $this->intentId(body: $response['body']), redirectUrl: null);
	}//end initiate()

	/**
	 * The PaymentIntent create form.
	 *
	 * @param PspPayment $payment The payment.
	 *
	 * @return array<string, string> The form fields.
	 */
	private function intentForm(PspPayment $payment): array {
		return [
			'amount' => (string)$payment->cents,
			'currency' => 'eur',
			'metadata[reference]' => $payment->reference(),
			'metadata[transactionId]' => $payment->transactionId(),
			'metadata[app]' => 'pipelinq',
		];
	}//end intentForm()

	/**
	 * The PaymentIntent id in a create reply, falling back to its client secret.
	 *
	 * @param array<string, mixed> $body The reply body.
	 *
	 * @return string The session id.
	 */
	private function intentId(array $body): string {
		$sessionId = (string)($body['id'] ?? '');
		$clientSecret = (string)($body['client_secret'] ?? '');
		if ($sessionId !== '') {
			return $sessionId;
		}

		return $clientSecret;
	}//end intentId()

	/**
	 * The secret key: the API secret, or the API key when no secret is set.
	 *
	 * @return string The key, or '' when neither is set.
	 */
	private function secretKey(): string {
		$apiKey = $this->credential(key: 'apiSecret');
		if ($apiKey === '') {
			$apiKey = $this->credential(key: 'apiKey');
		}

		return $apiKey;
	}//end secretKey()

	/**
	 * Capture a Stripe PaymentIntent.
	 *
	 * @param string $sessionId The PaymentIntent id.
	 *
	 * @return array{sessionId: string, status: string, error?: string|null}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
	 */
	public function capture(string $sessionId): array {
		if ($this->secretKey() === '' || $sessionId === '') {
			return $this->failedCapture(sessionId: $sessionId, message: 'Stripe API key or session missing');
		}

		$call = new PspCall(url: self::BASE_URL . '/payment_intents/' . urlencode($sessionId) . '/capture', context: 'capture failed');
		$response = $this->send(call: $call, body: '');
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedCapture(sessionId: $sessionId, message: $error);
		}

		$status = (string)($response['body']['status'] ?? 'pending');
		return $this->settled(sessionId: $sessionId, status: $this->mapStatus(providerStatus: $status));
	}//end capture()

	/**
	 * Refund a Stripe PaymentIntent.
	 *
	 * @param string $sessionId The PaymentIntent id.
	 * @param string $reason The reason.
	 *
	 * @return array{sessionId: string, refundId: string, status: string, error?: string|null}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
	 */
	public function refund(string $sessionId, string $reason): array {
		if ($this->secretKey() === '' || $sessionId === '') {
			return $this->failedRefund(sessionId: $sessionId, message: 'Stripe API key or session missing');
		}

		$form = [
			'payment_intent' => $sessionId,
			'reason' => 'requested_by_customer',
			'metadata[reason]' => $this->refundReason(reason: $reason),
			'metadata[app]' => 'pipelinq',
		];
		$response = $this->send(call: new PspCall(url: self::BASE_URL . '/refunds', context: 'refund failed'), body: http_build_query($form));
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedRefund(sessionId: $sessionId, message: $error);
		}

		return $this->refunded(sessionId: $sessionId, refundId: (string)($response['body']['id'] ?? ''));
	}//end refund()

	/**
	 * Validate a Stripe webhook — t=/v1= header per constructEvent.
	 *
	 * @param string $rawPayload The raw body.
	 * @param string $signature The Stripe-Signature header.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 */
	public function validateWebhook(string $rawPayload, string $signature): bool {
		if ($rawPayload === '' || $signature === '') {
			return false;
		}

		$secret = $this->credential(key: 'webhookSecret');
		if ($secret === '') {
			return false;
		}

		$parsed = $this->parseStripeSignature(signature: $signature);
		$timestamp = (int)($parsed['t'] ?? 0);
		$provided = (string)($parsed['v1'] ?? '');

		if ($timestamp === 0 || $provided === '') {
			return false;
		}

		// Anti-replay — drop signatures older than the replay window.
		$now = time();
		if (($now - $timestamp) > self::REPLAY_WINDOW) {
			return false;
		}

		$signedPayload = $timestamp . '.' . $rawPayload;
		$expected = hash_hmac('sha256', $signedPayload, $secret);

		return $this->safeEquals(expected: $expected, provided: $provided);
	}//end validateWebhook()

	/**
	 * Parse a Stripe webhook envelope.
	 *
	 * @param array<string, mixed> $payload The decoded payload.
	 *
	 * @return array{sessionId: string, status: string, eventId: string, error: string|null}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 */
	public function parseWebhook(array $payload): array {
		$eventId = (string)($payload['id'] ?? '');
		$eventType = (string)($payload['type'] ?? '');
		$object = ($payload['data']['object'] ?? []);
		$sessionId = (string)($object['payment_intent'] ?? ($object['id'] ?? ''));

		return [
			'sessionId' => $sessionId,
			'status' => $this->mapEventType(eventType: $eventType),
			'eventId' => $eventId,
			'error' => null,
		];
	}//end parseWebhook()

	/**
	 * Test Stripe connection by listing payment methods.
	 *
	 * @return array{status: string, message: string}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function testConnection(): array {
		if ($this->secretKey() === '') {
			return $this->notConfigured(message: 'API secret niet ingesteld');
		}

		$response = $this->send(call: new PspCall(url: self::BASE_URL . '/balance', context: 'testConnection failed'), body: null);
		return $this->connectionVerdict(response: $response);
	}//end testConnection()

	/**
	 * Parse the Stripe-Signature header into a t= / v1= map.
	 *
	 * @param string $signature The header value.
	 *
	 * @return array<string, string>
	 */
	private function parseStripeSignature(string $signature): array {
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
	private function mapStatus(string $providerStatus): string {
		$map = [
			'succeeded' => 'settled',
			'requires_capture' => 'captured',
			'requires_confirmation' => 'pending',
			'requires_action' => 'pending',
			'processing' => 'pending',
			'requires_payment_method' => 'failed',
			'canceled' => 'failed',
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
	private function mapEventType(string $eventType): string {
		$map = [
			'payment_intent.succeeded' => 'settled',
			'payment_intent.amount_capturable_updated' => 'captured',
			'payment_intent.payment_failed' => 'failed',
			'payment_intent.canceled' => 'failed',
			'charge.refunded' => 'refunded',
			'refund.created' => 'refunded',
		];

		return ($map[strtolower($eventType)] ?? 'pending');
	}//end mapEventType()
}//end class
