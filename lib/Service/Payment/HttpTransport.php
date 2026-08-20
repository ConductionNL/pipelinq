<?php

/**
 * Pipelinq HttpTransport.
 *
 * Lightweight HTTP transport seam for the POS payment adapters. The seam is
 * intentionally minimal: a single `request()` method that returns the parsed
 * JSON body and the HTTP status code. Concrete implementations include
 * {@see CurlHttpTransport} (production) and an in-memory stub the unit tests
 * inject to assert the request payload without an actual network call.
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

/**
 * HTTP transport seam — used by the payment adapters to talk to providers.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
interface HttpTransport {
	/**
	 * Execute an HTTP request and return { status, body }.
	 *
	 * @param string $method The HTTP method (GET, POST, ...).
	 * @param string $url The full URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null $body Optional raw request body.
	 *
	 * @return array{status: int, body: array<string, mixed>, raw: string}
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}//end interface
