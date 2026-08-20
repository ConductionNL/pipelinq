<?php

/**
 * Pipelinq AbstractPaymentAdapter.
 *
 * Shared base for the four POS payment provider adapters. Holds the
 * decrypted credentials + provider config injected by PosPaymentService and
 * provides shared helpers for integer-cent conversion, constant-time
 * signature comparison, and HTTP transport via openconnector when available
 * (falls back to direct cURL for unit-test environments).
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

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared base class for the POS payment provider adapters.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
abstract class AbstractPaymentAdapter implements PaymentProviderInterface {
	/**
	 * Placeholder standing in for a PSP key the adapter is not allowed to know.
	 *
	 * The adapters used to receive the real, decrypted apiKey and paste it into an
	 * `Authorization`/`X-API-Key` header. They no longer get one: the key lives in
	 * OpenRegister's credential broker and is injected server-side.
	 *
	 * Rather than rewrite seventeen `if ($apiKey === '')` guards into a second code path
	 * that could drift from the first, `PosPaymentService` hands the adapters THIS value.
	 * The guards see a non-empty credential and behave exactly as before, and
	 * {@see BrokerHttpTransport} strips the resulting auth header before the call — the
	 * broker discards caller-supplied auth headers anyway.
	 *
	 * It is deliberately not secret-shaped, and it must never reach the wire.
	 * {@see CurlHttpTransport} refuses to send any request carrying it, so a future
	 * change that swaps the transport back fails loudly instead of quietly sending a
	 * placeholder as a bearer token.
	 *
	 * @var string
	 */
	public const BROKER_MANAGED_SECRET = '__managed_by_credential_broker__';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $credentials Decrypted credentials (apiKey, apiSecret, webhookSecret).
	 * @param array<string, mixed> $config Provider-specific config (environment, testMode, terminalId etc.).
	 * @param LoggerInterface $logger The logger.
	 * @param HttpTransport|null $http Optional HTTP transport seam (test wiring).
	 */
	public function __construct(
		protected array $credentials,
		protected array $config,
		protected LoggerInterface $logger,
		protected ?HttpTransport $http = null,
	) {
	}//end __construct()

	/**
	 * The canonical provider name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	abstract public function getName(): string;

	/**
	 * Inject a transport seam (used in unit tests to stub out HTTP).
	 *
	 * @param HttpTransport|null $transport The transport.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	public function setHttpTransport(?HttpTransport $transport): void {
		$this->http = $transport;
	}//end setHttpTransport()

	/**
	 * Render an integer-cent amount from a decimal EUR.
	 *
	 * @param float $amount The decimal amount.
	 *
	 * @return int The amount in integer cents.
	 */
	protected function toCents(float $amount): int {
		return (int)round(($amount * 100));
	}//end toCents()

	/**
	 * Render a `nn.nn` decimal string from an integer-cent amount.
	 *
	 * @param int $cents The integer-cent amount.
	 *
	 * @return string The decimal string.
	 */
	protected function centsToDecimal(int $cents): string {
		$sign = '';
		$absolute = $cents;
		if ($cents < 0) {
			$sign = '-';
			$absolute = -$cents;
		}

		$major = intdiv($absolute, 100);
		$minor = ($absolute % 100);
		return sprintf('%s%d.%02d', $sign, $major, $minor);
	}//end centsToDecimal()

	/**
	 * Constant-time hex-signature compare.
	 *
	 * @param string $expected The computed hex digest.
	 * @param string $provided The signature from the request.
	 *
	 * @return bool True when the signatures match.
	 */
	protected function safeEquals(string $expected, string $provided): bool {
		if ($expected === '' || $provided === '') {
			return false;
		}

		return hash_equals($expected, $provided);
	}//end safeEquals()

	/**
	 * Resolve the HTTP transport, lazy-defaulting to the cURL implementation.
	 *
	 * @return HttpTransport
	 */
	protected function transport(): HttpTransport {
		if ($this->http === null) {
			$this->http = new CurlHttpTransport(logger: $this->logger);
		}

		return $this->http;
	}//end transport()

	/**
	 * Build a failed-initiate response (never expose secrets).
	 *
	 * @param string $message The error message.
	 *
	 * @return array{sessionId: string, redirectUrl: string|null, status: string, error: string}
	 */
	protected function failedInitiate(string $message): array {
		return [
			'sessionId' => '',
			'redirectUrl' => null,
			'status' => 'failed',
			'error' => $message,
		];
	}//end failedInitiate()

	/**
	 * Build a failed-capture response.
	 *
	 * @param string $sessionId The session id.
	 * @param string $message The error message.
	 *
	 * @return array{sessionId: string, status: string, error: string}
	 */
	protected function failedCapture(string $sessionId, string $message): array {
		return [
			'sessionId' => $sessionId,
			'status' => 'failed',
			'error' => $message,
		];
	}//end failedCapture()

	/**
	 * Build a failed-refund response.
	 *
	 * @param string $sessionId The session id.
	 * @param string $message The error message.
	 *
	 * @return array{sessionId: string, refundId: string, status: string, error: string}
	 */
	protected function failedRefund(string $sessionId, string $message): array {
		return [
			'sessionId' => $sessionId,
			'refundId' => '',
			'status' => 'failed',
			'error' => $message,
		];
	}//end failedRefund()

	/**
	 * Log a provider API error without surfacing secrets.
	 *
	 * @param string $context The log context (e.g. 'initiate failed').
	 * @param Throwable $e The exception.
	 *
	 * @return void
	 */
	protected function logProviderError(string $context, Throwable $e): void {
		$this->logger->warning(
			'Pipelinq POS payment [' . $this->getName() . ']: ' . $context,
			[
				'provider' => $this->getName(),
				// Intentionally omit message body — may include secrets.
				'class' => get_class($e),
			]
		);
	}//end logProviderError()

	/**
	 * Read a credential value without surfacing the secret in logs.
	 *
	 * @param string $key The credential key (apiKey, apiSecret, webhookSecret).
	 *
	 * @return string The decrypted value or empty string.
	 */
	protected function credential(string $key): string {
		$value = ($this->credentials[$key] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end credential()

	/**
	 * Read a provider config value.
	 *
	 * @param string $key The config key.
	 * @param string $default The default.
	 *
	 * @return string The value.
	 */
	protected function configValue(string $key, string $default = ''): string {
		if (isset($this->config[$key]) === false) {
			return $default;
		}

		$value = $this->config[$key];
		if (is_string($value) === false) {
			return $default;
		}

		return $value;
	}//end configValue()

	/**
	 * True when the adapter is configured for sandbox mode.
	 *
	 * @return bool
	 */
	protected function isSandbox(): bool {
		$env = $this->configValue(key: 'environment', default: 'sandbox');
		return ($env !== 'live');
	}//end isSandbox()
}//end class
