<?php

/**
 * Pipelinq MastodonAdapter.
 *
 * Mastodon, and the first of the two networks this change can prove end to
 * end. It needs nobody's approval: an application is registered at the
 * account's own server when the connection is made, so there is no filing to
 * wait for and no relay callback to register.
 *
 * Every path below is one of the allow-rules OpenRegister's provider
 * catalogue declares for `mastodon`. That is deliberate: the broker refuses a
 * path its rules do not name, so an adapter that invented one would fail
 * closed at the broker rather than reach the network. The rules are the
 * contract, not a suggestion.
 *
 * The host is not here, because a Mastodon account's host is its own server
 * and the broker pins it onto the credential at mint. `mastodon.nl` and
 * `mastodon.social` are two credentials, each locked to one server for its
 * whole life.
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
 * @link https://docs.joinmastodon.org/methods/statuses/
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * Mastodon, per instance.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */
class MastodonAdapter extends AbstractSocialAdapter {
	/**
	 * The network name, matching the schema enum.
	 *
	 * @return string The network name.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function network(): string {
		return 'mastodon';
	}//end network()

	/**
	 * The broker provider. Filed and usable today.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function brokerProvider(): string {
		return 'mastodon';
	}//end brokerProvider()

	/**
	 * The default status limit. An instance may raise it, and a longer body is
	 * refused by the instance rather than silently cut here.
	 *
	 * @return int The character limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function bodyLimit(): int {
		return 500;
	}//end bodyLimit()

	/**
	 * Mastodon takes four images on one status.
	 *
	 * @return int The media limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function maxMedia(): int {
		return 4;
	}//end maxMedia()

	/**
	 * POST /api/v1/statuses, with the link inside the status text because
	 * Mastodon has no separate link field and unfurls what it finds.
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
			path: '/api/v1/statuses',
			payload: [
				'status' => $request->bodyWithLink(),
				'visibility' => 'public',
			],
		);
	}//end publishCall()

	/**
	 * GET /api/v1/statuses/{id}, which carries the counts on the status itself.
	 *
	 * @param string $externalId The status id.
	 * @param array<string, mixed> $account The account row, unused here.
	 *
	 * @return SocialAdapterCall|null The request, or null without an id.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mastodon addresses a
	 *  status by its own id, so the account is not part of the path.
	 */
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(method: 'GET', path: '/api/v1/statuses/' . rawurlencode($externalId));
	}//end metricsCall()

	/**
	 * Mastodon reports reblogs, favourites and replies. It reports no view
	 * count at all, so views stays zero rather than being inferred from
	 * something else.
	 *
	 * @param array<string, mixed> $payload The status payload.
	 * @param string $externalId Unused: the payload is the status itself.
	 *
	 * @return array{views: int, likes: int, comments: int, shares: int, clicks: int} The normalised numbers.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The payload is already
	 *  the one status that was asked for.
	 */
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		return [
			'views' => 0,
			'likes' => $this->readInt(payload: $payload, path: ['favourites_count']),
			'comments' => $this->readInt(payload: $payload, path: ['replies_count']),
			'shares' => $this->readInt(payload: $payload, path: ['reblogs_count']),
			'clicks' => 0,
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
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The credential already
	 *  names the account, so the path takes no argument.
	 */
	public function followersCall(array $account): ?SocialAdapterCall {
		return new SocialAdapterCall(method: 'GET', path: '/api/v1/accounts/verify_credentials');
	}//end followersCall()

	/**
	 * The follower count on the verified account.
	 *
	 * @param array<string, mixed> $payload The account payload.
	 *
	 * @return int The follower count.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function readFollowers(array $payload): int {
		return $this->readInt(payload: $payload, path: ['followers_count']);
	}//end readFollowers()

	/**
	 * Mastodon's own share intent, on the account's own server.
	 *
	 * @param SocialPublishRequest $request The prepared post.
	 *
	 * @return string An address that opens the composer.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function composerUrl(SocialPublishRequest $request): string {
		return 'https://mastodon.social/share?text=' . rawurlencode($request->bodyWithLink());
	}//end composerUrl()
}//end class
