<?php

/**
 * Pipelinq QueueOverflowJob.
 *
 * Background job for monitoring queue capacities and routing overflow items.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reverse-2026-05-26-be-background-jobs/tasks.md#task-2
 * @spec openspec/changes/queue-management/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\QueueService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Timed background job that checks queue capacities and moves overflow items.
 *
 * Runs every 5 minutes (300 seconds) by default; the interval is admin-tunable
 * via `pipelinq.queue_overflow.poll_interval_seconds`.
 *
 * @spec openspec/changes/reverse-2026-05-26-be-background-jobs/tasks.md#task-2
 */
class QueueOverflowJob extends TimedJob
{
    /**
     * Default poll interval in seconds (5 minutes) when unconfigured.
     *
     * @var int
     */
    private const DEFAULT_INTERVAL = 300;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time            The time factory.
     * @param QueueService    $queueService    The queue service.
     * @param SettingsService $settingsService The settings service.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private QueueService $queueService,
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(
            seconds: $this->settingsService->getIntValue(
                'queue_overflow.poll_interval_seconds',
                self::DEFAULT_INTERVAL
            )
        );
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
     * @spec                                          openspec/changes/reverse-2026-05-26-be-background-jobs/tasks.md#task-2
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
