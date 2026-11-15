<?php

/**
 * Pipelinq PostReview.
 *
 * Who reviews which social post: the post id and the reviewer from the session.
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
 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A post under review by one person.
 *
 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 */
final class PostReview {
	/**
	 * Constructor.
	 *
	 * @param string $postId The post.
	 * @param string $uid    The reviewer, taken from the session.
	 */
	public function __construct(
		public readonly string $postId,
		public readonly string $uid,
	) {
	}//end __construct()
}//end class
