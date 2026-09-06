<?php

/**
 * Pipelinq SocialBrokerGateway.
 *
 * The one place a social network is spoken to, and the reason no social
 * adapter in this app builds an HTTP client.
 *
 * Rule 2 of the marketing architecture says no secret is a property of a
 * Pipelinq object, and ADR-064 says the same thing fleet-wide. A social token
 * is worth more than most: it can post as a company or as a colleague. So the
 * token never reaches this process at all. An adapter hands this class a
 * method, a PATH and a body; the broker in OpenRegister owns the host (its
 * host-lock), owns the `Authorization` header, and refuses any path its
 * provider allow-rules do not name. `resolveInjectable()` returns null for
 * these credentials by design: a host-locked provider is proxy-only.
 *
 * That the adapters cannot name a host is the point rather than a limitation.
 * An adapter that could name a host could name a different one, and a
 * rewritten base URL is how a token leaves.
 *
 * The one behaviour worth reading twice is the catch order in
 * {@see request()}. `CredentialRelinkRequiredException` EXTENDS
 * `CredentialAccessDeniedException` in OpenRegister, so catching the parent
 * first compiles, passes review and turns every dead grant into a permission
 * refusal. That would hide the single failure a person can actually fix by
 * pressing Reconnect. The order is asserted by a test, not left to whoever
 * edits this method next.
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
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The brokered egress seam for every social network call.
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `SocialGatewayResult` and
 *  `SocialPublishOutcome` are value objects with NAMED CONSTRUCTORS
 *  (`succeeded`, `failed`, `published`, `refused`). Those are static by
 *  definition, and the alternative PHPMD is asking for, a factory injected
 *  into every adapter, would add a collaborator that constructs a struct.
 */
class SocialBrokerGateway {
	/**
	 * OpenRegister's credential broker, resolved lazily by name so Pipelinq
	 * still boots on an instance without OpenRegister.
	 *
	 * @var string
	 */
	public const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * OpenRegister's read-only provider catalogue, the authority on which
	 * networks have an application filed and which are still previews.
	 *
	 * @var string
	 */
	public const CATALOGUE_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\ProviderCatalogue';

	/**
	 * Thrown when the grant behind a credential is gone. Extends the access
	 * exception below, which is why it is caught first.
	 *
	 * @var string
	 */
	public const RELINK_EXCEPTION = 'OCA\\OpenRegister\\Service\\Credential\\CredentialRelinkRequiredException';

	/**
	 * Thrown when a broker guard refuses.
	 *
	 * @var string
	 */
	public const ACCESS_EXCEPTION = 'OCA\\OpenRegister\\Service\\Credential\\CredentialAccessDeniedException';

	/**
	 * The app id Pipelinq identifies itself with. A credential's `allowedApps`
	 * grant must name it or the broker refuses the call.
	 *
	 * @var string
	 */
	public const APP_ID = 'pipelinq';

	/**
	 * The network can be published to now.
	 *
	 * @var string
	 */
	public const READY = 'ready';

	/**
	 * The broker ships this provider as a preview: it can be connected and
	 * publishing is attempted, but something upstream is incomplete and the
	 * network may refuse. Bluesky is the live example, because AT Protocol
	 * needs DPoP-bound tokens and the broker's DPoP layer is not written yet.
	 *
	 * @var string
	 */
	public const PREVIEW = 'preview';

	/**
	 * No provider is filed for this network at all, so nothing can be
	 * connected and nothing may be attempted.
	 *
	 * @var string
	 */
	public const NOT_CONFIGURED = 'not_configured';

	/**
	 * Headers the broker owns. Whatever an adapter puts here is dropped: the
	 * broker injects the real value and discards a caller-supplied one, and
	 * dropping it here means a stale header can never look like it is doing
	 * something.
	 *
	 * @var array<int, string>
	 */
	private const BROKER_OWNED_HEADERS = ['authorization', 'x-api-key', 'apikey', 'dpop'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container, for the lazy broker resolve.
	 * @param LoggerInterface $logger Logger for secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister's credential broker is installed on this instance.
	 *
	 * @return bool True when the broker class can be resolved.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function isAvailable(): bool {
		return class_exists(self::BROKER_CLASS);
	}//end isAvailable()

	/**
	 * How ready one broker provider is, and why.
	 *
	 * A network with no catalogue entry has no application filed, which is a
	 * filing rather than a bug, so it is reported with a reason instead of
	 * being attempted and failing at the call. A `preview` entry is NOT
	 * blocked: nothing is missing on the Pipelinq side, so blocking would mean
	 * a later OpenRegister release could not switch the network on without a
	 * Pipelinq change too.
	 *
	 * @param string $brokerProvider The catalogue provider identifier, or an empty string.
	 *
	 * @return array{state: string, reason: string} The readiness and its reason.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function readiness(string $brokerProvider): array {
		if ($brokerProvider === '') {
			return [
				'state' => self::NOT_CONFIGURED,
				'reason' => 'This network has no developer application yet, so an account cannot be connected.',
			];
		}

		if (class_exists(self::CATALOGUE_CLASS) === false) {
			return [
				'state' => self::NOT_CONFIGURED,
				'reason' => 'OpenRegister is not available, so no network can be connected.',
			];
		}

		$entry = null;
		try {
			$catalogue = $this->container->get(self::CATALOGUE_CLASS);
			$entry = $catalogue->get($brokerProvider);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'SocialBrokerGateway.readiness: the provider catalogue could not be read',
				['provider' => $brokerProvider, 'exception' => $failure->getMessage()]
			);

			return [
				'state' => self::NOT_CONFIGURED,
				'reason' => 'The credential provider catalogue could not be read.',
			];
		}

		if (is_array($entry) === false) {
			return [
				'state' => self::NOT_CONFIGURED,
				'reason' => 'No developer application is filed for this network yet, so an account cannot be connected.',
			];
		}

		if (($entry['preview'] ?? false) === true) {
			return [
				'state' => self::PREVIEW,
				'reason' => 'This network is still a preview in the credential broker, so the network itself may refuse a post.',
			];
		}

		return ['state' => self::READY, 'reason' => ''];
	}//end readiness()

	/**
	 * Make one call to a network through the broker.
	 *
	 * @param string $credentialRef The broker credential UUID stored on the account.
	 * @param string $method The HTTP method the network's API documents.
	 * @param string $path The provider-relative PATH. Never a URL: the host is the broker's.
	 * @param array<string, string> $headers Extra headers, minus the ones the broker owns.
	 * @param string|null $body The raw request body, or null.
	 * @param string|null $actingUserId The account owner, asserted on the sessionless job path (ADR-099).
	 *
	 * @return SocialGatewayResult What came back, or which of the six failures happened.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The branches ARE the closed
	 *  failure set this method exists to produce; folding them into a helper
	 *  would move the mapping without reducing it, and the catch ORDER is the
	 *  behaviour under test.
	 */
	public function request(
		string $credentialRef,
		string $method,
		string $path,
		array $headers = [],
		?string $body = null,
		?string $actingUserId = null,
	): SocialGatewayResult {
		if ($this->isAvailable() === false) {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::UNAVAILABLE,
				reason: 'The OpenRegister credential broker is not installed, so nothing can be published.',
			);
		}

		if (trim($credentialRef) === '') {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::NOT_CONFIGURED,
				reason: 'This account is not connected yet, so there is no credential to publish with.',
			);
		}

		try {
			$broker = $this->container->get(self::BROKER_CLASS);
			$response = $broker->request(
				$credentialRef,
				self::APP_ID,
				strtoupper($method),
				$path,
				$this->withoutBrokerOwnedHeaders(headers: $headers),
				$body,
				$actingUserId
			);
		} catch (Throwable $failure) {
			return $this->classify(failure: $failure, method: $method, path: $path);
		}

		return $this->interpret(response: $response, method: $method, path: $path);
	}//end request()

	/**
	 * Turn an exception from the broker into one of the named failures.
	 *
	 * THE ORDER MATTERS. `CredentialRelinkRequiredException` extends
	 * `CredentialAccessDeniedException`, so asking about the access type first
	 * would answer true for both and every dead grant would be reported as a
	 * permission problem, which is the one diagnosis a person cannot act on.
	 *
	 * @param Throwable $failure What the broker threw.
	 * @param string $method The method that was attempted, for the log.
	 * @param string $path The path that was attempted, for the log.
	 *
	 * @return SocialGatewayResult The named failure.
	 */
	private function classify(Throwable $failure, string $method, string $path): SocialGatewayResult {
		// Never the message, never the body, never the credential: the broker
		// keeps secrets out of its own exceptions and so does this log line.
		$this->logger->warning(
			'SocialBrokerGateway: the brokered call failed',
			['method' => strtoupper($method), 'path' => $path, 'type' => $failure::class]
		);

		if (is_a($failure, self::RELINK_EXCEPTION) === true) {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::RELINK_NEEDED,
				reason: 'The connection to this account has ended. Reconnect it and the post can go out again.',
			);
		}

		if (is_a($failure, self::ACCESS_EXCEPTION) === true) {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::NOT_PERMITTED,
				reason: 'The credential broker refused this call. Check who owns the account and which app it was granted to.',
			);
		}

		return SocialGatewayResult::failed(
			code: SocialGatewayResult::UNAVAILABLE,
			reason: 'The network could not be reached. This can be tried again.',
		);
	}//end classify()

	/**
	 * Turn the broker's answer into a result.
	 *
	 * @param mixed $response The broker's `{status, headers, body}` array.
	 * @param string $method The method that was called, for the log.
	 * @param string $path The path that was called, for the log.
	 *
	 * @return SocialGatewayResult The outcome.
	 */
	private function interpret(mixed $response, string $method, string $path): SocialGatewayResult {
		if (is_array($response) === false) {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::UNAVAILABLE,
				reason: 'The credential broker answered in a shape this version does not understand.',
			);
		}

		$status = (int)($response['status'] ?? 0);
		$raw = (string)($response['body'] ?? '');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		if ($status >= 200 && $status < 300) {
			return SocialGatewayResult::succeeded(status: $status, body: $decoded);
		}

		$this->logger->warning(
			'SocialBrokerGateway: the network refused the call',
			['method' => strtoupper($method), 'path' => $path, 'status' => $status]
		);

		if ($status === 401 || $status === 403) {
			// A network answering 401 or 403 on a call the broker allowed means
			// the grant no longer carries the scope it did, which a retry cannot
			// mend and a reconnect can.
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::RELINK_NEEDED,
				reason: 'The network refused the connection for this account. Reconnect it to restore the permissions.',
				status: $status,
			);
		}

		if ($status >= 400 && $status < 500) {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::REJECTED_BY_NETWORK,
				reason: $this->networkMessage(decoded: $decoded, status: $status),
				status: $status,
			);
		}

		return SocialGatewayResult::failed(
			code: SocialGatewayResult::UNAVAILABLE,
			reason: 'The network answered with an error of its own. This can be tried again.',
			status: $status,
		);
	}//end interpret()

	/**
	 * The network's own refusal in one readable sentence.
	 *
	 * Networks disagree on where they put it, so the four shapes actually seen
	 * are read in turn and the status is the fallback. Nothing else from the
	 * body is repeated, because a body can carry an echoed request.
	 *
	 * @param array<string, mixed> $decoded The decoded response body.
	 * @param int $status The upstream status.
	 *
	 * @return string A readable reason.
	 */
	private function networkMessage(array $decoded, int $status): string {
		$candidates = [
			($decoded['error'] ?? null),
			($decoded['message'] ?? null),
			($decoded['detail'] ?? null),
			($decoded['error_description'] ?? null),
		];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return 'The network refused the post: ' . mb_substr(trim($candidate), 0, 300);
			}
		}

		return 'The network refused the post with status ' . $status . '.';
	}//end networkMessage()

	/**
	 * Drop the headers the broker owns.
	 *
	 * @param array<string, string> $headers The adapter's headers.
	 *
	 * @return array<string, string> The headers with every auth header removed.
	 */
	private function withoutBrokerOwnedHeaders(array $headers): array {
		$kept = [];
		foreach ($headers as $name => $value) {
			if (in_array(strtolower((string)$name), self::BROKER_OWNED_HEADERS, true) === true) {
				continue;
			}

			$kept[(string)$name] = (string)$value;
		}

		return $kept;
	}//end withoutBrokerOwnedHeaders()
}//end class
