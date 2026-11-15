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
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;


/**
 * Mollie payment adapter.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 */
class MollieAdapter extends AbstractPaymentAdapter {
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
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	public function getName(): string {
		return 'mollie';
	}//end getName()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The display name.
	 */
	protected function label(): string {
		return 'Mollie';
	}//end label()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string> The bearer authorisation header.
	 */
	protected function authHeaders(): array {
		return ['Authorization' => 'Bearer ' . $this->credential(key: 'apiKey')];
	}//end authHeaders()

	/**
	 * {@inheritDoc}
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return string The message, pointing at the API key.
	 */
	protected function connectionHttpError(int $status): string {
		return 'Mollie gaf HTTP ' . $status . ' terug — controleer de API key';
	}//end connectionHttpError()

	/**
	 * Initiate a Mollie payment.
	 *
	 * @param array<string, mixed> $transactionData The transaction data.
	 * @param float $amount The amount in EUR.
	 * @param string $paymentMethod The method (ideal, bancontact, creditcard).
	 *
	 * @return array{sessionId: string, redirectUrl: string|null, status: string, error?: string}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
	 */
	public function initiate(array $transactionData, float $amount, string $paymentMethod): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '') {
			return $this->failedInitiate(message: 'Mollie API key not configured');
		}

		$payment = new PspPayment(transactionData: $transactionData, cents: $this->toCents(amount: $amount));
		if ($payment->cents <= 0) {
			return $this->failedInitiate(message: 'Invalid amount');
		}

		$rawBody = (string)json_encode($this->paymentPayload(payment: $payment, paymentMethod: $paymentMethod));
		$response = $this->send(call: new PspCall(url: self::BASE_URL . '/payments', context: 'initiate failed'), body: $rawBody);
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedInitiate(message: $error);
		}

		return $this->pending(sessionId: (string)($response['body']['id'] ?? ''), redirectUrl: $this->checkoutUrl(body: $response['body']));
	}//end initiate()

	/**
	 * The Mollie create-payment request body.
	 *
	 * @param PspPayment $payment       The payment.
	 * @param string     $paymentMethod The normalised method name.
	 *
	 * @return array<string, mixed> The request body.
	 */
	private function paymentPayload(PspPayment $payment, string $paymentMethod): array {
		return [
			'amount' => [
				'currency' => 'EUR',
				'value' => $this->centsToDecimal(cents: $payment->cents),
			],
			'description' => $payment->description(),
			'method' => $this->mapMethod(method: $paymentMethod),
			'metadata' => [
				'reference' => $payment->reference(),
				'transactionId' => $payment->transactionId(),
				'app' => 'pipelinq',
			],
		];
	}//end paymentPayload()

	/**
	 * The hosted checkout URL in a create-payment reply, when it has one.
	 *
	 * @param array<string, mixed> $body The reply body.
	 *
	 * @return string|null The checkout URL.
	 */
	private function checkoutUrl(array $body): ?string {
		if (isset($body['_links']['checkout']['href']) === true) {
			return (string)$body['_links']['checkout']['href'];
		}

		return null;
	}//end checkoutUrl()

	/**
	 * Capture is a no-op for Mollie — settles via webhook.
	 *
	 * @param string $sessionId The session id.
	 *
	 * @return array{sessionId: string, status: string, error?: string|null}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
	 */
	public function capture(string $sessionId): array {
		// Mollie payments auto-settle on customer completion; capture is a
		// status read so the caller can mirror the latest state.
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '' || $sessionId === '') {
			return $this->failedCapture(sessionId: $sessionId, message: 'Mollie API key or session missing');
		}

		$call = new PspCall(url: self::BASE_URL . '/payments/' . urlencode($sessionId), context: 'capture failed');
		$response = $this->send(call: $call, body: null);
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedCapture(sessionId: $sessionId, message: $error);
		}

		$status = (string)($response['body']['status'] ?? 'pending');
		return $this->settled(sessionId: $sessionId, status: $this->mapStatus(providerStatus: $status));
	}//end capture()

	/**
	 * Refund a Mollie payment.
	 *
	 * @param string $sessionId The session id.
	 * @param string $reason The reason.
	 *
	 * @return array{sessionId: string, refundId: string, status: string, error?: string|null}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
	 */
	public function refund(string $sessionId, string $reason): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '' || $sessionId === '') {
			return $this->failedRefund(sessionId: $sessionId, message: 'Mollie API key or session missing');
		}

		$rawBody = (string)json_encode(['description' => $this->refundReason(reason: $reason)]);
		$call = new PspCall(url: self::BASE_URL . '/payments/' . urlencode($sessionId) . '/refunds', context: 'refund failed');
		$response = $this->send(call: $call, body: $rawBody);
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedRefund(sessionId: $sessionId, message: $error);
		}

		return $this->refunded(sessionId: $sessionId, refundId: (string)($response['body']['id'] ?? ''));
	}//end refund()

	/**
	 * Validate the Mollie webhook signature (HMAC-SHA256).
	 *
	 * @param string $rawPayload The raw body.
	 * @param string $signature The X-Mollie-Signature header.
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
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 */
	public function parseWebhook(array $payload): array {
		$sessionId = (string)($payload['id'] ?? ($payload['paymentId'] ?? ''));
		$status = (string)($payload['status'] ?? '');
		$eventId = (string)($payload['eventId'] ?? ($payload['id'] ?? ''));

		return [
			'sessionId' => $sessionId,
			'status' => $this->mapStatus(providerStatus: $status),
			'eventId' => $eventId,
			'error' => null,
		];
	}//end parseWebhook()

	/**
	 * Test the Mollie connection by listing methods (cheap GET).
	 *
	 * @return array{status: string, message: string}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function testConnection(): array {
		if ($this->credential(key: 'apiKey') === '') {
			return $this->notConfigured(message: 'API key niet ingesteld');
		}

		$response = $this->send(call: new PspCall(url: self::BASE_URL . '/methods', context: 'testConnection failed'), body: null);
		return $this->connectionVerdict(response: $response);
	}//end testConnection()

	/**
	 * Map a normalised method name to Mollie's method id.
	 *
	 * @param string $method The normalised name.
	 *
	 * @return string
	 */
	private function mapMethod(string $method): string {
		$lower = strtolower($method);
		$map = [
			'ideal' => 'ideal',
			'bancontact' => 'bancontact',
			'card' => 'creditcard',
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
	private function mapStatus(string $providerStatus): string {
		$map = [
			'paid' => 'settled',
			'completed' => 'settled',
			'authorized' => 'captured',
			'pending' => 'pending',
			'open' => 'pending',
			'canceled' => 'failed',
			'cancelled' => 'failed',
			'expired' => 'failed',
			'failed' => 'failed',
			'refunded' => 'refunded',
		];

		return ($map[strtolower($providerStatus)] ?? 'pending');
	}//end mapStatus()
}//end class
