<?php

/**
 * Pipelinq XAdapter.
 *
 * X, the one network in this change that charges. Every post and every read is
 * metered, which is why publishing to X is gated on the tenant's spend budget
 * before the call is made rather than reconciled afterwards. The budget lives
 * in `SocialPostService`, on the existing `messageSendBudget` semantics; what
 * lives here is the per-post cost the budget is charged.
 *
 * ⏳ WAITING ON A FILING, NOT ON CODE. X needs a developer account with
 * billing, and its OAuth application caps at ten callback URLs, which is why
 * the broker's relay exists. Until Conduction's account is filed and the tier
 * chosen, the connect flow reports the network as unfiled and a post to X is
 * refused as `not_configured` with that reason. The request below is asserted
 * against X's documented API rather than against a live account.
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
 * @link https://docs.x.com/x-api/posts/creation-of-a-post
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * X, with a price per post.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Eleven of the twelve are the
 *  `SocialNetworkAdapter` contract itself, which every network implements; the
 *  twelfth is the read cost only this network has. There is no subset to split
 *  off without splitting the interface.
 */
class XAdapter extends AbstractSocialAdapter {
	/**
	 * The budget provider id every X spend is charged against. It is the
	 * broker provider name rather than a `channelProvider` UUID, because for
	 * social publishing the network IS the provider.
	 *
	 * @var string
	 */
	public const BUDGET_PROVIDER = 'x';

	/**
	 * What one post costs, in euros, on the tier this change assumes. It is an
	 * estimate held in one place rather than a number scattered through the
	 * publishing path, and it is what the budget is charged.
	 *
	 * @var float
	 */
	public const COST_PER_POST_EUR = 0.05;

	/**
	 * What one metrics read costs, in euros. X meters reads as well as posts,
	 * so a daily pull across many publications is a real bill and is charged
	 * against the same budget the posts are.
	 *
	 * @var float
	 */
	public const COST_PER_READ_EUR = 0.01;

	/**
	 * The network name, matching the schema enum.
	 *
	 * @return string The network name.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function network(): string {
		return 'x';
	}//end network()

	/**
	 * The broker provider.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function brokerProvider(): string {
		return 'x';
	}//end brokerProvider()

	/**
	 * The free-tier post length. A longer body is refused at approval rather
	 * than paid for and then rejected.
	 *
	 * @return int The character limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function bodyLimit(): int {
		return 280;
	}//end bodyLimit()

	/**
	 * X takes four images on one post.
	 *
	 * @return int The media limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function maxMedia(): int {
		return 4;
	}//end maxMedia()

	/**
	 * X is the one network that charges per post.
	 *
	 * @return float The cost in euros.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-to-x-stops-at-the-tenants-spend-budget
	 */
	public function costPerPost(): float {
		return self::COST_PER_POST_EUR;
	}//end costPerPost()

	/**
	 * X charges for reads too.
	 *
	 * @return float The cost in euros.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-to-x-stops-at-the-tenants-spend-budget
	 */
	public function costPerRead(): float {
		return self::COST_PER_READ_EUR;
	}//end costPerRead()

	/**
	 * POST /2/tweets.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 *
	 * @return SocialAdapterCall The request.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		return new SocialAdapterCall(
			method: 'POST',
			path: '/2/tweets',
			payload: ['text' => $request->bodyWithLink()],
		);
	}//end publishCall()

	/**
	 * X nests its answer under `data`.
	 *
	 * @param array<string, mixed> $payload The response body.
	 *
	 * @return string The post id.
	 */
	protected function readPublishedId(array $payload): string {
		$data = ($payload['data'] ?? []);
		if (is_array($data) === false) {
			return '';
		}

		return (string)($data['id'] ?? '');
	}//end readPublishedId()

	/**
	 * The public address is built from the handle and the id.
	 *
	 * @param array<string, mixed> $payload The response body.
	 * @param SocialPublishRequest $request The post, for the handle.
	 *
	 * @return string The public address, or an empty string.
	 */
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		$id = $this->readPublishedId(payload: $payload);
		$handle = ltrim($request->handle, '@');
		if ($id === '' || $handle === '') {
			return '';
		}

		return 'https://x.com/' . rawurlencode($handle) . '/status/' . rawurlencode($id);
	}//end readPublishedUrl()

	/**
	 * GET /2/tweets/{id} with the public metrics asked for by name.
	 *
	 * @param string $externalId The post id.
	 * @param array<string, mixed> $account The account row, unused here.
	 *
	 * @return SocialAdapterCall|null The request, or null without an id.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) A post is addressed by
	 *  its own id.
	 */
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/2/tweets/' . rawurlencode($externalId) . '?tweet.fields=public_metrics',
		);
	}//end metricsCall()

	/**
	 * X reports all five, including an impression count that maps to views and
	 * a link-click count that maps to clicks.
	 *
	 * @param array<string, mixed> $payload The response body.
	 * @param string $externalId Unused: the payload is the post asked for.
	 *
	 * @return array{views: int, likes: int, comments: int, shares: int, clicks: int} The normalised numbers.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Same as above.
	 */
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		$base = ['data', 'public_metrics'];

		return [
			'views' => $this->readInt(payload: $payload, path: array_merge($base, ['impression_count'])),
			'likes' => $this->readInt(payload: $payload, path: array_merge($base, ['like_count'])),
			'comments' => $this->readInt(payload: $payload, path: array_merge($base, ['reply_count'])),
			'shares' => $this->readInt(payload: $payload, path: array_merge($base, ['retweet_count'])),
			'clicks' => $this->readInt(payload: $payload, path: array_merge($base, ['url_link_clicks'])),
		];
	}//end normaliseMetrics()

	/**
	 * The connected account's own record carries its follower count.
	 *
	 * @param array<string, mixed> $account The account row, unused here.
	 *
	 * @return SocialAdapterCall|null The request.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The credential names the
	 *  account, so the path takes no argument.
	 */
	public function followersCall(array $account): ?SocialAdapterCall {
		return new SocialAdapterCall(method: 'GET', path: '/2/users/me?user.fields=public_metrics');
	}//end followersCall()

	/**
	 * The follower count under the user's public metrics.
	 *
	 * @param array<string, mixed> $payload The response body.
	 *
	 * @return int The follower count.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function readFollowers(array $payload): int {
		return $this->readInt(payload: $payload, path: ['data', 'public_metrics', 'followers_count']);
	}//end readFollowers()

	/**
	 * X's own composer intent.
	 *
	 * @param SocialPublishRequest $request The prepared post.
	 *
	 * @return string An address that opens the composer.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function composerUrl(SocialPublishRequest $request): string {
		return 'https://x.com/intent/post?text=' . rawurlencode($request->bodyWithLink());
	}//end composerUrl()
}//end class
