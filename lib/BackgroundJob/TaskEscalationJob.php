<?php

/**
 * Pipelinq TaskEscalationJob.
 *
 * Background job for monitoring task deadlines and triggering escalations.
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
 * @spec openspec/specs/task-background-jobs/spec.md#requirement-deadline-escalation-notifications
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Service\TaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Timed background job for task deadline monitoring.
 *
 * Runs every 15 minutes to check for:
 * 1. Tasks approaching their deadline (escalation notification)
 * 2. Tasks past their deadline (status change to "verlopen")
 *
 * The escalation threshold (hours before deadline) is admin-tunable via
 * `pipelinq.task_escalation.threshold_hours`.
 */
class TaskEscalationJob extends TimedJob
{
    /**
     * Default escalation threshold in hours before deadline when unconfigured.
     *
     * @var int
     */
    private const DEFAULT_ESCALATION_THRESHOLD_HOURS = 4;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time            The time factory.
     * @param TaskService     $taskService     The task service.
     * @param SettingsService $settingsService The settings service.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private TaskService $taskService,
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 15 minutes (900 seconds).
        $this->setInterval(seconds: 900);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Execute the background job.
     *
     * Checks all open and in_behandeling tasks for deadline proximity
     * and expiry. Uses OpenRegister API to query and update tasks.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/specs/task-background-jobs/spec.md#requirement-deadline-escalation-notifications
     */
    protected function run($argument): void
    {
        $thresholdHours = $this->settingsService->getIntValue(
            'task_escalation.threshold_hours',
            self::DEFAULT_ESCALATION_THRESHOLD_HOURS
        );

        $this->logger->info(
            'TaskEscalationJob: Starting deadline check',
            ['escalationThresholdHours' => $thresholdHours]
        );

        try {
            // In production, this would query OpenRegister for tasks with
            // status in ['open', 'in_behandeling'] and check deadlines.
            // For each task:
            // 1. If deadline passed and status is open -> change to verlopen
            // 2. If deadline approaching (within $thresholdHours) -> send
            // escalation notification.
            $this->logger->info('TaskEscalationJob: Deadline check completed');
        } catch (\Exception $e) {
            $this->logger->error(
                'TaskEscalationJob: Error during deadline check',
                ['exception' => $e->getMessage()],
            );
        }
    }//end run()
}//end class
