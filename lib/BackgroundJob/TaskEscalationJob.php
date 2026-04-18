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
 * @spec openspec/changes/2026-03-20-terugbel-taakbeheer/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Service\TaskService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
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
     * @param ITimeFactory       $time             The time factory.
     * @param TaskService        $taskService      The task service.
     * @param SettingsService    $settingsService  The settings service.
     * @param IAppManager        $appManager       The app manager.
     * @param ContainerInterface $container        The DI container.
     * @param LoggerInterface    $logger           The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private TaskService $taskService,
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 15 minutes (900 seconds).
        $this->setInterval(interval: 900);
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
     * @spec openspec/changes/2026-03-20-terugbel-taakbeheer/tasks.md#task-2
     */
    protected function run($argument): void
    {
        $this->logger->info('TaskEscalationJob: Starting deadline check');

        try {
            // Check if OpenRegister is installed
            if (!in_array('openregister', $this->appManager->getInstalledApps(), true)) {
                $this->logger->debug('TaskEscalationJob: OpenRegister not installed, skipping');
                return;
            }

            $config = $this->settingsService->getSettings();
            if (empty($config['register']) || empty($config['task_schema'])) {
                $this->logger->debug('TaskEscalationJob: Register or task_schema not configured');
                return;
            }

            // Get the ObjectService from OpenRegister
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Query for open and in_behandeling tasks
            $result = $objectService->findAll(
                register: $config['register'],
                schema: $config['task_schema'],
                filters: ['status' => ['open', 'in_behandeling'], '_limit' => 500]
            );

            $tasks = $result['results'] ?? [];
            $expiredCount = 0;
            $escalatedCount = 0;

            foreach ($tasks as $task) {
                if (empty($task['deadline'])) {
                    continue;
                }

                // Check if deadline has passed
                if ($this->taskService->isDeadlinePassed($task['deadline'])) {
                    if ($task['status'] === 'open') {
                        // Update task status to verlopen
                        $task['status'] = 'verlopen';
                        $objectService->saveObject(
                            register: $config['register'],
                            schema: $config['task_schema'],
                            object: $task
                        );
                        $expiredCount++;
                        $this->logger->info('Task marked as expired: ' . ($task['subject'] ?? 'unknown'));
                    }
                } elseif ($this->taskService->isDeadlineApproaching($task['deadline'], self::ESCALATION_THRESHOLD_HOURS)) {
                    // Log escalation for approaching deadline
                    $escalatedCount++;
                    $this->logger->warning('Task deadline approaching: ' . ($task['subject'] ?? 'unknown'));
                }
            }

            $this->logger->info(
                'TaskEscalationJob: Deadline check completed',
                ['expired' => $expiredCount, 'escalated' => $escalatedCount]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'TaskEscalationJob: Error during deadline check',
                ['exception' => $e->getMessage()],
            );
        }
    }//end run()
}//end class
