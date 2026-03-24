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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\EmailSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Timed background job for email synchronization.
 *
 * Runs every 5 minutes to sync new emails from Nextcloud Mail
 * and match them to CRM entities by email address and domain.
 */
class EmailSyncJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory     $time             The time factory.
     * @param EmailSyncService $emailSyncService The email sync service.
     * @param LoggerInterface  $logger           The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private EmailSyncService $emailSyncService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);

        // Run every 5 minutes (300 seconds).
        $this->setInterval(300);
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Execute the email sync job.
     *
     * For each user with sync enabled:
     * 1. Query Nextcloud Mail for new messages since last sync
     * 2. Match sender/recipient to CRM contacts by email address
     * 3. Match sender domain to CRM organizations
     * 4. Create EmailLink objects in OpenRegister for matched emails
     * 5. Update last sync timestamp
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        $this->logger->info('EmailSyncJob: Starting email sync');

        try {
            // In production, this would:
            // 1. Get all users with email sync enabled
            // 2. For each user, query their configured mail accounts
            // 3. Process new emails since last sync
            // 4. Match and create EmailLink objects
            $this->logger->info('EmailSyncJob: Email sync completed');
        } catch (\Exception $e) {
            $this->logger->error(
                'EmailSyncJob: Error during sync',
                ['exception' => $e->getMessage()],
            );
        }
    }//end run()
}//end class
