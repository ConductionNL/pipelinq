<?php

/**
 * Pipelinq ExportSchedulerJob.
 *
 * Timed background job that, each minute, creates a pending export run for
 * every enabled export job whose cron schedule fires in the current minute.
 * The ExportWorkerJob then picks the pending run up and executes it. Creating
 * the run here (rather than running inline) keeps scheduling and execution
 * decoupled and lets the worker's per-job lock enforce no-overlap.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Creates pending runs for due, enabled export jobs.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
 */
class ExportSchedulerJob extends TimedJob
{
    /**
     * Poll interval in seconds (1 minute) — cron granularity is the minute.
     *
     * @var int
     */
    private const INTERVAL = 60;

    /**
     * Constructor.
     *
     * @param ITimeFactory         $time   The time factory (required by TimedJob).
     * @param ExportJobService     $jobs   The job service.
     * @param ExportRunService     $runs   The run service.
     * @param CronExpressionHelper $cron   The cron helper.
     * @param LoggerInterface      $logger The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private ExportJobService $jobs,
        private ExportRunService $runs,
        private CronExpressionHelper $cron,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Execute the scheduler: enqueue a pending run per due enabled job.
     *
     * @param mixed $argument The job argument (unused; required by TimedJob).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
     */
    protected function run(mixed $argument): void
    {
        $now = new DateTimeImmutable();

        try {
            $jobs = $this->jobs->listJobs();
        } catch (\Throwable $e) {
            $this->logger->error('ExportSchedulerJob: failed to list jobs', ['error' => $e->getMessage()]);
            return;
        }

        $enqueued = 0;
        foreach ($jobs as $job) {
            if (($job['enabled'] ?? false) !== true) {
                continue;
            }

            $cron = (string) ($job['scheduleCron'] ?? '');
            if ($this->cron->isDue(expression: $cron, when: $now) === false) {
                continue;
            }

            try {
                $watermarkFrom = $this->resolveWatermarkFrom(job: $job);
                $this->runs->createPendingRun(job: $job, watermarkFrom: $watermarkFrom);
                $enqueued++;
            } catch (\Throwable $e) {
                $this->logger->error(
                    'ExportSchedulerJob: failed to enqueue run',
                    ['jobId' => ($job['id'] ?? $job['uuid'] ?? ''), 'error' => $e->getMessage()]
                );
            }
        }//end foreach

        if ($enqueued > 0) {
            $this->logger->info('ExportSchedulerJob: enqueued export runs', ['count' => $enqueued]);
        }
    }//end run()

    /**
     * Resolve the incremental watermark start from the last succeeded run.
     *
     * @param array<string, mixed> $job The job.
     *
     * @return string|null The previous run's watermarkTo, or null.
     */
    private function resolveWatermarkFrom(array $job): ?string
    {
        if ((string) ($job['mode'] ?? 'full') !== 'incremental') {
            return null;
        }

        $jobId = (string) ($job['id'] ?? $job['uuid'] ?? '');
        if ($jobId === '') {
            return null;
        }

        $last = $this->runs->lastSucceededRun(jobId: $jobId);
        if ($last === null) {
            return null;
        }

        $watermarkTo = ($last['watermarkTo'] ?? null);
        if ($watermarkTo === null) {
            return null;
        }

        return (string) $watermarkTo;
    }//end resolveWatermarkFrom()
}//end class
