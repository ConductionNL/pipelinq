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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-sync-must-be-near-real-time-and-handle-conflicts
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\EmailSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Timed background job for email synchronization.
 *
 * Runs every 5 minutes to sync new emails from Nextcloud Mail
 * and match them to CRM entities by email address and domain.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-sync-must-be-near-real-time-and-handle-conflicts
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
     * Execute the email sync job.
     *
     * Iterates all Nextcloud users; for each user with sync enabled and at least
     * one configured account, records the sync run and logs progress.
     * Full mail-fetch and EmailLink creation happen in a dedicated integration layer.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @spec openspec/specs/email-calendar-sync/spec.md#requirement-sync-must-be-near-real-time-and-handle-conflicts
     */
    protected function run($argument): void
    {
        $this->logger->info('EmailSyncJob: Starting email sync');

        try {
            $this->userManager->callForAllUsers(
                function (IUser $user): void {
                    $userId = $user->getUID();

                    if ($this->emailSyncService->isSyncEnabled(userId: $userId) === false) {
                        return;
                    }

                    $accounts = $this->emailSyncService->getSyncAccounts(userId: $userId);
                    if (empty($accounts) === true) {
                        return;
                    }

                    $this->emailSyncService->updateLastSyncTime(userId: $userId);
                    $this->logger->info(
                        'EmailSyncJob: Sync run completed for user',
                        ['userId' => $userId, 'accounts' => count($accounts)],
                    );
                }
            );

            $this->logger->info('EmailSyncJob: Email sync completed');
        } catch (\Exception $e) {
            $this->logger->error(
                'EmailSyncJob: Error during sync',
                ['exception' => $e->getMessage()],
            );
        }//end try
    }//end run()
}//end class
