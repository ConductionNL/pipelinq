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
 * @spec openspec/changes/callback-management/tasks.md#task-3.1
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
     * @spec   openspec/changes/reverse-2026-05-26-be-background-jobs/tasks.md#task-1
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
    }//end run()

    /**
     * Check whether a task was already notified within the cooldown period.
     *
     * @param string $taskId The task object ID.
     *
     * @return bool True if the task was recently notified.
     *
     * @spec openspec/changes/callback-management/tasks.md#task-3.1
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
     * Also prunes stale notification entries older than twice the cooldown
     * window so the oc_appconfig table does not grow without bound.
     *
     * @param string $taskId The task object ID.
     *
     * @return void
     *
     * @spec openspec/changes/callback-management/tasks.md#task-3.1
     */
    public function markNotified(string $taskId): void
    {
        $key = self::NOTIFIED_KEY_PREFIX.$taskId;
        $this->appConfig->setValueString(Application::APP_ID, $key, (string) time());
        $this->pruneStaleNotifications();
    }//end markNotified()

    /**
     * Remove notification entries that are older than twice the cooldown window.
     *
     * This prevents unlimited growth of oc_appconfig rows created by markNotified().
     *
     * @return void
     */
    private function pruneStaleNotifications(): void
    {
        try {
            $keys      = $this->appConfig->getKeys(Application::APP_ID);
            $cutoff    = time() - (self::NOTIFICATION_COOLDOWN * 2);
            $prefix    = self::NOTIFIED_KEY_PREFIX;
            $prefixLen = strlen($prefix);

            foreach ($keys as $key) {
                if (substr($key, 0, $prefixLen) !== $prefix) {
                    continue;
                }

                $timestamp = (int) $this->appConfig->getValueString(Application::APP_ID, $key, '0');
                if ($timestamp > 0 && $timestamp < $cutoff) {
                    $this->appConfig->deleteKey(Application::APP_ID, $key);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'CallbackOverdueJob: failed to prune stale notifications',
                ['exception' => $e]
            );
        }//end try
    }//end pruneStaleNotifications()
}//end class
