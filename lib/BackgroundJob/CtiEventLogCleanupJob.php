<?php

/**
 * Pipelinq CtiEventLogCleanupJob.
 *
 * Background job that purges cti_event_log entries older than 30 days.
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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-7.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\CtiService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Scheduled cleanup of the CTI event log (30-day retention).
 *
 * Runs once per day; deletes ctiEventLog entries whose `received_at` is older
 * than 30 days. Number of deleted records is logged for audit.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-7.2
 */
class CtiEventLogCleanupJob extends TimedJob {
	/**
	 * Run interval in seconds (24 hours).
	 *
	 * @var int
	 */
	private const INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param CtiService $ctiService The CTI service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private CtiService $ctiService,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Run the daily cleanup.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 */
	protected function run(mixed $argument): void {
		try {
			$deleted = $this->ctiService->purgeOldEventLog();
			$this->logger->info(
				'CTI event log cleanup completed',
				['deleted' => $deleted]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI event log cleanup failed',
				['exception' => $e->getMessage()]
			);
		}
	}//end run()
}//end class
