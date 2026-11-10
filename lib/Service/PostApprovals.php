<?php

/**
 * Pipelinq PostApprovals.
 *
 * The approval list of a social post: the entry one review decision adds,
 * and the list with that entry appended.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The approvals of a social post; built by SocialPostService.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
final class PostApprovals {
	/**
	 * The approval entry for one decision.
	 *
	 * The approver is the session, always. A body naming somebody else is
	 * ignored rather than refused, because refusing would tell a caller
	 * which field it guessed right.
	 *
	 * @param PostReview    $review  The post and the person deciding.
	 * @param ReviewVerdict $verdict The decision and its note.
	 *
	 * @return array{userId: string, decision: string, decidedAt: string, note: string}
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function entryFor(PostReview $review, ReviewVerdict $verdict): array {
		return [
			'userId' => $review->uid,
			'decision' => $verdict->decision,
			'decidedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'note' => $verdict->note,
		];
	}//end entryFor()


	/**
	 * The post's approvals with one more appended (a malformed list starts over).
	 *
	 * @param array<string, mixed>  $post     The post.
	 * @param array<string, string> $approval The new approval entry.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function appended(array $post, array $approval): array {
		$approvals = ($post['approvals'] ?? []);
		if (is_array($approvals) === false) {
			$approvals = [];
		}

		$approvals[] = $approval;

		return $approvals;
	}//end appended()
}//end class
