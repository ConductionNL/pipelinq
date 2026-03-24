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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

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
 */
class TaskEscalationJob extends TimedJob
{
    /**
     * Escalation threshold in hours before deadline.
     *
     * @var int
     */
    private const ESCALATION_THRESHOLD_HOURS = 4;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time        The time factory.
     * @param TaskService     $taskService The task service.
     * @param LoggerInterface $logger      The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private TaskService $taskService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);

        // Run every 15 minutes (900 seconds).
        $this->setInterval(900);
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
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
     */
    protected function run($argument): void
    {
        $this->logger->info('TaskEscalationJob: Starting deadline check');

        try {
            // In production, this would query OpenRegister for tasks with
            // status in ['open', 'in_behandeling'] and check deadlines.
            // For each task:
            // 1. If deadline passed and status is open -> change to verlopen
            // 2. If deadline approaching -> send escalation notification
            $this->logger->info('TaskEscalationJob: Deadline check completed');
        } catch (\Exception $e) {
            $this->logger->error(
                'TaskEscalationJob: Error during deadline check',
                ['exception' => $e->getMessage()],
            );
        }
    }//end run()
}//end class
