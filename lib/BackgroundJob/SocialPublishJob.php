<?php

/**
 * Pipelinq SocialPublishJob.
 *
 * Publishes every approved post whose scheduled moment has arrived, per
 * ADR-069. Five minutes is the interval: a marketer schedules a post for a
 * time of day rather than a second, and a shorter interval would mean seven
 * networks polled far more often than anything changes.
 *
 * The job holds no identity of its own. Each account's own owner is asserted
 * to the credential broker by `SocialPostService`, which is ADR-099's rule:
 * the identity a run executes as belongs to the run's subject, not to whoever
 * happened to author or approve the post.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
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

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\SocialPostService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publish scheduled social posts whose moment has arrived.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
class SocialPublishJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param SocialPostService $posts The publishing service.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SocialPostService $posts,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Every five minutes.
		$this->setInterval(seconds: 300);
	}//end __construct()

	/**
	 * Run the publish sweep.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob contract
	 *  passes an argument this job does not take.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	protected function run($argument): void {
		try {
			$attempted = $this->posts->publishDuePosts();
			if ($attempted > 0) {
				$this->logger->info('SocialPublishJob published due posts', ['posts' => $attempted]);
			}
		} catch (Throwable $failure) {
			$this->logger->error(
				'SocialPublishJob failed',
				['exception' => $failure->getMessage()]
			);
		}
	}//end run()
}//end class
