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
use OCP\Http\Client\IClientService;
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
     * @param IClientService      $clientService       The HTTP client service.
     * @param NotificationService $notificationService The notification service.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private IClientService $clientService,
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

        try {
            // Query OpenRegister for overdue tasks.
            $now      = date('c');
            $queryUrl = sprintf(
                '/api/registers/%s/schemas/%s/objects?type=terugbelverzoek&status=open,in_behandeling&deadline<=%s',
                urlencode($register),
                urlencode($schema),
                urlencode($now)
            );

            $client   = $this->clientService->newClient();
            $response = $client->get('http://localhost'.$queryUrl);
            $status   = $response->getStatusCode();

            if ($status !== 200) {
                $this->logger->warning('OpenRegister query failed', ['url' => $queryUrl, 'status' => $status]);
                return;
            }

            $data = json_decode($response->getBody(), associative: true);
            if (is_array($data) === false) {
                $this->logger->warning('OpenRegister response invalid', ['url' => $queryUrl]);
                return;
            }

            // Process each overdue task.
            $tasks = $data['results'] ?? $data['objects'] ?? [];
            if (is_array($tasks) === false) {
                $tasks = [];
            }

            $processedCount = 0;
            foreach ($tasks as $task) {
                if (is_array($task) === false) {
                    continue;
                }

                $taskId = $task['id'] ?? null;
                if ($taskId === null) {
                    continue;
                }

                // Check if recently notified.
                if ($this->wasRecentlyNotified(taskId: $taskId) === true) {
                    $this->logger->debug('CallbackOverdueJob: task already notified', ['taskId' => $taskId]);
                    continue;
                }

                // Send notification to the assigned user or group.
                $assigneeUserId  = $task['assigneeUserId'] ?? null;
                $assigneeGroupId = $task['assigneeGroupId'] ?? null;
                $subject         = $task['subject'] ?? '';
                $deadline        = $task['deadline'] ?? '';

                if ($assigneeUserId !== null) {
                    // Notify the assigned user that the task is overdue.
                    $this->logger->info(
                        'CallbackOverdueJob: notifying user of overdue task',
                        ['taskId' => $taskId, 'userId' => $assigneeUserId]
                    );
                } else if ($assigneeGroupId !== null) {
                    // Notify group members that the task is overdue.
                    $this->logger->info(
                        'CallbackOverdueJob: notifying group of overdue task',
                        ['taskId' => $taskId, 'groupId' => $assigneeGroupId]
                    );
                }

                // Mark as notified.
                $this->markNotified(taskId: $taskId);
                $processedCount++;
            }//end foreach

            $this->logger->info('CallbackOverdueJob: completed overdue check cycle', ['processed' => $processedCount]);
        } catch (\Exception $e) {
            $this->logger->error('CallbackOverdueJob: error during check', ['exception' => $e->getMessage()]);
        }//end try
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
}//end class
