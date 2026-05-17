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
 * @spec openspec/changes/task-background-jobs/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\ScheduledTaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that drives the Schedules API lifecycle.
 *
 * Runs every 5 minutes; never rethrows so the queue cannot be poisoned.
 *
 * @spec openspec/changes/task-background-jobs/tasks.md#task-4
 */
class ScheduledTaskJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory         $time                 Time factory (required by TimedJob).
     * @param ScheduledTaskService $scheduledTaskService The scheduled task service.
     * @param LoggerInterface      $logger               Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private ScheduledTaskService $scheduledTaskService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 5 minutes (300 seconds).
        $this->setInterval(seconds: 300);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Execute the background job.
     *
     * Delegates all processing to ScheduledTaskService::processScheduledTasks().
     * Catches every Throwable so the job queue remains healthy.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/task-background-jobs/tasks.md#task-4
     */
    protected function run($argument): void
    {
        try {
            $this->scheduledTaskService->processScheduledTasks();
        } catch (\Throwable $e) {
            $this->logger->error(
                'ScheduledTaskJob failed',
                ['exception' => $e]
            );
        }
    }//end run()
}//end class
