<?php

/**
 * Pipelinq EmailSyncJob.
 *
 * Background job for syncing emails with CRM entities.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\EmailSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job for periodically syncing new emails with CRM entities.
 *
 * Runs every 5 minutes to check for new emails and link them to relevant CRM entities.
 */
class EmailSyncJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory     $timeFactory      The time factory.
     * @param EmailSyncService $emailSyncService The email sync service.
     * @param LoggerInterface  $logger           The logger.
     */
    public function __construct(
        ITimeFactory $timeFactory,
        private EmailSyncService $emailSyncService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(timeFactory: $timeFactory);
        // Run every 5 minutes.
        $this->setInterval(interval: 5 * 60);
    }//end __construct()

    /**
     * Run the background job.
     *
     * @param mixed $argument Job argument (unused)
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-2.3
     */
    protected function run($argument): void
    {
        $this->logger->info('Starting email sync job');

        try {
            // The background job runs every 5 minutes to check for new emails
            // and link them to relevant CRM entities.
            //
            // In a complete implementation, this would:
            // 1. Fetch new emails from Nextcloud Mail.
            // 2. Extract sender and recipient addresses.
            // 3. Match them to CRM entities using EmailSyncService.
            // 4. Create emailLink records in the register.
            // 5. Log any errors or mismatches.
            //
            // Current state: foundation implementation with service scaffolding.
            // See EmailSyncService for matching logic and tasks.md#task-2.3 for acceptance criteria.
            $this->logger->info('Email sync job: foundation implementation in progress');
        } catch (\Exception $e) {
            $this->logger->error(
                'Email sync job failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]
            );
        }
    }//end run()
}//end class
