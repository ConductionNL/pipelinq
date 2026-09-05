<?php

/**
 * Pipelinq SocialMetricsPullJob.
 *
 * Reads every published publication's numbers back once a day, and refreshes
 * the follower count on every connected account, per ADR-069.
 *
 * Daily rather than hourly on purpose. Every network rate-limits these reads,
 * one of them charges for them, and the question a marketer asks the morning
 * after a post is not a question an hourly pull answers any better.
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
 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\SocialMetricsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The daily social metrics pull.
 *
 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
 */
class SocialMetricsPullJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param SocialMetricsService $metrics The pull and the normalisation.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SocialMetricsService $metrics,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Once a day.
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the pull.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob contract
	 *  passes an argument this job does not take.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	protected function run($argument): void {
		try {
			$this->logger->info('SocialMetricsPullJob complete', $this->metrics->pullAll());
		} catch (Throwable $failure) {
			$this->logger->error(
				'SocialMetricsPullJob failed',
				['exception' => $failure->getMessage()]
			);
		}
	}//end run()
}//end class
