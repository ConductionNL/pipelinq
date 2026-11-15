<?php

/**
 * Pipelinq CcvAdapter.
 *
 * CCV PIN terminal integration via the CCV Gateway API. Handles card-present
 * payments from a configured terminal; the customer completes PIN entry on
 * the physical device and CCV emits a settlement webhook. Webhook signatures
 * use HmacSHA512 with merchantId concatenation per CCV API spec.
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
 * CCV PIN terminal adapter.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 */
class CcvAdapter extends AbstractPaymentAdapter {
	/**
	 * CCV Gateway sandbox base URL.
	 *
	 * @var string
	 */
	private const SANDBOX_URL = 'https://api.psp.sandbox.ccv.eu/api/v1';

	/**
	 * CCV Gateway live base URL.
	 *
	 * @var string
	 */
	private const LIVE_URL = 'https://api.psp.ccv.eu/api/v1';

	/**
	 * The canonical provider name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	public function getName(): string {
		return 'ccv';
	}//end getName()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The display name.
	 */
	protected function label(): string {
		return 'CCV';
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
	 * Initiate a CCV PIN terminal payment.
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
		$apiKey = $this->credential(key: 'apiKey');
		$terminalId = $this->configValue(key: 'terminalId');

		if ($apiKey === '' || $terminalId === '') {
			return $this->failedInitiate(message: 'CCV API key or terminal id not configured');
		}

		$payment = new PspPayment(transactionData: $transactionData, cents: $this->toCents(amount: $amount));
		if ($payment->cents <= 0) {
			return $this->failedInitiate(message: 'Invalid amount');
		}

		$rawBody = (string)json_encode($this->paymentPayload(payment: $payment, terminalId: $terminalId));
		$response = $this->send(call: new PspCall(url: $this->baseUrl() . '/payments', context: 'initiate failed'), body: $rawBody);
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedInitiate(message: $error);
		}

		return $this->pending(sessionId: $this->referenceIn(body: $response['body']), redirectUrl: null);
	}//end initiate()

	/**
	 * The CCV terminal-payment request body.
	 *
	 * @param PspPayment $payment    The payment.
	 * @param string     $terminalId The terminal that takes the card.
	 *
	 * @return array<string, mixed> The request body.
	 */
	private function paymentPayload(PspPayment $payment, string $terminalId): array {
		return [
			'amount' => $payment->cents,
			'currency' => 'EUR',
			'method' => 'card',
			'terminalId' => $terminalId,
			'reference' => $payment->reference(),
			'description' => $payment->description(),
			'metadata' => [
				'transactionId' => $payment->transactionId(),
				'app' => 'pipelinq',
			],
		];
	}//end paymentPayload()

	/**
	 * The payment or refund reference in a CCV reply, falling back to its id.
	 *
	 * @param array<string, mixed> $body The reply body.
	 *
	 * @return string The reference, or ''.
	 */
	private function referenceIn(array $body): string {
		return (string)($body['reference'] ?? ($body['id'] ?? ''));
	}//end referenceIn()

	/**
	 * Capture is a status read for CCV — terminal auto-settles.
	 *
	 * @param string $sessionId The session id.
	 *
	 * @return array{sessionId: string, status: string, error?: string|null}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
	 */
	public function capture(string $sessionId): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '' || $sessionId === '') {
			return $this->failedCapture(sessionId: $sessionId, message: 'CCV API key or session missing');
		}

		$call = new PspCall(url: $this->baseUrl() . '/payments/' . urlencode($sessionId), context: 'capture failed');
		$response = $this->send(call: $call, body: null);
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedCapture(sessionId: $sessionId, message: $error);
		}

		$status = (string)($response['body']['status'] ?? 'pending');
		return $this->settled(sessionId: $sessionId, status: $this->mapStatus(providerStatus: $status));
	}//end capture()

	/**
	 * Refund a CCV payment.
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
			return $this->failedRefund(sessionId: $sessionId, message: 'CCV API key or session missing');
		}

		$rawBody = (string)json_encode(['reason' => $this->refundReason(reason: $reason)]);
		$call = new PspCall(url: $this->baseUrl() . '/payments/' . urlencode($sessionId) . '/refunds', context: 'refund failed');
		$response = $this->send(call: $call, body: $rawBody);
		$error = $this->replyError(response: $response);
		if ($error !== null) {
			return $this->failedRefund(sessionId: $sessionId, message: $error);
		}

		return $this->refunded(sessionId: $sessionId, refundId: $this->referenceIn(body: $response['body']));
	}//end refund()

	/**
	 * Validate CCV webhook signature — HmacSHA512 with merchantId concat.
	 *
	 * @param string $rawPayload The raw body.
	 * @param string $signature The X-CCV-Signature header.
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

		$merchantId = $this->configValue(key: 'merchantId', default: '');
		$signed = $merchantId . $rawPayload;
		$expected = hash_hmac('sha512', $signed, $secret);

		return $this->safeEquals(expected: $expected, provided: $signature);
	}//end validateWebhook()

	/**
	 * Parse a CCV webhook.
	 *
	 * @param array<string, mixed> $payload The decoded payload.
	 *
	 * @return array{sessionId: string, status: string, eventId: string, error: string|null}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 */
	public function parseWebhook(array $payload): array {
		$sessionId = (string)($payload['reference'] ?? ($payload['paymentId'] ?? ''));
		$status = (string)($payload['status'] ?? '');
		$eventId = (string)($payload['eventId'] ?? ($payload['reference'] ?? ''));

		return [
			'sessionId' => $sessionId,
			'status' => $this->mapStatus(providerStatus: $status),
			'eventId' => $eventId,
			'error' => null,
		];
	}//end parseWebhook()

	/**
	 * Test CCV connection.
	 *
	 * @return array{status: string, message: string}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function testConnection(): array {
		if ($this->credential(key: 'apiKey') === '') {
			return $this->notConfigured(message: 'API key niet ingesteld');
		}

		$response = $this->send(call: new PspCall(url: $this->baseUrl() . '/terminals', context: 'testConnection failed'), body: null);
		return $this->connectionVerdict(response: $response);
	}//end testConnection()

	/**
	 * {@inheritDoc}
	 *
	 * CCV answers bad credentials with 401 or 403; those get their own message.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return string The message.
	 */
	protected function connectionHttpError(int $status): string {
		if ($status === 401 || $status === 403) {
			return 'Fout: Invalid API credentials. Controleer uw API key.';
		}

		return 'CCV gaf HTTP ' . $status . ' terug';
	}//end connectionHttpError()

	/**
	 * Resolve the base URL based on environment.
	 *
	 * @return string
	 */
	private function baseUrl(): string {
		return $this->environmentUrl(sandboxUrl: self::SANDBOX_URL, liveUrl: self::LIVE_URL);
	}//end baseUrl()

	/**
	 * Map CCV status to pipelinq payment lifecycle.
	 *
	 * @param string $providerStatus The CCV status.
	 *
	 * @return string
	 */
	private function mapStatus(string $providerStatus): string {
		$map = [
			'success' => 'settled',
			'manualintervention' => 'pending',
			'paid' => 'settled',
			'captured' => 'captured',
			'authorised' => 'captured',
			'authorized' => 'captured',
			'pending' => 'pending',
			'open' => 'pending',
			'failed' => 'failed',
			'cancelled' => 'failed',
			'expired' => 'failed',
			'refunded' => 'refunded',
		];

		return ($map[strtolower($providerStatus)] ?? 'pending');
	}//end mapStatus()
}//end class
