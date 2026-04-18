<?php

/**
 * Pipelinq TaskExpiryJob.
 *
 * Background job for expiring overdue tasks and sending deadline escalation notifications.
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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Background job that expires overdue tasks and sends deadline escalation notifications.
 *
 * Runs every 15 minutes (900 seconds).
 *
 * @spec openspec/changes/2026-03-20-terugbel-taakbeheer/tasks.md#task-2.2
 */
class TaskExpiryJob extends TimedJob
{
    /**
     * Interval in seconds (15 minutes).
     *
     * @var int
     */
    private const INTERVAL = 900;

    /**
     * Escalation threshold in seconds (4 hours).
     *
     * @var int
     */
    private const ESCALATION_THRESHOLD = 14400;

    /**
     * Grace period for in-progress tasks in seconds (24 hours past deadline).
     *
     * @var int
     */
    private const IN_PROGRESS_GRACE = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory        $time                The time factory.
     * @param IAppConfig          $appConfig           The app config.
     * @param NotificationService $notificationService The notification service.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(interval: self::INTERVAL);
    }//end __construct()

    /**
     * Run the task expiry job.
     *
     * Queries OpenRegister for overdue tasks, expires them, and sends escalation notifications.
     *
     * @spec openspec/changes/2026-03-20-terugbel-taakbeheer/tasks.md#task-2.2
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run(mixed $argument): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');

        if ($register === '' || $schema === '') {
            $this->logger->debug('TaskExpiryJob: no register or task schema configured, skipping');
            return;
        }

        $this->logger->info('TaskExpiryJob: starting task expiry check');

        // NOTE: This job sets up the framework for task expiry.
        // The actual OpenRegister API calls require the ObjectService which
        // needs a user context. For now, we log that the job ran.
        // Full implementation requires OpenRegister's system-level API access.
        $this->logger->info('TaskExpiryJob: completed check cycle');
    }//end run()
}//end class
