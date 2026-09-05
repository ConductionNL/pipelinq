<?php

/**
 * Pipelinq SocialNetworkAdapter.
 *
 * What every network has to be able to answer. Seven networks implement it,
 * and the interface is the reason the differences between them stay inside
 * their own file rather than spreading into the publishing job as a chain of
 * conditionals.
 *
 * Each method is split so that the SHAPE of a request can be asserted without
 * a network, a broker or a credential. That is not a testing convenience: for
 * LinkedIn, X, Facebook, Instagram and Threads it is the only assertion
 * available at all until the developer applications are filed, and an adapter
 * with no assertion is an adapter nobody can trust six months later.
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
 * One social network, as the publishing job needs to see it.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */
interface SocialNetworkAdapter {
	/**
	 * The network name, matching the `socialAccount.network` enum.
	 *
	 * @return string The network name.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function network(): string;

	/**
	 * The credential-broker provider this network connects through, or an
	 * empty string when no application has been filed for it at all.
	 *
	 * @return string The broker provider identifier.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function brokerProvider(): string;

	/**
	 * The longest body this network accepts, in characters.
	 *
	 * @return int The character limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function bodyLimit(): int;

	/**
	 * How many media items this network takes on one post.
	 *
	 * @return int The media limit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function maxMedia(): int;

	/**
	 * What one post costs on this network, in euros. Only X charges.
	 *
	 * @return float The cost per published post.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-to-x-stops-at-the-tenants-spend-budget
	 */
	public function costPerPost(): float;

	/**
	 * What one metrics read costs on this network, in euros. Only X charges,
	 * and it charges for reads as well as posts, which is why this is separate
	 * from the cost of publishing rather than folded into it.
	 *
	 * @return float The cost per metrics read.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-to-x-stops-at-the-tenants-spend-budget
	 */
	public function costPerRead(): float;

	/**
	 * The request this network documents for publishing the post.
	 *
	 * For a network that publishes in two steps this is the FIRST step; the
	 * second is issued by {@see publish()} once the first has answered.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 *
	 * @return SocialAdapterCall The request to make.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall;

	/**
	 * Publish the post and report what happened.
	 *
	 * @param SocialPublishRequest $request The resolved post.
	 *
	 * @return SocialPublishOutcome The network's id and address, or a named failure.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function publish(SocialPublishRequest $request): SocialPublishOutcome;

	/**
	 * The request that reads one published item's numbers back, or null when
	 * this network has no reachable way to report them.
	 *
	 * @param string $externalId The network's own id for the item.
	 * @param array<string, mixed> $account The account row the item was published to.
	 *
	 * @return SocialAdapterCall|null The request, or null when there is none.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall;

	/**
	 * Reduce this network's own payload to the five numbers every network can
	 * answer. A number the network does not report stays zero rather than
	 * being guessed from another one.
	 *
	 * @param array<string, mixed> $payload The network's response body.
	 * @param string $externalId The item being asked about, for the networks that answer with a feed.
	 *
	 * @return array{views: int, likes: int, comments: int, shares: int, clicks: int} The normalised numbers.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function normaliseMetrics(array $payload, string $externalId = ''): array;

	/**
	 * The request that reads the connected account's follower count, or null
	 * when this network's allow-rules reach no such endpoint.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return SocialAdapterCall|null The request, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function followersCall(array $account): ?SocialAdapterCall;

	/**
	 * The follower count in this network's own payload.
	 *
	 * @param array<string, mixed> $payload The network's response body.
	 *
	 * @return int The follower count, or 0 when the payload does not carry one.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function readFollowers(array $payload): int;

	/**
	 * A deep link into this network's own composer, for a colleague who has to
	 * post it themselves.
	 *
	 * @param SocialPublishRequest $request The prepared post.
	 *
	 * @return string An address that opens the network's composer.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function composerUrl(SocialPublishRequest $request): string;
}//end interface
