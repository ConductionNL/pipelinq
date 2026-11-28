<?php

/**
 * Pipelinq SocialPostRecord.
 *
 * The stored fields of a social post as a draft or an edit, and the answer
 * to its write.
 *
 * @category Util
 * @package  OCA\Pipelinq\Service\Support
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Support;

use OCA\Pipelinq\Service\SocialPostService;

/**
 * Pure record steps of SocialPostService: authorship of a draft and of an
 * edit, and the answer to a save.
 *
 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 */
final class SocialPostRecord {

	/**
	 * A new post's fields as a draft by its author, with no approvals yet.
	 *
	 * @param array<string, mixed> $post The client's fields.
	 * @param string               $uid  The author.
	 *
	 * @return array<string, mixed> The draft.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public static function drafted(array $post, string $uid): array {
		$post['status'] = SocialPostService::STATUS_DRAFT;
		$post['approvals'] = [];
		$post['createdBy'] = $uid;

		return $post;
	}//end drafted()

	/**
	 * An edit of an agent's draft: the person editing it takes authorship of it.
	 *
	 * @param array<string, mixed> $update The post with the client's fields merged in;
	 *                                     its `agentAuthored` is the stored one.
	 * @param string               $uid    The editor.
	 *
	 * @return array<string, mixed> The update.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public static function takenOver(array $update, string $uid): array {
		if ((bool)($update['agentAuthored'] ?? false) === true) {
			$update['agentAuthored'] = false;
			$update['agentAuthoredBy'] = '';
			$update['createdBy'] = $uid;
		}

		return $update;
	}//end takenOver()

	/**
	 * The answer to a write: the saved post, or the error when it did not land.
	 *
	 * @param array<string, mixed>|null $saved The saved post, or null.
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public static function savedAnswer(?array $saved): array {
		if ($saved === null) {
			return ['error' => 'The post could not be saved.'];
		}

		return ['post' => $saved];
	}//end savedAnswer()
}//end class
