<?php

/**
 * Pipelinq LinkedInAdapter.
 *
 * LinkedIn, for both a company page and a colleague's own profile. The two are
 * one adapter because the request differs in exactly one field: the author
 * URN is `urn:li:organization:{id}` for a page and `urn:li:person:{id}` for a
 * member. Splitting them into two classes would duplicate everything else to
 * express one ternary.
 *
 * ⏳ WAITING ON A FILING, NOT ON CODE. Member posting needs `w_member_social`,
 * which an ordinary developer application carries. Posting to a company page
 * needs `w_organization_social` from the Community Management API, which
 * LinkedIn grants by application and review. Until Conduction's filing is
 * approved, an organisation account connects but the network refuses the post,
 * which arrives here as a typed `rejected_by_network` naming LinkedIn's own
 * words rather than as a silent nothing.
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
 * @link https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * LinkedIn, member and organisation.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */
class LinkedInAdapter extends AbstractSocialAdapter {
	/**
	 * The versioned API month LinkedIn requires on every REST call. LinkedIn
	 * refuses a request without it, so it is a header and not an option.
	 *
	 * @var string
	 */
	public const API_VERSION = '202506';

	/**
	 * The network name, matching the schema enum.
	 *
	 * @return string The network name.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function network(): string {
		return 'linkedin';
	}//end network()

	/**
	 * The broker provider.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function brokerProvider(): string {
		return 'linkedin';
	}//end brokerProvider()

	/**
	 * LinkedIn takes 3,000 characters of commentary.
	 *
	 * @return int The character limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function bodyLimit(): int {
		return 3000;
	}//end bodyLimit()

	/**
	 * POST /rest/posts, authored by the connected member or the connected
	 * organisation.
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
			path: '/rest/posts',
			payload: [
				'author' => $this->authorUrn(request: $request),
				'commentary' => $request->bodyWithLink(),
				'visibility' => 'PUBLIC',
				'distribution' => [
					'feedDistribution' => 'MAIN_FEED',
					'targetEntities' => [],
					'thirdPartyDistributionChannels' => [],
				],
				'lifecycleState' => 'PUBLISHED',
				'isReshareDisabledByAuthor' => false,
			],
			headers: [
				'LinkedIn-Version' => self::API_VERSION,
				'X-Restli-Protocol-Version' => '2.0.0',
			],
		);
	}//end publishCall()

	/**
	 * The author URN, which is the one field that differs between a company
	 * page and a colleague's own profile.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 *
	 * @return string The author URN.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function authorUrn(SocialPublishRequest $request): string {
		$id = trim($request->externalAccountId);
		if (str_starts_with($id, 'urn:li:') === true) {
			return $id;
		}

		if ($request->accountKind === 'person') {
			return 'urn:li:person:' . $id;
		}

		return 'urn:li:organization:' . $id;
	}//end authorUrn()

	/**
	 * LinkedIn returns the post's URN in a header rather than the body, and
	 * the broker hands the body back only. The `id` field is present on the
	 * REST response, so it is read from there.
	 *
	 * @param array<string, mixed> $payload The response body.
	 *
	 * @return string The post URN.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	protected function readPublishedId(array $payload): string {
		foreach (['id', 'urn'] as $field) {
			$value = ($payload[$field] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return '';
	}//end readPublishedId()

	/**
	 * The feed address of a post is built from its URN.
	 *
	 * @param array<string, mixed> $payload The response body.
	 * @param SocialPublishRequest $request The post, unused here.
	 *
	 * @return string The public address, or an empty string.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) LinkedIn's address is
	 *  derived from the URN alone.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		$urn = $this->readPublishedId(payload: $payload);
		if ($urn === '') {
			return '';
		}

		return 'https://www.linkedin.com/feed/update/' . rawurlencode($urn) . '/';
	}//end readPublishedUrl()

	/**
	 * GET /rest/socialActions/{urn}, which carries likes and comments on the
	 * account's own post.
	 *
	 * @param string $externalId The post URN.
	 * @param array<string, mixed> $account The account row, unused here.
	 *
	 * @return SocialAdapterCall|null The request, or null without an id.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) A social action is
	 *  addressed by the post URN alone.
	 */
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/rest/socialActions/' . rawurlencode($externalId),
			headers: ['LinkedIn-Version' => self::API_VERSION, 'X-Restli-Protocol-Version' => '2.0.0'],
		);
	}//end metricsCall()

	/**
	 * Likes and comments come off the social action. LinkedIn withdrew
	 * impressions from this surface in June 2026, so views stays zero here and
	 * is not borrowed from a share-statistics call the allow-rules reach only
	 * for an organisation.
	 *
	 * @param array<string, mixed> $payload The social action payload.
	 * @param string $externalId Unused: the payload is the action asked for.
	 *
	 * @return array{views: int, likes: int, comments: int, shares: int, clicks: int} The normalised numbers.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Same as above.
	 */
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		return [
			'views' => 0,
			'likes' => $this->readInt(payload: $payload, path: ['likesSummary', 'totalLikes']),
			'comments' => $this->readInt(payload: $payload, path: ['commentsSummary', 'totalFirstLevelComments']),
			'shares' => 0,
			'clicks' => 0,
		];
	}//end normaliseMetrics()

	/**
	 * Follower statistics exist for an organisation only. A member has no
	 * follower endpoint the allow-rules reach, so null is the honest answer
	 * rather than a zero that would look like a count.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return SocialAdapterCall|null The request, or null for a member.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function followersCall(array $account): ?SocialAdapterCall {
		$id = trim((string)($account['externalAccountId'] ?? ''));
		if ($id === '' || (string)($account['kind'] ?? '') !== 'organisation') {
			return null;
		}

		$urn = $id;
		if (str_starts_with($urn, 'urn:li:') === false) {
			$urn = 'urn:li:organization:' . $urn;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/rest/organizationalEntityFollowerStatistics?q=organizationalEntity&organizationalEntity='
				. rawurlencode($urn),
			headers: ['LinkedIn-Version' => self::API_VERSION, 'X-Restli-Protocol-Version' => '2.0.0'],
		);
	}//end followersCall()

	/**
	 * The organic follower count in the statistics payload.
	 *
	 * @param array<string, mixed> $payload The statistics payload.
	 *
	 * @return int The follower count.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function readFollowers(array $payload): int {
		$elements = ($payload['elements'] ?? []);
		if (is_array($elements) === false || $elements === []) {
			return 0;
		}

		$total = 0;
		foreach ($elements as $element) {
			if (is_array($element) === false) {
				continue;
			}

			// LinkedIn answers in one of two shapes: a flat `followerCounts`
			// on the element, or one entry per association type (organic,
			// sponsored, employee) that has to be summed. Both are read, so a
			// change of shape loses nothing and never double-counts, because
			// only one of the two is ever present on an element.
			$total += $this->readInt(payload: $element, path: ['followerCounts', 'organicFollowerCount']);

			$byType = ($element['followerCountsByAssociationType'] ?? []);
			if (is_array($byType) === false) {
				continue;
			}

			foreach ($byType as $entry) {
				if (is_array($entry) === true) {
					$total += $this->readInt(payload: $entry, path: ['followerCounts', 'organicFollowerCount']);
				}
			}
		}

		return $total;
	}//end readFollowers()

	/**
	 * LinkedIn's own share intent.
	 *
	 * @param SocialPublishRequest $request The prepared post.
	 *
	 * @return string An address that opens the composer.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function composerUrl(SocialPublishRequest $request): string {
		if ($request->link !== '') {
			return 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($request->link);
		}

		return 'https://www.linkedin.com/feed/?shareActive=true';
	}//end composerUrl()
}//end class
