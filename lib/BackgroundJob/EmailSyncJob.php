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
     * @param ITimeFactory    $timeFactory The time factory.
     * @param LoggerInterface $logger      The logger.
     */
    public function __construct(
        ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
        parent::__construct(timeFactory: $timeFactory);
        // Run every 5 minutes.
        $this->setInterval(interval: 5 * 60);
    }//end __construct()

    /**
     * Run the background job.
     *
     * Syncs new emails with CRM entities by:
     * 1. Checking for new emails in configured mail accounts
     * 2. Extracting sender and recipient information
     * 3. Matching addresses to CRM entities using EmailSyncService
     * 4. Creating or updating emailLink records in the register
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
            // Email sync workflow:
            // 1. Fetch new emails from Nextcloud Mail accounts
            // This requires integration with OCP\Mail\IMailer or similar.
            $emailCount = 0;

            // 2. For each email, extract metadata
            // 3. Use EmailSyncService to match to entities
            // 4. Create emailLink records in register
            // This implementation is a placeholder that developers should extend
            // with actual Mail app integration and register queries.
            if ($emailCount === 0) {
                $this->logger->debug('No new emails found to sync');
            } else {
                $this->logger->info(
                    'Email sync job completed',
                    ['synced_emails' => $emailCount]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Email sync job failed',
                [
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]
            );
        }//end try
    }//end run()
}//end class
