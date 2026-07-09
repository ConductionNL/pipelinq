<?php

/**
 * Pipelinq WalkInQueueRebalanceJob.
 *
 * On-demand background job that recomputes `estimatedReadyAt` for every waiting
 * WalkInTicket — triggered after a Booking completes (member 04 event) so the
 * walk-in queue panel surfaces fresh ETAs without page reloads.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-09-walkin-queue/specs/appointment-booking/spec.md#req-apt-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\WalkInQueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\Job;
use Psr\Log\LoggerInterface;

/**
 * On-demand background job that rebalances walk-in ticket ETAs.
 *
 * Extends `OCP\BackgroundJob\Job` (NOT TimedJob) — runs only when explicitly
 * scheduled by BookingService::completeBooking via the JobList. Each invocation
 * delegates to {@see WalkInQueueService::rebalance()} and logs the touched
 * ticket count.
 *
 * @spec openspec/changes/appointment-booking-09-walkin-queue/specs/appointment-booking/spec.md#req-apt-012
 */
class WalkInQueueRebalanceJob extends Job
{
    /**
     * Constructor.
     *
     * @param ITimeFactory       $time    The time factory (parent contract).
     * @param WalkInQueueService $service The walk-in queue service.
     * @param LoggerInterface    $logger  The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private WalkInQueueService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Run the rebalance.
     *
     * @param mixed $argument Optional payload (unused; the job rebalances every
     *                        waiting ticket each invocation).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
     *
     * @spec openspec/changes/appointment-booking-09-walkin-queue/specs/appointment-booking/spec.md#req-apt-012
     */
    protected function run(mixed $argument): void
    {
        try {
            $touched = $this->service->rebalance();
            $this->logger->info(
                'WalkInQueueRebalanceJob: rebalance completed',
                ['touched' => $touched]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'WalkInQueueRebalanceJob: rebalance failed',
                ['exception' => $e]
            );
        }
    }//end run()
}//end class
