<?php

/**
 * Contract of SocialPostService
 *
 * The public surface of SocialPostService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of SocialPostService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SocialPostServiceInterface {

	/**
	 * Approve a post so it may go out at its moment.
	 *
	 * @param string $postId The post.
	 * @param string $uid The approver, taken from the session.
	 * @param string $note Their words, when they left any.
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function approve(string $postId, string $uid, string $note = ''): array;

	/**
	 * Create a post as a draft.
	 *
	 * @param array<string, mixed> $payload The client's fields.
	 * @param string $uid The author, stamped from the session.
	 * @param string $agent The agent that drafted it, when an agent did (ADR-088).
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function createPost(array $payload, string $uid, string $agent = ''): array;

	/**
	 * One post, or null.
	 *
	 * @param string $postId The post.
	 *
	 * @return array<string, mixed>|null The post, or null.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function getPost(string $postId): ?array;

	/**
	 * Every post, optionally filtered by status.
	 *
	 * @param string $status A status to filter on, or an empty string for all.
	 *
	 * @return array{data: array<int, array<string, mixed>>} The posts.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function listPosts(string $status = ''): array;

	/**
	 * Publish every approved post whose moment has arrived.
	 *
	 * @param string|null $now The moment to compare against, for tests. Defaults to now.
	 *
	 * @return int How many posts were attempted.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function publishDuePosts(?string $now = null): int;

	/**
	 * Reject a post, sending it back to the marketer.
	 *
	 * @param string $postId The post.
	 * @param string $uid The reviewer, taken from the session.
	 * @param string $note Why.
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function reject(string $postId, string $uid, string $note = ''): array;

	/**
	 * Try one failed publication again.
	 *
	 * A failure a retry cannot fix is refused with the reason it already
	 * carries, rather than making the same call a second time to fail the same
	 * way.
	 *
	 * @param string $publicationId The publication.
	 * @param string $uid The caller.
	 *
	 * @return array{error?: string, publication?: array<string, mixed>} The row, or an error.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function retryPublication(string $publicationId, string $uid): array;

	/**
	 * Put a draft up for approval, refusing when a variant does not fit.
	 *
	 * @param string $postId The post.
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function submitForApproval(string $postId): array;

	/**
	 * Update a draft. A post that is not a draft cannot be edited: it has
	 * either been approved or already gone out.
	 *
	 * @param string $postId The post.
	 * @param array<string, mixed> $payload The client's fields.
	 * @param string $uid The editor, who takes authorship from an agent.
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function updatePost(string $postId, array $payload, string $uid): array;
}//end interface
