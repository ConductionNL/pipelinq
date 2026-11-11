<?php

/**
 * Pipelinq AccountPublishRun.
 *
 * One post being published to one account: the post, the account and the
 * publication row opened for it.
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * One publish of a post to an account; built and read by SocialPostService.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
final class AccountPublishRun {
	/**
	 * The publication row opened for this account.
	 *
	 * @var array<string, mixed>
	 */
	public array $publication = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $post    The post.
	 * @param PublishingAccount    $account The account.
	 */
	public function __construct(
		public readonly array $post,
		public readonly PublishingAccount $account,
	) {
	}//end __construct()
}//end class
