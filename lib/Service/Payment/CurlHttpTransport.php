<?php

/**
 * Pipelinq CurlHttpTransport.
 *
 * Production HTTP transport used by the POS payment adapters when no other
 * transport is injected. Wraps the `curl_*` family with a 5-second timeout
 * (REQ-PAY-010 scenario: provider API timeout shows a user-facing connection
 * error). Always returns a result envelope — never throws — so the adapter
 * layer can map a non-2xx to a controller-friendly { status: failed, error }
 * shape without try/catch ceremony.
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

use Psr\Log\LoggerInterface;

/**
 * CURL-backed HTTP transport.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
class CurlHttpTransport implements HttpTransport {
	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT = 5;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Execute an HTTP request.
	 *
	 * @param string $method The HTTP method.
	 * @param string $url The URL.
	 * @param array<string, string> $headers The headers.
	 * @param string|null $body The raw body.
	 *
	 * @return array{status: int, body: array<string, mixed>, raw: string}
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
		if ($this->carriesBrokerPlaceholder(headers: $headers) === true || $this->curlMissing() === true) {
			return self::NO_RESPONSE;
		}

		$handle = curl_init($url);
		if ($handle === false) {
			return self::NO_RESPONSE;
		}

		$this->setRequestOptions(handle: $handle, method: $method);
		curl_setopt($handle, CURLOPT_HTTPHEADER, $this->headerLines(headers: $headers));
		$this->setBody(handle: $handle, body: $body);
		return $this->execute(handle: $handle);
	}//end request()

	/**
	 * The tripwire: whether a header carries the broker-managed placeholder (logged as an error).
	 *
	 * The adapters are handed AbstractPaymentAdapter::BROKER_MANAGED_SECRET in place of a
	 * real PSP key; BrokerHttpTransport strips the resulting auth header and the broker
	 * injects the real secret. If that placeholder ever reaches THIS transport, the call has
	 * been routed around the broker — sending it would put a meaningless bearer token on the
	 * wire and, worse, mean somebody has reintroduced a direct, app-authenticated PSP call.
	 * Fail loudly rather than send it.
	 *
	 * @param array<string, string> $headers The headers.
	 *
	 * @return bool True when the request must not be sent.
	 */
	private function carriesBrokerPlaceholder(array $headers): bool {
		foreach ($headers as $value) {
			if (is_string($value) === true
				&& str_contains($value, AbstractPaymentAdapter::BROKER_MANAGED_SECRET) === true
			) {
				$this->logger->error(
					'Pipelinq POS payment: refusing to send a PSP request directly — it carries the '
					. 'broker-managed placeholder, which means it bypassed the credential broker.'
				);
				return true;
			}
		}

		return false;
	}//end carriesBrokerPlaceholder()

	/**
	 * Whether the cURL extension is missing (logged as a warning).
	 *
	 * @return bool True when cURL is not available.
	 */
	private function curlMissing(): bool {
		if (function_exists('curl_init') === false) {
			$this->logger->warning('Pipelinq POS payment: cURL not available');
			return true;
		}

		return false;
	}//end curlMissing()

	/**
	 * Set the method, return-transfer and timeout options.
	 *
	 * @param \CurlHandle $handle The cURL handle.
	 * @param string      $method The HTTP method.
	 *
	 * @return void
	 */
	private function setRequestOptions(\CurlHandle $handle, string $method): void {
		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_TIMEOUT, self::TIMEOUT);
		curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT);
	}//end setRequestOptions()

	/**
	 * Set the raw request body, when there is one.
	 *
	 * @param \CurlHandle $handle The cURL handle.
	 * @param string|null $body   The raw body.
	 *
	 * @return void
	 */
	private function setBody(\CurlHandle $handle, ?string $body): void {
		if ($body !== null) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
		}
	}//end setBody()

	/**
	 * The headers as `Name: value` lines.
	 *
	 * @param array<string, string> $headers The headers.
	 *
	 * @return array<int, string> The header lines.
	 */
	private function headerLines(array $headers): array {
		$headerList = [];
		foreach ($headers as $name => $value) {
			$headerList[] = $name . ': ' . $value;
		}

		return $headerList;
	}//end headerLines()

	/**
	 * Send the request and read the status and the JSON reply.
	 *
	 * @param \CurlHandle $handle The configured cURL handle.
	 *
	 * @return array{status: int, body: array<string, mixed>, raw: string}
	 */
	private function execute(\CurlHandle $handle): array {
		$raw = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
		$rawText = '';
		if (is_string($raw) === true) {
			$rawText = $raw;
		}

		curl_close($handle);

		return [
			'status' => $status,
			'body' => $this->decodeObject(rawText: $rawText),
			'raw' => $rawText,
		];
	}//end execute()

	/**
	 * The reply decoded as a JSON array, or an empty array.
	 *
	 * @param string $rawText The raw reply.
	 *
	 * @return array<string, mixed> The decoded reply.
	 */
	private function decodeObject(string $rawText): array {
		if ($rawText === '') {
			return [];
		}

		$maybe = json_decode($rawText, true);
		if (is_array($maybe) === true) {
			return $maybe;
		}

		return [];
	}//end decodeObject()
}//end class
