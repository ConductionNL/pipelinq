<?php

/**
 * Pipelinq AdyenAdapter.
 *
 * Adyen integration for the POS payment provider seam: multi-method
 * card-not-present and terminal payments. Money is integer-cent on the wire
 * (Adyen's native unit), webhook validation uses HMAC-SHA256 over the
 * canonical key/value join of the NotificationRequestItem.
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
 * Adyen payment adapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
class AdyenAdapter extends AbstractPaymentAdapter {
	/**
	 * Adyen Checkout API base (live).
	 *
	 * @var string
	 */
	private const LIVE_URL = 'https://checkout-live.adyen.com/v71';

	/**
	 * Adyen Checkout API base (sandbox).
	 *
	 * @var string
	 */
	private const SANDBOX_URL = 'https://checkout-test.adyen.com/v71';

	/**
	 * The canonical provider name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	public function getName(): string {
		return 'adyen';
	}//end getName()

	/**
	 * Initiate an Adyen payment.
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
		$merchantAccount = $this->configValue(key: 'merchantAccount', default: '');

		if ($apiKey === '' || $merchantAccount === '') {
			return $this->failedInitiate(message: 'Adyen API key or merchantAccount not configured');
		}

		$cents = $this->toCents(amount: $amount);
		if ($cents <= 0) {
			return $this->failedInitiate(message: 'Invalid amount');
		}

		$reference = (string)($transactionData['reference'] ?? ('PIPELINQ-' . uniqid()));

		$payload = [
			'amount' => [
				'value' => $cents,
				'currency' => 'EUR',
			],
			'reference' => $reference,
			'merchantAccount' => $merchantAccount,
			'paymentMethod' => ['type' => $this->mapMethod(method: $paymentMethod)],
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
					'X-API-Key' => $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'initiate failed', e: $e);
			return $this->failedInitiate(message: 'Verbinding met betalingsprovider verbroken');
		}//end try

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedInitiate(message: 'Adyen returned HTTP ' . $response['status']);
		}

		$body = $response['body'];
		$sessionId = (string)($body['pspReference'] ?? '');

		return [
			'sessionId' => $sessionId,
			'redirectUrl' => null,
			'status' => 'pending',
		];
	}//end initiate()

	/**
	 * Capture is a status read for Adyen (auto-capture by default).
	 *
	 * @param string $sessionId The session id.
	 *
	 * @return array{sessionId: string, status: string, error?: string|null}
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
	 */
	public function capture(string $sessionId): array {
		$apiKey = $this->credential(key: 'apiKey');
		$merchantAccount = $this->configValue(key: 'merchantAccount', default: '');
		if ($apiKey === '' || $sessionId === '' || $merchantAccount === '') {
			return $this->failedCapture(sessionId: $sessionId, message: 'Adyen API key or session missing');
		}

		$payload = [
			'merchantAccount' => $merchantAccount,
		];
		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: $this->baseUrl() . '/payments/' . urlencode($sessionId) . '/captures',
				headers: [
					'X-API-Key' => $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'capture failed', e: $e);
			return $this->failedCapture(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}//end try

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedCapture(sessionId: $sessionId, message: 'Adyen returned HTTP ' . $response['status']);
		}

		return [
			'sessionId' => $sessionId,
			'status' => 'captured',
			'error' => null,
		];
	}//end capture()

	/**
	 * Refund an Adyen payment.
	 *
	 * @param string $sessionId The session id (pspReference).
	 * @param string $reason The reason.
	 *
	 * @return array{sessionId: string, refundId: string, status: string, error?: string|null}
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
	 */
	public function refund(string $sessionId, string $reason): array {
		$apiKey = $this->credential(key: 'apiKey');
		$merchantAccount = $this->configValue(key: 'merchantAccount', default: '');
		if ($apiKey === '' || $sessionId === '' || $merchantAccount === '') {
			return $this->failedRefund(sessionId: $sessionId, message: 'Adyen API key or session missing');
		}

		$refundReason = 'Refund via Pipelinq POS';
		if ($reason !== '') {
			$refundReason = $reason;
		}

		$payload = [
			'merchantAccount' => $merchantAccount,
			'merchantRefundReason' => $refundReason,
		];
		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: $this->baseUrl() . '/payments/' . urlencode($sessionId) . '/refunds',
				headers: [
					'X-API-Key' => $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'refund failed', e: $e);
			return $this->failedRefund(sessionId: $sessionId, message: 'Verbinding met betalingsprovider verbroken');
		}//end try

		if ($response['status'] < 200 || $response['status'] >= 300) {
			return $this->failedRefund(sessionId: $sessionId, message: 'Adyen returned HTTP ' . $response['status']);
		}

		$refundId = (string)($response['body']['pspReference'] ?? '');

		return [
			'sessionId' => $sessionId,
			'refundId' => $refundId,
			'status' => 'refunded',
			'error' => null,
		];
	}//end refund()

	/**
	 * Validate Adyen webhook — HMAC-SHA256 over canonical key/value join.
	 *
	 * @param string $rawPayload The raw body.
	 * @param string $signature The hmacSignature from NotificationRequestItem.additionalData.
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

		// Adyen ships the HMAC secret as a hex string; convert to raw bytes
		// when it parses cleanly, fall back to literal bytes otherwise. Guard
		// the hex2bin() precondition ourselves (odd length / non-hex chars)
		// instead of relying on the error control operator to swallow the
		// resulting E_WARNING.
		$looksHex = (strlen($secret) % 2 === 0 && ctype_xdigit($secret) === true);
		$rawKey = false;
		if ($looksHex === true) {
			$rawKey = hex2bin($secret);
		}

		if ($rawKey === false || $rawKey === '') {
			$rawKey = $secret;
		}

		$expected = base64_encode(hash_hmac('sha256', $rawPayload, $rawKey, true));
		return $this->safeEquals(expected: $expected, provided: $signature);
	}//end validateWebhook()

	/**
	 * Parse an Adyen webhook.
	 *
	 * @param array<string, mixed> $payload The decoded payload.
	 *
	 * @return array{sessionId: string, status: string, eventId: string, error: string|null}
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 */
	public function parseWebhook(array $payload): array {
		$item = $payload;
		if (isset($payload['notificationItems'][0]['NotificationRequestItem']) === true) {
			$item = $payload['notificationItems'][0]['NotificationRequestItem'];
		}

		$sessionId = (string)($item['pspReference'] ?? '');
		$eventCode = (string)($item['eventCode'] ?? '');
		$success = ((string)($item['success'] ?? 'false') === 'true');
		$eventId = (string)($item['eventId'] ?? ($item['pspReference'] ?? ''));

		return [
			'sessionId' => $sessionId,
			'status' => $this->mapEvent(eventCode: $eventCode, success: $success),
			'eventId' => $eventId,
			'error' => null,
		];
	}//end parseWebhook()

	/**
	 * Test Adyen connection by listing payment methods.
	 *
	 * @return array{status: string, message: string}
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function testConnection(): array {
		$apiKey = $this->credential(key: 'apiKey');
		$merchantAccount = $this->configValue(key: 'merchantAccount', default: '');

		if ($apiKey === '' || $merchantAccount === '') {
			return [
				'status' => 'error',
				'message' => 'API key of merchantAccount niet ingesteld',
			];
		}

		$payload = [
			'merchantAccount' => $merchantAccount,
			'countryCode' => 'NL',
			'amount' => [
				'value' => 100,
				'currency' => 'EUR',
			],
		];
		$rawBody = (string)json_encode($payload);

		try {
			$response = $this->transport()->request(
				method: 'POST',
				url: $this->baseUrl() . '/paymentMethods',
				headers: [
					'X-API-Key' => $apiKey,
					'Content-Type' => 'application/json',
				],
				body: $rawBody
			);
		} catch (Throwable $e) {
			$this->logProviderError(context: 'testConnection failed', e: $e);
			return [
				'status' => 'error',
				'message' => 'Verbinding met Adyen mislukt',
			];
		}//end try

		if ($response['status'] >= 200 && $response['status'] < 300) {
			return [
				'status' => 'ok',
				'message' => 'Verbinding met Adyen succesvol',
			];
		}

		return [
			'status' => 'error',
			'message' => 'Adyen gaf HTTP ' . $response['status'] . ' terug',
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
	 * Map a normalised method name to an Adyen paymentMethod type.
	 *
	 * @param string $method The method.
	 *
	 * @return string
	 */
	private function mapMethod(string $method): string {
		$lower = strtolower($method);
		$map = [
			'card' => 'scheme',
			'creditcard' => 'scheme',
			'ideal' => 'ideal',
			'bancontact' => 'bcmc',
		];

		return ($map[$lower] ?? 'scheme');
	}//end mapMethod()

	/**
	 * Map an Adyen eventCode + success flag to the pipelinq lifecycle.
	 *
	 * @param string $eventCode The eventCode (AUTHORISATION, CAPTURE, REFUND, ...).
	 * @param bool $success The success flag.
	 *
	 * @return string
	 */
	private function mapEvent(string $eventCode, bool $success): string {
		if ($success === false) {
			return 'failed';
		}

		$map = [
			'AUTHORISATION' => 'captured',
			'CAPTURE' => 'settled',
			'REFUND' => 'refunded',
			'CANCELLATION' => 'failed',
			'CANCEL_OR_REFUND' => 'refunded',
		];

		return ($map[strtoupper($eventCode)] ?? 'pending');
	}//end mapEvent()
}//end class
