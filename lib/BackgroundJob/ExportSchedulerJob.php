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
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\Kernel\Contracts\CronExpressionHelperInterface;
use OCA\Pipelinq\Kernel\Contracts\ExportJobServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ExportRunServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Creates pending runs for due, enabled export jobs.
 *
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
 */
class ExportSchedulerJob extends TimedJob {
	/**
	 * Poll interval in seconds (1 minute) — cron granularity is the minute.
	 *
	 * @var int
	 */
	private const INTERVAL = 60;

	/**
	 * How far back a tick looks for a missed minute, in seconds.
	 *
	 * @var int
	 */
	private const CATCH_UP_WINDOW = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory (required by TimedJob).
	 * @param ExportJobServiceInterface $jobs The job service.
	 * @param ExportRunServiceInterface $runs The run service.
	 * @param CronExpressionHelperInterface $cron The cron helper.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private ExportJobServiceInterface $jobs,
		private ExportRunServiceInterface $runs,
		private CronExpressionHelperInterface $cron,
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
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
	 */
	protected function run(mixed $argument): void {
		$now = (new DateTimeImmutable())->setTimestamp($this->time->getTime());

		try {
			$jobs = $this->jobs->listJobs();
		} catch (\Throwable $e) {
			$this->logger->error('ExportSchedulerJob: failed to list jobs', ['error' => $e->getMessage()]);
			return;
		}

		$enqueued = 0;
		foreach ($jobs as $job) {
			if ($this->isScheduledNow(job: $job, now: $now) === false) {
				continue;
			}

			if ($this->enqueue(job: $job) === true) {
				$enqueued++;
			}
		}

		if ($enqueued > 0) {
			$this->logger->info('ExportSchedulerJob: enqueued export runs', ['count' => $enqueued]);
		}
	}//end run()

	/**
	 * Whether the job is enabled and its schedule fired since the previous tick.
	 *
	 * @param array<string, mixed> $job The job.
	 * @param DateTimeImmutable    $now The current tick.
	 *
	 * @return bool
	 */
	private function isScheduledNow(array $job, DateTimeImmutable $now): bool {
		if (($job['enabled'] ?? false) !== true) {
			return false;
		}

		$cron = (string)($job['scheduleCron'] ?? '');
		return $this->isDueSinceLastTick(cron: $cron, now: $now);
	}//end isScheduledNow()

	/**
	 * Create the job's pending run; a failure is logged and does not stop the tick.
	 *
	 * @param array<string, mixed> $job The job.
	 *
	 * @return bool Whether a run was enqueued.
	 */
	private function enqueue(array $job): bool {
		try {
			$watermarkFrom = $this->resolveWatermarkFrom(job: $job);
			$this->runs->createPendingRun(job: $job, watermarkFrom: $watermarkFrom);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ExportSchedulerJob: failed to enqueue run',
				['jobId' => ($job['id'] ?? $job['uuid'] ?? ''), 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end enqueue()

	/**
	 * Whether the schedule fired in a minute since the previous tick.
	 *
	 * Cron does not always tick every minute, so matching only the current
	 * minute misses a schedule whose minute fell between two ticks. Each
	 * tick therefore covers the minutes after the previous tick up to now
	 * (at most CATCH_UP_WINDOW back), and enqueues one run however many of
	 * them matched. Without a previous tick only the current minute counts,
	 * so a new job does not fire for its whole history.
	 *
	 * @param string $cron The job's cron expression.
	 * @param DateTimeImmutable $now The current tick.
	 *
	 * @return bool
	 */
	private function isDueSinceLastTick(string $cron, DateTimeImmutable $now): bool {
		$previous = $this->getLastRun();
		if ($previous <= 0 || $previous >= $now->getTimestamp()) {
			return $this->cron->isDue(expression: $cron, when: $now);
		}

		$since = $now->setTimestamp(max($previous, ($now->getTimestamp() - self::CATCH_UP_WINDOW)));
		$next = $this->cron->nextRunAfter(expression: $cron, from: $since, horizonDays: 2);
		return $next !== null && $next <= $now;
	}//end isDueSinceLastTick()

	/**
	 * Resolve the incremental watermark start from the last succeeded run.
	 *
	 * @param array<string, mixed> $job The job.
	 *
	 * @return string|null The previous run's watermarkTo, or null.
	 */
	private function resolveWatermarkFrom(array $job): ?string {
		if ((string)($job['mode'] ?? 'full') !== 'incremental') {
			return null;
		}

		$jobId = (string)($job['id'] ?? $job['uuid'] ?? '');
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

		return (string)$watermarkTo;
	}//end resolveWatermarkFrom()
}//end class
