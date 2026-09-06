<?php

/**
 * Pipelinq InstagramBusinessAdapter.
 *
 * An Instagram business account, through the Meta Graph API. As with Facebook,
 * a personal Instagram account cannot be posted to by any application at all,
 * so a personal account in Pipelinq is a `share`-mode account and never
 * reaches this adapter.
 *
 * Instagram publishes in TWO steps and cannot be made to publish in one: a
 * media container is created first and published second. That is not a
 * convenience of this implementation, it is the API. What matters here is the
 * failure case: when the container step fails, the publish step is NOT
 * attempted. Attempting it would publish nothing while reporting the second
 * call's own error, which is a different and more confusing failure than the
 * one that actually happened.
 *
 * Instagram also requires an image or a video. A text-only post is refused
 * before any call is made, with a reason that says so.
 *
 * ⏳ WAITING ON A FILING, NOT ON CODE. `instagram_content_publish` needs Meta
 * App Review with Business Verification.
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
 * @link https://developers.facebook.com/docs/instagram-platform/content-publishing
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * An Instagram business account, published in two steps.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `SocialGatewayResult` and
 *  `SocialPublishOutcome` are value objects with NAMED CONSTRUCTORS
 *  (`succeeded`, `failed`, `published`, `refused`). Those are static by
 *  definition, and the alternative PHPMD is asking for, a factory injected
 *  into every adapter, would add a collaborator that constructs a struct.
 */
class InstagramBusinessAdapter extends FacebookPageAdapter {
	/**
	 * The network name, matching the schema enum.
	 *
	 * @return string The network name.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function network(): string {
		return 'instagram';
	}//end network()

	/**
	 * An Instagram caption is 2,200 characters.
	 *
	 * @return int The character limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function bodyLimit(): int {
		return 2200;
	}//end bodyLimit()

	/**
	 * The FIRST of the two calls: create the media container.
	 *
	 * Instagram fetches the image rather than accepting an upload, so the
	 * media has to carry a publicly reachable address. A Nextcloud path alone
	 * is not one.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 *
	 * @return SocialAdapterCall The container request.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		$media = $request->firstPublicMedia();

		return new SocialAdapterCall(
			method: 'POST',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($request->externalAccountId) . '/media',
			payload: [
				'image_url' => (string)($media['url'] ?? ''),
				'caption' => $request->bodyWithLink(),
			],
		);
	}//end publishCall()

	/**
	 * The SECOND of the two calls: publish the prepared container.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 * @param string $containerId The id the container step answered with.
	 *
	 * @return SocialAdapterCall The publish request.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function publishContainerCall(SocialPublishRequest $request, string $containerId): SocialAdapterCall {
		return new SocialAdapterCall(
			method: 'POST',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($request->externalAccountId) . '/media_publish',
			payload: ['creation_id' => $containerId],
		);
	}//end publishContainerCall()

	/**
	 * Publish in two steps, and never publish when the first one failed.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 *
	 * @return SocialPublishOutcome The outcome.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function publish(SocialPublishRequest $request): SocialPublishOutcome {
		$refusal = $this->refuseWhenNotConfigured();
		if ($refusal !== null) {
			return $refusal;
		}

		if ($request->firstPublicMedia() === null) {
			return SocialPublishOutcome::refused(
				code: SocialGatewayResult::REJECTED_BY_NETWORK,
				reason: 'Instagram needs an image or a video with a public address. Add media to this post and try again.',
			);
		}

		$container = $this->send(call: $this->publishCall(request: $request), request: $request);
		if ($container->accepted === false) {
			// The publish step is deliberately NOT attempted. Publishing a
			// container that was never created would report the second call's
			// error and hide the one that actually happened.
			return SocialPublishOutcome::fromGatewayFailure(result: $container);
		}

		$containerId = (string)($container->body['id'] ?? '');
		if ($containerId === '') {
			return SocialPublishOutcome::refused(
				code: SocialGatewayResult::REJECTED_BY_NETWORK,
				reason: 'Instagram accepted the media but named no container, so there is nothing to publish.',
			);
		}

		$published = $this->send(
			call: $this->publishContainerCall(request: $request, containerId: $containerId),
			request: $request,
		);
		if ($published->accepted === false) {
			return SocialPublishOutcome::fromGatewayFailure(result: $published);
		}

		return SocialPublishOutcome::published(
			externalId: $this->readPublishedId(payload: $published->body),
			url: $this->readPublishedUrl(payload: $published->body, request: $request),
		);
	}//end publish()

	/**
	 * Instagram answers with a media id and no address, so none is built.
	 *
	 * @param array<string, mixed> $payload The response body.
	 * @param SocialPublishRequest $request The post, unused here.
	 *
	 * @return string Always an empty string.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Instagram publishes no
	 *  permalink on this response, and guessing one would be wrong more often
	 *  than right.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		return '';
	}//end readPublishedUrl()

	/**
	 * GET /{version}/{media-id}/insights, for the metrics Instagram still
	 * reports after the June 2026 withdrawal.
	 *
	 * @param string $externalId The media id.
	 * @param array<string, mixed> $account The account row, unused here.
	 *
	 * @return SocialAdapterCall|null The request, or null without an id.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) A media item is addressed
	 *  by its own id.
	 */
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/' . self::GRAPH_VERSION . '/' . rawurlencode($externalId)
				. '/insights?metric=views,likes,comments,shares',
		);
	}//end metricsCall()

	/**
	 * Instagram names its series exactly as the five normalised numbers, minus
	 * clicks, which it does not report at all.
	 *
	 * @param array<string, mixed> $payload The insights payload.
	 * @param string $externalId Unused: the payload is the media asked for.
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
			'views' => ($series['views'] ?? 0),
			'likes' => ($series['likes'] ?? 0),
			'comments' => ($series['comments'] ?? 0),
			'shares' => ($series['shares'] ?? 0),
			'clicks' => 0,
		];
	}//end normaliseMetrics()

	/**
	 * Instagram's own composer cannot be opened with prepared text from a
	 * browser, so the share path offers the app itself and the copy action.
	 *
	 * @param SocialPublishRequest $request The prepared post, unused here.
	 *
	 * @return string An address that opens Instagram.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Instagram accepts no
	 *  prefilled text on any address it publishes.
	 */
	public function composerUrl(SocialPublishRequest $request): string {
		return 'https://www.instagram.com/';
	}//end composerUrl()
}//end class
