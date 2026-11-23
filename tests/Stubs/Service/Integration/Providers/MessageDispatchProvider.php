<?php

/**
 * Test stub for OpenRegister's MessageDispatchProvider leaf.
 *
 * Mirrors the real `dispatch(source, body, path, headers)` surface so
 * pipelinq's messaging clients can be unit-tested in the "OR-leaf-loaded"
 * container mode. The behaviour is driven by a static script the test sets
 * via {@see self::queue()} so a single test can assert the request shape and
 * return either a success `{ status, source, response }` envelope or a
 * degraded `{ unavailable, cause }` envelope.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * Minimal MessageDispatchProvider stub.
 */
class MessageDispatchProvider {
	/**
	 * Captured dispatch calls (source, body, path, headers).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * The next envelope dispatch() should return.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $nextResult = null;

	/**
	 * Record the call and return the scripted envelope.
	 *
	 * @param string          $source  Source slug.
	 * @param OutboundMessage $message The send path, vendor-shaped body and extra headers.
	 *
	 * @return array<string, mixed>
	 */
	public function dispatch(string $source, OutboundMessage $message): array {
		$this->calls[] = [
			'source' => $source,
			'body' => $message->body,
			'path' => $message->path,
			'headers' => $message->headers,
		];

		if ($this->nextResult !== null) {
			return $this->nextResult;
		}

		return [
			'status' => 'sent',
			'source' => $source,
			'response' => [],
		];
	}//end dispatch()
}//end class
