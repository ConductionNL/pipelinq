<?php

/**
 * Pipelinq ThreadsAdapter.
 *
 * Threads, which publishes in two steps like Instagram: a container is created
 * and then published.
 *
 * ⛔ NOT CONFIGURED, AND SAYING SO IS THE POINT. Threads is not the Meta Graph
 * API. It has its own host (`graph.threads.net`), its own application and its
 * own scopes, and OpenRegister's credential provider catalogue carries no
 * `threads` entry at all. So {@see brokerProvider()} answers an empty string,
 * the gateway's readiness reports `not_configured` with a reason, the connect
 * flow refuses, and a post to a Threads account is recorded as a failed
 * publication with failure code `not_configured` rather than being attempted
 * and disappearing.
 *
 * The adapter is written anyway, and the request below is asserted against the
 * documented API, because the alternative is writing it in six months against
 * a different set of assumptions from the six adapters beside it. When the
 * provider is filed, this class needs one line changed.
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
 * @link https://developers.facebook.com/docs/threads/posts
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * Threads, published in two steps, with no provider filed yet.
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `SocialGatewayResult` and
 *  `SocialPublishOutcome` are value objects with NAMED CONSTRUCTORS
 *  (`succeeded`, `failed`, `published`, `refused`). Those are static by
 *  definition, and the alternative PHPMD is asking for, a factory injected
 *  into every adapter, would add a collaborator that constructs a struct.
 */
class ThreadsAdapter extends AbstractSocialAdapter {
	/**
	 * The network name, matching the schema enum.
	 *
	 * @return string The network name.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function network(): string {
		return 'threads';
	}//end network()

	/**
	 * No provider is filed for Threads, so this is empty on purpose and the
	 * readiness check turns it into a refusal with a reason.
	 *
	 * @return string An empty string.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function brokerProvider(): string {
		return '';
	}//end brokerProvider()

	/**
	 * A Threads post is 500 characters.
	 *
	 * @return int The character limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function bodyLimit(): int {
		return 500;
	}//end bodyLimit()

	/**
	 * The FIRST of the two calls: create the container.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 *
	 * @return SocialAdapterCall The container request.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		$media = $request->firstPublicMedia();
		$payload = [
			'media_type' => 'TEXT',
			'text' => $request->bodyWithLink(),
		];

		if ($media !== null) {
			$payload['media_type'] = 'IMAGE';
			$payload['image_url'] = (string)($media['url'] ?? '');
		}

		return new SocialAdapterCall(
			method: 'POST',
			path: '/v1.0/' . rawurlencode($request->externalAccountId) . '/threads',
			payload: $payload,
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
			path: '/v1.0/' . rawurlencode($request->externalAccountId) . '/threads_publish',
			payload: ['creation_id' => $containerId],
		);
	}//end publishContainerCall()

	/**
	 * Publish in two steps, once a provider exists. Until then the readiness
	 * check refuses before the first call is made.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 *
	 * @return SocialPublishOutcome The outcome.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function publish(SocialPublishRequest $request): SocialPublishOutcome {
		$refusal = $this->refuseWhenNotConfigured();
		if ($refusal !== null) {
			return $refusal;
		}

		$container = $this->send(call: $this->publishCall(request: $request), request: $request);
		if ($container->accepted === false) {
			return SocialPublishOutcome::fromGatewayFailure(result: $container);
		}

		$containerId = (string)($container->body['id'] ?? '');
		if ($containerId === '') {
			return SocialPublishOutcome::refused(
				code: SocialGatewayResult::REJECTED_BY_NETWORK,
				reason: 'Threads accepted the text but named no container, so there is nothing to publish.',
			);
		}

		$published = $this->send(
			call: $this->publishContainerCall(request: $request, containerId: $containerId),
			request: $request,
		);
		if ($published->accepted === false) {
			return SocialPublishOutcome::fromGatewayFailure(result: $published);
		}

		return SocialPublishOutcome::published(externalId: $this->readPublishedId(payload: $published->body));
	}//end publish()

	/**
	 * Threads' own composer, which takes prefilled text.
	 *
	 * @param SocialPublishRequest $request The prepared post.
	 *
	 * @return string An address that opens the composer.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function composerUrl(SocialPublishRequest $request): string {
		return 'https://www.threads.net/intent/post?text=' . rawurlencode($request->bodyWithLink());
	}//end composerUrl()
}//end class
