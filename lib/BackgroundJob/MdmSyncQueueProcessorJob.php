<?php

/**
 * Pipelinq MdmSyncQueueProcessorJob.
 *
 * Drains the outbound MDM sync queue every five minutes, delivering due items
 * to downstream apps with exponential-backoff retries and dead-lettering after
 * the maximum attempts.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Mdm\SyncQueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background processor for the outbound downstream sync queue.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) run()'s $argument is required
 *  by the TimedJob contract but unused by this job.
 */
class MdmSyncQueueProcessorJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory     $time      The time factory.
     * @param SyncQueueService $syncQueue The sync queue service.
     * @param LoggerInterface  $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private SyncQueueService $syncQueue,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 5 minutes.
        $this->setInterval(seconds: 300);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Process due sync-queue items.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        try {
            $stats = $this->syncQueue->processQueue();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq MDM: sync queue processing failed',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        if ($stats['processed'] > 0) {
            $this->logger->info('Pipelinq MDM: sync queue processed', $stats);
        }
    }//end run()
}//end class
