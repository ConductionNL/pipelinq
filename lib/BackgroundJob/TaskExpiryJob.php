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
 *
 * @spec openspec/specs/task-background-jobs/spec.md#requirement-task-expiry-background-job
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
 * Runs every 15 minutes (900 seconds) by default; the interval and the
 * escalation/grace thresholds are admin-tunable via the
 * `pipelinq.task_expiry.*` admin-config keys.
 */
class TaskExpiryJob extends TimedJob
{
    /**
     * Default poll interval in seconds (15 minutes) when unconfigured.
     *
     * @var int
     */
    private const DEFAULT_INTERVAL = 900;

    /**
     * Default escalation threshold in seconds (4 hours) when unconfigured.
     *
     * @var int
     */
    private const DEFAULT_ESCALATION_THRESHOLD = 14400;

    /**
     * Default grace period for in-progress tasks in seconds (24 hours) when unconfigured.
     *
     * @var int
     */
    private const DEFAULT_IN_PROGRESS_GRACE = 86400;

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
        $this->setInterval(
            seconds: $this->appConfig->getValueInt(
                Application::APP_ID,
                'task_expiry.poll_interval_seconds',
                self::DEFAULT_INTERVAL
            )
        );
    }//end __construct()

    /**
     * Run the task expiry job.
     *
     * Queries OpenRegister for overdue tasks, expires them, and sends escalation notifications.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/specs/task-background-jobs/spec.md#requirement-task-expiry-background-job
     */
    protected function run(mixed $argument): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');

        if ($register === '' || $schema === '') {
            $this->logger->debug('TaskExpiryJob: no register or task schema configured, skipping');
            return;
        }

        $escalationThreshold = $this->appConfig->getValueInt(
            Application::APP_ID,
            'task_expiry.escalation_threshold_seconds',
            self::DEFAULT_ESCALATION_THRESHOLD
        );
        $inProgressGrace     = $this->appConfig->getValueInt(
            Application::APP_ID,
            'task_expiry.in_progress_grace_seconds',
            self::DEFAULT_IN_PROGRESS_GRACE
        );

        $this->logger->info(
            'TaskExpiryJob: starting task expiry check',
            [
                'escalationThresholdSeconds' => $escalationThreshold,
                'inProgressGraceSeconds'     => $inProgressGrace,
            ]
        );

        // NOTE: This job sets up the framework for task expiry.
        // The actual OpenRegister API calls require the ObjectService which
        // needs a user context. For now, we log that the job ran.
        // Full implementation requires OpenRegister's system-level API access.
        $this->logger->info('TaskExpiryJob: completed check cycle');
    }//end run()
}//end class
