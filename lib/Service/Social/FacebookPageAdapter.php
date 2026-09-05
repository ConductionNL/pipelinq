<?php

/**
 * Pipelinq FacebookPageAdapter.
 *
 * A Facebook page, through the Meta Graph API. A page is the ONLY Facebook
 * surface an application may post to: a personal profile cannot be written to
 * by any application at all, at any tier of review, which is why a personal
 * Facebook account in Pipelinq is a `share`-mode account and goes down the
 * advocacy path instead of arriving here.
 *
 * ⏳ WAITING ON A FILING, NOT ON CODE. Posting to a page needs
 * `pages_manage_posts`, which needs Meta App Review with Business
 * Verification. Until Conduction's filing is approved, a page account connects
 * and the network refuses the post, which arrives as a typed
 * `rejected_by_network` carrying Meta's own words.
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
 * @link https://developers.facebook.com/docs/pages-api/posts
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * A Facebook page.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */
class FacebookPageAdapter extends AbstractSocialAdapter {
	/**
	 * The Graph API version every path is prefixed with. It is pinned rather
	 * than floating, because Meta deprecates versions on a schedule and a
	 * floating path changes behaviour without a commit.
	 *
	 * @var string
	 */
	public const GRAPH_VERSION = 'v21.0';

	/**
	 * The network name, matching the schema enum.
	 *
	 * @return string The network name.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function network(): string {
		return 'facebook';
	}//end network()

	/**
	 * The broker provider. Facebook, Instagram and the Graph API share one.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function brokerProvider(): string {
		return 'meta-graph';
	}//end brokerProvider()

	/**
	 * A page post is generously long; this is the practical cap the composer
	 * warns at rather than Facebook's own hard limit.
	 *
	 * @return int The character limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function bodyLimit(): int {
		return 5000;
	}//end bodyLimit()

	/**
	 * POST /{version}/{page-id}/feed, with the link as its own field so
	 * Facebook renders the preview card rather than a bare address in the text.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 *
	 * @return SocialAdapterCall The request.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		$payload = ['message' => trim($request->body)];
		if ($request->link !== '') {
			$payload['link'] = $request->link;
		}

		return new SocialAdapterCall(
			method: 'POST',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($request->externalAccountId) . '/feed',
			payload: $payload,
		);
	}//end publishCall()

	/**
	 * The public address of a page post is built from its composite id.
	 *
	 * @param array<string, mixed> $payload The response body.
	 * @param SocialPublishRequest $request The post, unused here.
	 *
	 * @return string The public address, or an empty string.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The composite id already
	 *  carries the page.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		$id = (string)($payload['id'] ?? '');
		if ($id === '') {
			return '';
		}

		return 'https://www.facebook.com/' . str_replace('_', '/posts/', $id);
	}//end readPublishedUrl()

	/**
	 * GET /{version}/{post-id}/insights for the metrics Meta still reports.
	 *
	 * Reach and impressions were withdrawn in June 2026. The three metrics
	 * asked for here are the ones that survived, and views is whichever of the
	 * remaining view counters answers.
	 *
	 * @param string $externalId The post id.
	 * @param array<string, mixed> $account The account row, unused here.
	 *
	 * @return SocialAdapterCall|null The request, or null without an id.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) A post is addressed by
	 *  its own composite id.
	 */
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($externalId)
				. '/insights?metric=post_video_views,post_clicks,post_reactions_by_type_total',
		);
	}//end metricsCall()

	/**
	 * Meta answers insights as a list of named series. Each name is read by
	 * name rather than by position, because the order is not documented and a
	 * positional read would silently attribute one number to another.
	 *
	 * @param array<string, mixed> $payload The insights payload.
	 * @param string $externalId Unused: the payload is the post asked for.
	 *
	 * @return array{views: int, likes: int, comments: int, shares: int, clicks: int} The normalised numbers.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Same as above.
	 */
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		$series = $this->seriesByName(payload: $payload);

		return [
			'views' => ($series['post_video_views'] ?? 0),
			'likes' => ($series['post_reactions_by_type_total'] ?? 0),
			'comments' => 0,
			'shares' => 0,
			'clicks' => ($series['post_clicks'] ?? 0),
		];
	}//end normaliseMetrics()

	/**
	 * Reduce Meta's insights list to a name-to-value map.
	 *
	 * @param array<string, mixed> $payload The insights payload.
	 *
	 * @return array<string, int> The values by metric name.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	protected function seriesByName(array $payload): array {
		$out = [];
		$data = ($payload['data'] ?? []);
		if (is_array($data) === false) {
			return $out;
		}

		foreach ($data as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$name = (string)($entry['name'] ?? '');
			$values = ($entry['values'] ?? []);
			if ($name === '' || is_array($values) === false || $values === []) {
				continue;
			}

			$first = $values[0];
			$value = 0;
			if (is_array($first) === true) {
				$value = ($first['value'] ?? 0);
			}

			if (is_array($value) === true) {
				$value = array_sum(array_map('intval', $value));
			}

			$out[$name] = (int)$value;
		}

		return $out;
	}//end seriesByName()

	/**
	 * Meta's allow-rules reach no page follower count, so this reports none
	 * rather than a zero that would read as a measurement.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return SocialAdapterCall|null Always null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) There is no reachable
	 *  endpoint for this, whatever the account.
	 */
	public function followersCall(array $account): ?SocialAdapterCall {
		return null;
	}//end followersCall()

	/**
	 * Facebook's own sharer.
	 *
	 * @param SocialPublishRequest $request The prepared post.
	 *
	 * @return string An address that opens the sharer.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function composerUrl(SocialPublishRequest $request): string {
		if ($request->link !== '') {
			return 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($request->link);
		}

		return 'https://www.facebook.com/';
	}//end composerUrl()
}//end class
