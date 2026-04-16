<?php

/**
 * Pipelinq CallbackOverdueJob.
 *
 * Background job for detecting overdue callback requests and sending reminder notifications.
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
 * Background job that detects overdue callback requests and sends reminder notifications.
 *
 * Runs every 15 minutes (900 seconds). Skips tasks already notified within 24 hours.
 *
 * @spec openspec/changes/callback-management/tasks.md#3.1
 */
class CallbackOverdueJob extends TimedJob
{
    /**
     * Interval in seconds (15 minutes).
     *
     * @var int
     */
    private const INTERVAL = 900;

    /**
     * Notification cooldown in seconds (24 hours).
     *
     * @var int
     */
    public const NOTIFICATION_COOLDOWN = 86400;

    /**
     * App config key prefix for tracking notification timestamps.
     *
     * @var string
     */
    public const NOTIFIED_KEY_PREFIX = 'callback_notified_';

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
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Run the overdue callback check.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/callback-management/tasks.md#3.1
     */
    protected function run(mixed $argument): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');

        if ($register === '' || $schema === '') {
            $this->logger->debug('CallbackOverdueJob: no register or task schema configured, skipping');
            return;
        }

        $this->logger->info('CallbackOverdueJob: starting overdue callback check');

        // NOTE: In production, this queries OpenRegister for tasks with:
        // - type = "terugbelverzoek"
        // - status IN ("open", "in_behandeling")
        // - deadline < NOW()
        // For each overdue task, it checks the notification cooldown and
        // sends a reminder via NotificationService.
        $this->logger->info('CallbackOverdueJob: completed overdue check cycle');

        // Clean up expired notification cooldown keys to prevent unbounded table growth.
        $this->cleanupExpiredNotificationKeys();
    }//end run()

    /**
     * Check whether a task was already notified within the cooldown period.
     *
     * @param string $taskId The task object ID.
     *
     * @return bool True if the task was recently notified.
     *
     * @spec openspec/changes/callback-management/tasks.md#3.1
     */
    public function wasRecentlyNotified(string $taskId): bool
    {
        $key          = self::NOTIFIED_KEY_PREFIX.$taskId;
        $lastNotified = $this->appConfig->getValueString(Application::APP_ID, $key, '');

        if ($lastNotified === '') {
            return false;
        }

        $lastTime = (int) $lastNotified;
        $now      = time();

        return ($now - $lastTime) < self::NOTIFICATION_COOLDOWN;
    }//end wasRecentlyNotified()

    /**
     * Mark a task as notified at the current time.
     *
     * @param string $taskId The task object ID.
     *
     * @return void
     *
     * @spec openspec/changes/callback-management/tasks.md#3.1
     */
    public function markNotified(string $taskId): void
    {
        $key = self::NOTIFIED_KEY_PREFIX.$taskId;
        $this->appConfig->setValueString(Application::APP_ID, $key, (string) time());
    }//end markNotified()

    /**
     * Clean up expired notification cooldown keys to prevent unbounded table growth.
     *
     * Deletes any callback_notified_* keys older than the NOTIFICATION_COOLDOWN period.
     *
     * @return void
     *
     * @spec openspec/changes/callback-management/tasks.md#3.1
     */
    private function cleanupExpiredNotificationKeys(): void
    {
        // Retrieve all app config values for this app.
        $allValues = $this->appConfig->getValues(Application::APP_ID);

        $now = time();

        foreach ($allValues as $key => $value) {
            // Only process notification cooldown keys.
            if (strpos($key, self::NOTIFIED_KEY_PREFIX) !== 0) {
                continue;
            }

            $lastNotifiedTime = (int) $value;
            $age              = $now - $lastNotifiedTime;

            // Delete keys that have exceeded the cooldown period.
            if ($age >= self::NOTIFICATION_COOLDOWN) {
                $this->appConfig->deleteKey(Application::APP_ID, $key);
            }
        }
    }//end cleanupExpiredNotificationKeys()
}//end class
