<?php

/**
 * In-memory HttpTransport stub used by the POS payment adapter tests.
 *
 * Returns queued canned responses in FIFO order; records every request so
 * tests can assert the wire-level payload without an actual network call.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Payment
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
 * @spec openspec/changes/pos-payment-provider-adapter/tasks.md#11.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\Pipelinq\Service\Payment\HttpTransport;

/**
 * Stub HTTP transport for adapter tests.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/tasks.md#11.1
 */
class StubHttpTransport implements HttpTransport {
	/**
	 * Queued responses.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $responses;

	/**
	 * Recorded requests.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $responses Queued responses (FIFO).
	 */
	public function __construct(array $responses) {
		$this->responses = $responses;
	}//end __construct()

	/**
	 * Execute a request — returns the next queued response.
	 *
	 * @param string $method The HTTP method.
	 * @param string $url The URL.
	 * @param array<string, string> $headers The headers.
	 * @param string|null $body The raw body.
	 *
	 * @return array{status: int, body: array<string, mixed>, raw: string}
	 */
	public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
		$this->requests[] = [
			'method' => $method,
			'url' => $url,
			'headers' => $headers,
			'body' => ($body ?? ''),
		];

		if ($this->responses === []) {
			return [
				'status' => 0,
				'body' => [],
				'raw' => '',
			];
		}

		return array_shift($this->responses);
	}//end request()

	/**
	 * Get the last recorded request.
	 *
	 * @return array<string, mixed>
	 */
	public function lastRequest(): array {
		if ($this->requests === []) {
			return [];
		}

		return $this->requests[(count($this->requests) - 1)];
	}//end lastRequest()

	/**
	 * Get all recorded requests.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function allRequests(): array {
		return $this->requests;
	}//end allRequests()
}//end class
