<?php

/**
 * Pipelinq BlueskyAdapter.
 *
 * Bluesky, over the AT Protocol. Like Mastodon it needs nobody's approval: the
 * client publishes its own metadata document and the tenant's Nextcloud is its
 * own OAuth client, so there is no developer application to file.
 *
 * ⚠️ ONE THING IS NOT FINISHED, AND IT IS UPSTREAM. OpenRegister's provider
 * catalogue ships `bluesky` flagged `preview`, because the AT Protocol
 * requires DPoP-bound access tokens and the broker's DPoP proof layer has not
 * been written (its follow-up is `credential-oauth2-bluesky-dpop`). A Bluesky
 * connection can be minted and refreshed, but a personal data server will
 * refuse the token until that lands. This adapter is therefore complete and
 * asserted against the documented request, and the account carries a `preview`
 * readiness that says what is incomplete. It is NOT blocked as
 * `not_configured`, because nothing is missing on the Pipelinq side and
 * blocking would mean a later OpenRegister release could not switch Bluesky on
 * without a Pipelinq change too.
 *
 * A post is a repository record rather than a message: `createRecord` writes an
 * `app.bsky.feed.post` into the account's own repository, and the account is
 * addressed by its DID.
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
 * @link https://docs.bsky.app/docs/advanced-guides/posts
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * Bluesky, over the AT Protocol.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */
class BlueskyAdapter extends AbstractSocialAdapter {
	/**
	 * The collection a Bluesky post is written into.
	 *
	 * @var string
	 */
	public const POST_COLLECTION = 'app.bsky.feed.post';

	/**
	 * The network name, matching the schema enum.
	 *
	 * @return string The network name.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function network(): string {
		return 'bluesky';
	}//end network()

	/**
	 * The broker provider. Filed, and shipped as a preview until DPoP lands.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function brokerProvider(): string {
		return 'bluesky';
	}//end brokerProvider()

	/**
	 * A Bluesky post is 300 graphemes.
	 *
	 * @return int The character limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function bodyLimit(): int {
		return 300;
	}//end bodyLimit()

	/**
	 * Bluesky takes four images on one post.
	 *
	 * @return int The media limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function maxMedia(): int {
		return 4;
	}//end maxMedia()

	/**
	 * POST /xrpc/com.atproto.repo.createRecord, writing an `app.bsky.feed.post`
	 * record into the account's own repository.
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
			path: '/xrpc/com.atproto.repo.createRecord',
			payload: [
				'repo' => $request->externalAccountId,
				'collection' => self::POST_COLLECTION,
				'record' => [
					'$type' => self::POST_COLLECTION,
					'text' => $request->bodyWithLink(),
					'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
					'langs' => ['nl'],
				],
			],
		);
	}//end publishCall()

	/**
	 * The record's AT URI is its identity, not the `id` the base class reads.
	 *
	 * @param array<string, mixed> $payload The response body.
	 *
	 * @return string The AT URI.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	protected function readPublishedId(array $payload): string {
		return (string)($payload['uri'] ?? '');
	}//end readPublishedId()

	/**
	 * Bluesky answers with an AT URI rather than a web address, so the public
	 * one is built from the handle and the record key.
	 *
	 * @param array<string, mixed> $payload The response body.
	 * @param SocialPublishRequest $request The post, for the handle.
	 *
	 * @return string The public address, or an empty string.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		$uri = (string)($payload['uri'] ?? '');
		$handle = ltrim($request->handle, '@');
		if ($uri === '' || $handle === '') {
			return '';
		}

		$parts = explode('/', $uri);
		$recordKey = (string)end($parts);
		if ($recordKey === '') {
			return '';
		}

		return 'https://bsky.app/profile/' . rawurlencode($handle) . '/post/' . rawurlencode($recordKey);
	}//end readPublishedUrl()

	/**
	 * The account's own feed. Bluesky publishes no per-post endpoint that the
	 * broker's allow-rules reach, so the feed is read and the post is picked
	 * out of it by its URI.
	 *
	 * @param string $externalId The post's AT URI.
	 * @param array<string, mixed> $account The account row, for the actor.
	 *
	 * @return SocialAdapterCall|null The request, or null without an actor.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		$actor = trim((string)($account['externalAccountId'] ?? ''));
		if ($actor === '' || trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/xrpc/app.bsky.feed.getAuthorFeed?actor=' . rawurlencode($actor) . '&limit=100',
		);
	}//end metricsCall()

	/**
	 * Find the post in the feed and read its counts. A post that has scrolled
	 * off the first hundred entries yields zeroes rather than an error, and its
	 * previous numbers are kept by the caller.
	 *
	 * @param array<string, mixed> $payload The feed payload.
	 * @param string $externalId The post's AT URI.
	 *
	 * @return array{views: int, likes: int, comments: int, shares: int, clicks: int} The normalised numbers.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		$feed = ($payload['feed'] ?? []);
		if (is_array($feed) === false || $externalId === '') {
			return self::noMetrics();
		}

		foreach ($feed as $entry) {
			$post = ($entry['post'] ?? null);
			if (is_array($post) === false || (string)($post['uri'] ?? '') !== $externalId) {
				continue;
			}

			return [
				'views' => 0,
				'likes' => $this->readInt(payload: $post, path: ['likeCount']),
				'comments' => $this->readInt(payload: $post, path: ['replyCount']),
				'shares' => $this->readInt(payload: $post, path: ['repostCount']),
				'clicks' => 0,
			];
		}

		return self::noMetrics();
	}//end normaliseMetrics()

	/**
	 * The account's own profile carries its follower count.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return SocialAdapterCall|null The request, or null without an actor.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function followersCall(array $account): ?SocialAdapterCall {
		$actor = trim((string)($account['externalAccountId'] ?? ''));
		if ($actor === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/xrpc/app.bsky.actor.getProfile?actor=' . rawurlencode($actor),
		);
	}//end followersCall()

	/**
	 * The follower count on the profile.
	 *
	 * @param array<string, mixed> $payload The profile payload.
	 *
	 * @return int The follower count.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function readFollowers(array $payload): int {
		return $this->readInt(payload: $payload, path: ['followersCount']);
	}//end readFollowers()

	/**
	 * Bluesky's own composer intent.
	 *
	 * @param SocialPublishRequest $request The prepared post.
	 *
	 * @return string An address that opens the composer.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function composerUrl(SocialPublishRequest $request): string {
		return 'https://bsky.app/intent/compose?text=' . rawurlencode($request->bodyWithLink());
	}//end composerUrl()
}//end class
