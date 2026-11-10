<?php

/**
 * Pipelinq ReviewVerdict.
 *
 * What a reviewer decided on a social post, and the words they left with it.
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A review decision with its note.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 */
final class ReviewVerdict {
	/**
	 * Constructor.
	 *
	 * @param string $decision `approved` or `rejected`.
	 * @param string $note     Their words.
	 */
	public function __construct(
		public readonly string $decision,
		public readonly string $note,
	) {
	}//end __construct()
}//end class
