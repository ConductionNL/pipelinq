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
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use Throwable;

/**
 * CCV PIN terminal adapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
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
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	public function getName(): string {
		return 'ccv';
	}//end getName()

	/**
	 * Initiate a CCV PIN terminal payment.
	 *
	 * @param array<string, mixed> $transactionData The transaction data.
	 * @param float $amount The amount in EUR.
	 * @param string $paymentMethod The method (card).
	 *
	 * @return array{sessionId: string, redirectUrl: string|null, status: string, error?: string}
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
	 */
	public function initiate(array $transactionData, float $amount, string $paymentMethod): array {
		$apiKey = $this->credential(key: 'apiKey');
		$terminalId = $this->configValue(key: 'terminalId');

		if ($apiKey === '' || $terminalId === '') {
			return $this->failedInitiate(message: 'CCV API key or terminal id not configured');
		}

		$cents = $this->toCents(amount: $amount);
		if ($cents <= 0) {
			return $this->failedInitiate(message: 'Invalid amount');
		}

		$reference = (string)($transactionData['reference'] ?? '');
		$label = 'transactie';
		if ($reference !== '') {
			$label = $reference;
		}

		$payload = [
			'amount' => $cents,
			'currency' => 'EUR',
			'method' => 'card',
			'terminalId' => $terminalId,
			'reference' => $reference,
			'description' => sprintf('Pipelinq POS %s', $label),
			'metadata' => [
				'transactionId' => (string)($transactionData['id'] ?? ''),
				'app' => 'pipelinq',
			],
		];

		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: $this->baseUrl() . '/payments',
				headers: [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'initiate failed', e: $e);
			return $this->failedInitiate(message: 'Verbinding met betalingsprovider verbroken');
		}//end try

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedInitiate(message: 'CCV returned HTTP ' . $response['status']);
		}

		$sessionId = (string)($response['body']['reference'] ?? ($response['body']['id'] ?? ''));

		return [
			'sessionId' => $sessionId,
			'redirectUrl' => null,
			'status' => 'pending',
		];
	}//end initiate()

	/**
	 * Capture is a status read for CCV — terminal auto-settles.
	 *
	 * @param string $sessionId The session id.
	 *
	 * @return array{sessionId: string, status: string, error?: string|null}
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
	 */
	public function capture(string $sessionId): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '' || $sessionId === '') {
			return $this->failedCapture(sessionId: $sessionId, message: 'CCV API key or session missing');
		}

		try {
			$response = $this->transport()->request(
				method: 'GET',
				url: $this->baseUrl() . '/payments/' . urlencode($sessionId),
				headers: ['Authorization' => 'Bearer ' . $apiKey],
				body: null
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'capture failed', e: $e);
			return $this->failedCapture(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}//end try

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedCapture(sessionId: $sessionId, message: 'CCV returned HTTP ' . $response['status']);
		}

		$status = (string)($response['body']['status'] ?? 'pending');

		return [
			'sessionId' => $sessionId,
			'status' => $this->mapStatus(providerStatus: $status),
			'error' => null,
		];
	}//end capture()

	/**
	 * Refund a CCV payment.
	 *
	 * @param string $sessionId The session id.
	 * @param string $reason The reason.
	 *
	 * @return array{sessionId: string, refundId: string, status: string, error?: string|null}
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
	 */
	public function refund(string $sessionId, string $reason): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '' || $sessionId === '') {
			return $this->failedRefund(sessionId: $sessionId, message: 'CCV API key or session missing');
		}

		$refundReason = 'Refund via Pipelinq POS';
		if ($reason !== '') {
			$refundReason = $reason;
		}

		$payload = ['reason' => $refundReason];
		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: $this->baseUrl() . '/payments/' . urlencode($sessionId) . '/refunds',
				headers: [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'refund failed', e: $e);
			return $this->failedRefund(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}//end try

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedRefund(sessionId: $sessionId, message: 'CCV returned HTTP ' . $response['status']);
		}

		$refundId = (string)($response['body']['reference'] ?? ($response['body']['id'] ?? ''));

		return [
			'sessionId' => $sessionId,
			'refundId' => $refundId,
			'status' => 'refunded',
			'error' => null,
		];
	}//end refund()

	/**
	 * Validate CCV webhook signature — HmacSHA512 with merchantId concat.
	 *
	 * @param string $rawPayload The raw body.
	 * @param string $signature The X-CCV-Signature header.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
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
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
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
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function testConnection(): array {
		$apiKey = $this->credential(key: 'apiKey');
		if ($apiKey === '') {
			return [
				'status' => 'error',
				'message' => 'API key niet ingesteld',
			];
		}

		try {
			$response = $this->transport()->request(
				method: 'GET',
				url: $this->baseUrl() . '/terminals',
				headers: ['Authorization' => 'Bearer ' . $apiKey],
				body: null
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'testConnection failed', e: $e);
			return [
				'status' => 'error',
				'message' => 'Verbinding met CCV mislukt',
			];
		}//end try

		if ($response['status'] >= 200 && $response['status'] < 300) {
			return [
				'status' => 'ok',
				'message' => 'Verbinding met CCV succesvol',
			];
		}

		if ($response['status'] === 401 || $response['status'] === 403) {
			return [
				'status' => 'error',
				'message' => 'Fout: Invalid API credentials. Controleer uw API key.',
			];
		}

		return [
			'status' => 'error',
			'message' => 'CCV gaf HTTP ' . $response['status'] . ' terug',
		];
	}//end testConnection()

	/**
	 * Resolve the base URL based on environment.
	 *
	 * @return string
	 */
	private function baseUrl(): string {
		if ($this->isSandbox() === true) {
			return self::SANDBOX_URL;
		}

		return self::LIVE_URL;
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
