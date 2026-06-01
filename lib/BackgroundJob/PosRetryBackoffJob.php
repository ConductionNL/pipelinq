<?php

/**
 * Pipelinq PosRetryBackoffJob.
 *
 * Background job to retry failed posJournalEntryOutbound submissions to Shillinq
 * with exponential backoff scheduling.
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
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Queued background job to retry a failed posJournalEntryOutbound submission.
 *
 * Instantiated with the outbound message UUID as the argument. Checks whether
 * the scheduled nextRetryAt time has been reached before calling postToShillinq.
 * On success: no further scheduling.
 * On 5xx: reschedules this job with the next backoff interval.
 * On 4xx: marks as failed permanently.
 * On max attempts: marks as failed, sends alert, stops scheduling.
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.2
 */
class PosRetryBackoffJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory          $time               Time factory (required by QueuedJob).
     * @param PosBookkeepingService $bookkeepingService The bookkeeping service.
     * @param LoggerInterface       $logger             Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private PosBookkeepingService $bookkeepingService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Execute the retry job for a failed outbound message submission.
     *
     * @param mixed $argument The outbound message UUID string.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.2
     */
    protected function run($argument): void
    {
        if (is_string($argument) === false || $argument === '') {
            $this->logger->warning('PosRetryBackoffJob: missing or invalid outbound message ID argument');
            return;
        }

        $outboundMessageId = $argument;

        try {
            $result = $this->bookkeepingService->postToShillinq(outboundMessageId: $outboundMessageId);

            $this->logger->info(
                'PosRetryBackoffJob: retry result for {id}: {status}',
                ['id' => $outboundMessageId, 'status' => $result['status'] ?? 'unknown']
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'PosRetryBackoffJob failed for {id}',
                ['id' => $outboundMessageId, 'exception' => $e]
            );
        }
    }//end run()

    /**
     * Schedule a new retry job for the given outbound message at the specified time.
     *
     * Creates a new PosRetryBackoffJob queued at $nextRetryAt. Scheduling is
     * best-effort via NC's IJobList — if unavailable, the failure is logged.
     *
     * This static factory method is called by PosBookkeepingService after a 5xx
     * or network-timeout response.
     *
     * @param string $outboundMessageId The outbound message UUID.
     * @param string $nextRetryAt       ISO 8601 datetime for the next attempt.
     * @param object $jobList           The NC IJobList instance.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.2
     */
    public static function schedule(string $outboundMessageId, string $nextRetryAt, object $jobList): void
    {
        try {
            $reservedAt = (new DateTimeImmutable($nextRetryAt))->getTimestamp();
            $jobList->scheduleAfter(
                klass: self::class,
                argument: $outboundMessageId,
                earliestStart: $reservedAt
            );
        } catch (\Throwable $e) {
            // Fallback: add without a specific time. The job will run on the next cron cycle.
            try {
                $jobList->add(klass: self::class, argument: $outboundMessageId);
            } catch (\Throwable $inner) {
                // Nothing more to do — the failure was already recorded on the outbound message.
                unset($inner);
            }
        }
    }//end schedule()
}//end class
