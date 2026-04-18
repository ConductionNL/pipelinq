<?php

/**
 * Pipelinq QueueOverflowJob.
 *
 * Background job for monitoring queue capacities and routing overflow items.
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
 * @spec openspec/changes/queue-management/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\QueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Timed background job that checks queue capacities and moves overflow items.
 *
 * Runs every 5 minutes (300 seconds).
 */
class QueueOverflowJob extends TimedJob
{
    /**
     * Interval in seconds (5 minutes).
     *
     * @var int
     */
    private const INTERVAL = 300;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time         The time factory.
     * @param QueueService    $queueService The queue service.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private QueueService $queueService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(interval: self::INTERVAL);
    }//end __construct()

    /**
     * Execute the background job.
     *
     * Delegates to QueueService::processOverflow() to check all queues
     * and move excess items to their configured overflow targets.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @spec                                          openspec/changes/queue-management/tasks.md#task-12
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('QueueOverflowJob: Starting overflow check');

        try {
            $moved = $this->queueService->processOverflow();

            if ($moved > 0) {
                $this->logger->info("QueueOverflowJob: Moved {$moved} items to overflow queues");
            }

            if ($moved === 0) {
                $this->logger->debug('QueueOverflowJob: No overflow items to move');
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'QueueOverflowJob: Error during overflow check',
                ['exception' => $e->getMessage()]
            );
        }//end try

        $this->logger->info('QueueOverflowJob: Overflow check completed');
    }//end run()
}//end class
