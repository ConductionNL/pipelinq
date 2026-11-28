<?php

/**
 * Pipelinq ScheduledTaskJob.
 *
 * Timed background job that processes due scheduled tasks every 5 minutes:
 * status transitions, notification dispatch, and attempt logging.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Kernel\Contracts\ScheduledTaskServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TaskRecurrenceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that drives the Schedules API lifecycle.
 *
 * Runs every 5 minutes; never rethrows so the queue cannot be poisoned.
 *
 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-4
 */
class ScheduledTaskJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory (required by TimedJob).
	 * @param ScheduledTaskServiceInterface $scheduledTaskService The scheduled task service.
	 * @param LoggerInterface $logger Logger.
	 * @param TaskRecurrenceInterface $recurrence Makes the next occurrence of a repeating task.
	 */
	public function __construct(
		ITimeFactory $time,
		private ScheduledTaskServiceInterface $scheduledTaskService,
		private LoggerInterface $logger,
		private TaskRecurrenceInterface $recurrence,
	) {
		parent::__construct(time: $time);

		// Run every 5 minutes (300 seconds).
		$this->setInterval(seconds: 300);
		$this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
	}//end __construct()

	/**
	 * Execute the background job.
	 *
	 * Delegates all processing to ScheduledTaskService::processScheduledTasks(),
	 * then makes the next occurrence of each repeating task that came due.
	 * Catches every Throwable so the job queue remains healthy.
	 *
	 * @param mixed $argument The job argument (unused; required by TimedJob).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-4
	 * @spec openspec/changes/specify-capability-rows/specs/task-background-jobs/spec.md#requirement-a-task-with-a-repeat-schedule-comes-back-on-that-schedule
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		try {
			$this->scheduledTaskService->processScheduledTasks();
		} catch (\Throwable $e) {
			$this->logger->error(
				'ScheduledTaskJob failed',
				['exception' => $e]
			);
		}

		try {
			$this->recurrence->run();
		} catch (\Throwable $e) {
			$this->logger->error(
				'ScheduledTaskJob: repeating tasks failed',
				['exception' => $e]
			);
		}
	}//end run()
}//end class
