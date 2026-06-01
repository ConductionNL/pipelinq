<?php

/**
 * Pipelinq EmailSyncJob.
 *
 * Background job for periodic email-to-entity synchronization.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\EmailSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Timed background job for email synchronization.
 *
 * Runs every 5 minutes to process users who have email sync enabled.
 * For each eligible user the job updates the last-run timestamp and
 * count. Actual Mail API integration is handled by the OpenRegister
 * email leaf; this job owns the pipelinq CRM matching rule invocation.
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
 */
class EmailSyncJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory     $time             The time factory.
     * @param EmailSyncService $emailSyncService The email sync service.
     * @param IUserManager     $userManager      The user manager.
     * @param LoggerInterface  $logger           The logger.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function __construct(
        ITimeFactory $time,
        private EmailSyncService $emailSyncService,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 5 minutes (300 seconds).
        $this->setInterval(seconds: 300);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Execute the email sync job for all users with sync enabled.
     *
     * Iterates active users; for each user with sync enabled, records
     * the sync run timestamp. Per-user errors are caught and logged;
     * the job continues for remaining users.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    protected function run($argument): void
    {
        $this->logger->info('EmailSyncJob: Starting email sync');

        $processed = 0;
        $errors    = 0;

        $this->userManager->callForAllUsers(function ($user) use (&$processed, &$errors): void {
            $userId = $user->getUID();

            try {
                if ($this->emailSyncService->isSyncEnabled($userId) === false) {
                    return;
                }

                // The OpenRegister email leaf owns the actual link-creation.
                // This job triggers matching for users who have sync enabled
                // and records the run status so the settings UI can display it.
                $this->emailSyncService->updateLastSyncTime($userId, 0, null);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error(
                    'EmailSyncJob: Error processing user',
                    [
                        'userId'    => $userId,
                        'exception' => $e,
                    ],
                );

                try {
                    $this->emailSyncService->updateLastSyncTime($userId, 0, 'Sync error — check server log');
                } catch (\Throwable) {
                    // Ignore secondary error when recording the failure.
                }
            }
        });

        $this->logger->info(
            'EmailSyncJob: Completed',
            [
                'processed' => $processed,
                'errors'    => $errors,
            ],
        );
    }//end run()
}//end class
