<?php

/**
 * Pipelinq AbstractSocialAdapter.
 *
 * What the seven adapters share: the readiness check, the single-call publish,
 * the guard that refuses before a call when a network has no application
 * filed, and the small number-reading helpers.
 *
 * Two adapters override {@see publish()} because their networks publish in two
 * steps (Instagram business and Threads both create a container and then
 * publish it). Everything else uses the one implementation here, so the rule
 * that a refusal is typed and a success carries an id lives in one place.
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
 * Shared behaviour for every network adapter.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `SocialGatewayResult` and
 *  `SocialPublishOutcome` are value objects with NAMED CONSTRUCTORS
 *  (`succeeded`, `failed`, `published`, `refused`). Those are static by
 *  definition, and the alternative PHPMD is asking for, a factory injected
 *  into every adapter, would add a collaborator that constructs a struct.
 */
abstract class AbstractSocialAdapter implements SocialNetworkAdapter {
	/**
	 * Constructor.
	 *
	 * @param SocialBrokerGateway $gateway The brokered egress seam.
	 *
	 * @return void
	 */
	public function __construct(protected readonly SocialBrokerGateway $gateway) {
	}//end __construct()

	/**
	 * Most networks take one image on a post.
	 *
	 * @return int The media limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function maxMedia(): int {
		return 1;
	}//end maxMedia()

	/**
	 * Publishing is free everywhere except X.
	 *
	 * @return float The cost per published post, in euros.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-to-x-stops-at-the-tenants-spend-budget
	 */
	public function costPerPost(): float {
		return 0.0;
	}//end costPerPost()

	/**
	 * Reading numbers back is free everywhere except X.
	 *
	 * @return float The cost per metrics read, in euros.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-to-x-stops-at-the-tenants-spend-budget
	 */
	public function costPerRead(): float {
		return 0.0;
	}//end costPerRead()

	/**
	 * Publish in one call, which is what five of the seven networks do.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 *
	 * @return SocialPublishOutcome The outcome.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function publish(SocialPublishRequest $request): SocialPublishOutcome {
		$refusal = $this->refuseWhenNotConfigured();
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->send(call: $this->publishCall(request: $request), request: $request);
		if ($result->accepted === false) {
			return SocialPublishOutcome::fromGatewayFailure(result: $result);
		}

		return SocialPublishOutcome::published(
			externalId: $this->readPublishedId(payload: $result->body),
			url: $this->readPublishedUrl(payload: $result->body, request: $request),
			cost: $this->costPerPost(),
		);
	}//end publish()

	/**
	 * No network reports its numbers by default; the ones that can override this.
	 *
	 * @param string $externalId The network's own id for the item.
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return SocialAdapterCall|null The request, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The base answers "this
	 *  network reports nothing"; the parameters exist for the overrides.
	 */
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		return null;
	}//end metricsCall()

	/**
	 * No numbers by default.
	 *
	 * @param array<string, mixed> $payload The network's response body.
	 * @param string $externalId The item being asked about.
	 *
	 * @return array{views: int, likes: int, comments: int, shares: int, clicks: int} The normalised numbers.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Same as above.
	 */
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		return self::noMetrics();
	}//end normaliseMetrics()

	/**
	 * No follower endpoint by default.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return SocialAdapterCall|null The request, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Same as above.
	 */
	public function followersCall(array $account): ?SocialAdapterCall {
		return null;
	}//end followersCall()

	/**
	 * No follower count by default.
	 *
	 * @param array<string, mixed> $payload The network's response body.
	 *
	 * @return int The follower count.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Same as above.
	 */
	public function readFollowers(array $payload): int {
		return 0;
	}//end readFollowers()

	/**
	 * The five numbers, all zero. Used wherever a network reports nothing, so
	 * a caller never has to tell an empty array from an absent one.
	 *
	 * @return array{views: int, likes: int, comments: int, shares: int, clicks: int} Five zeroes.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public static function noMetrics(): array {
		return ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0];
	}//end noMetrics()

	/**
	 * Refuse, typed, when this network has no developer application filed.
	 *
	 * @return SocialPublishOutcome|null The refusal, or null when the network may be used.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	protected function refuseWhenNotConfigured(): ?SocialPublishOutcome {
		$readiness = $this->gateway->readiness(brokerProvider: $this->brokerProvider());
		if ($readiness['state'] !== SocialBrokerGateway::NOT_CONFIGURED) {
			return null;
		}

		return SocialPublishOutcome::refused(
			code: SocialGatewayResult::NOT_CONFIGURED,
			reason: $readiness['reason'],
		);
	}//end refuseWhenNotConfigured()

	/**
	 * Hand one call to the broker.
	 *
	 * @param SocialAdapterCall $call The request the network documents.
	 * @param SocialPublishRequest $request The post, for its credential and acting user.
	 *
	 * @return SocialGatewayResult What came back.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	protected function send(SocialAdapterCall $call, SocialPublishRequest $request): SocialGatewayResult {
		return $this->gateway->request(
			credentialRef: $request->credentialRef,
			method: $call->method,
			path: $call->path,
			headers: $call->requestHeaders(),
			body: $call->body(),
			actingUserId: $request->actingUserId,
		);
	}//end send()

	/**
	 * The network's own id for the item it just published.
	 *
	 * @param array<string, mixed> $payload The response body.
	 *
	 * @return string The id, or an empty string when the network gave none.
	 */
	protected function readPublishedId(array $payload): string {
		return (string)($payload['id'] ?? '');
	}//end readPublishedId()

	/**
	 * The public address of the item it just published.
	 *
	 * @param array<string, mixed> $payload The response body.
	 * @param SocialPublishRequest $request The post, for the networks that need the handle to build one.
	 *
	 * @return string The address, or an empty string.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The request is what the
	 *  overrides use; the base reads the network's own field.
	 */
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		foreach (['url', 'permalink', 'uri'] as $field) {
			$value = ($payload[$field] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return '';
	}//end readPublishedUrl()

	/**
	 * Read a nested integer out of a payload without tripping over a missing level.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param array<int, string> $path The keys to walk, in order.
	 *
	 * @return int The value, or 0 when any level is missing or not a number.
	 */
	protected function readInt(array $payload, array $path): int {
		$node = $payload;
		foreach ($path as $key) {
			if (is_array($node) === false || array_key_exists($key, $node) === false) {
				return 0;
			}

			$node = $node[$key];
		}

		if (is_numeric($node) === false) {
			return 0;
		}

		return (int)$node;
	}//end readInt()
}//end class
