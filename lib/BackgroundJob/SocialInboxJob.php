<?php

/**
 * Pipelinq SocialInboxJob.
 *
 * Every five minutes, takes the new Facebook and Instagram messages of the
 * connected accounts into the Queue (requests-inbound-to-queue D5). The work
 * is SocialInboxService's; this job only schedules it and keeps a failure out
 * of the cron run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Social\SocialInboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the social inbox intake every five minutes.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
 */
class SocialInboxJob extends TimedJob {

	/**
	 * Five minutes.
	 *
	 * @var int
	 */
	private const INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time   The clock.
	 * @param SocialInboxService $inbox  The intake.
	 * @param LoggerInterface    $logger Where a pass is logged.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SocialInboxService $inbox,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Run one pass.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) A TimedJob takes an argument it does not need here.
	 */
	protected function run(mixed $argument): void {
		try {
			$totals = $this->inbox->run();
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq: the social inbox intake failed', ['exception' => $e->getMessage()]);
			return;
		}

		if ($totals['accounts'] > 0) {
			$this->logger->info('Pipelinq: social inbox intake complete', $totals);
		}
	}//end run()
}//end class
