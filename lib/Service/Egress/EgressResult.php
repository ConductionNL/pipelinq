<?php

/**
 * Pipelinq EgressResult.
 *
 * The typed answer of one outbound read through {@see ConnectorEgress}. It
 * exists so that "nothing came back" and "the read did not happen" can never
 * be the same value: a crawl that did not run must not be presented as a site
 * with no content, and a competitor whose feed refused us must not be
 * presented as a competitor who published nothing.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Egress
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Egress;

/**
 * The outcome of one outbound read.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
 */
final class EgressResult {

	/**
	 * No source id is configured for this capability.
	 *
	 * @var string
	 */
	public const NOT_CONFIGURED = 'not_configured';

	/**
	 * OpenConnector is absent, the source does not resolve, or the call threw.
	 *
	 * @var string
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * The far end answered with a status outside 2xx.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * A body came back but is not the format the reader expects.
	 *
	 * @var string
	 */
	public const UNPARSABLE = 'unparsable';

	/**
	 * The whole failure vocabulary, so a caller can assert it is closed.
	 *
	 * @var array<int, string>
	 */
	public const FAILURES = [
		self::NOT_CONFIGURED,
		self::UNAVAILABLE,
		self::REFUSED,
		self::UNPARSABLE,
	];

	/**
	 * Constructor.
	 *
	 * @param bool $ok Whether the read succeeded.
	 * @param int $status The HTTP status, 0 when no call was made.
	 * @param string $body The response body, empty on failure.
	 * @param string|null $failure One of {@see FAILURES}, or null on success.
	 * @param string $reason A sentence a page can render, empty on success.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly int $status = 0,
		public readonly string $body = '',
		public readonly ?string $failure = null,
		public readonly string $reason = '',
	) {
	}//end __construct()

	/**
	 * A successful read.
	 *
	 * @param string $body The response body.
	 * @param int $status The HTTP status.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public static function success(string $body, int $status = 200): self {
		return new self(ok: true, status: $status, body: $body);
	}//end success()

	/**
	 * A failed read, carrying why.
	 *
	 * @param string $failure One of {@see FAILURES}.
	 * @param string $reason A sentence a page can render.
	 * @param int $status The HTTP status, when there was one.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public static function failed(string $failure, string $reason, int $status = 0): self {
		return new self(ok: false, status: $status, failure: $failure, reason: $reason);
	}//end failed()

	/**
	 * The decoded JSON body, or null when it is not JSON.
	 *
	 * @return array<string|int, mixed>|null
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public function json(): ?array {
		if ($this->ok === false || $this->body === '') {
			return null;
		}

		$decoded = json_decode($this->body, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end json()
}//end class
