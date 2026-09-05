<?php

/**
 * Pipelinq SocialAdapterCall.
 *
 * One request an adapter wants made, in the only shape the broker accepts: a
 * method, a PATH and a body. There is deliberately no host field. The host is
 * the broker's host-lock, pinned to the credential, and an adapter that could
 * name one could name a different one.
 *
 * Splitting the request out of the call is what makes an adapter testable
 * without a network, a broker or a credential. `SocialAdapterRequestShapeTest`
 * asserts these objects against each network's published API, which is the
 * only assertion available for the four networks whose developer applications
 * have not been filed yet.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Social
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * A single brokered request: method, path, headers, body.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */
class SocialAdapterCall {
	/**
	 * Constructor.
	 *
	 * @param string $method The HTTP method the network's API documents.
	 * @param string $path The provider-relative path, with its query when it has one.
	 * @param array<string, mixed> $payload The request body as an array, or an empty array for none.
	 * @param array<string, string> $headers Extra headers. Never an authorization header: the broker owns that.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $payload = [],
		public readonly array $headers = [],
	) {
	}//end __construct()

	/**
	 * The body as the broker wants it: a raw JSON string, or null when there
	 * is nothing to send.
	 *
	 * @return string|null The encoded body, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function body(): ?string {
		if ($this->payload === []) {
			return null;
		}

		$encoded = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			return null;
		}

		return $encoded;
	}//end body()

	/**
	 * The headers to send, with the JSON content type added when there is a body.
	 *
	 * @return array<string, string> The request headers.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function requestHeaders(): array {
		if ($this->payload === []) {
			return $this->headers;
		}

		return array_merge(['Content-Type' => 'application/json'], $this->headers);
	}//end requestHeaders()
}//end class
