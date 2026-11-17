<?php

/**
 * Pipelinq ExportWorkerJob.
 *
 * Timed background worker that picks up pending export runs and executes each
 * through ExportExecutionService (per-job distributed lock, extract, upload,
 * audit). Runs frequently so a pending run is picked up within ~60 seconds.
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
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Kernel\Contracts\ExportExecutionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ExportRunServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background worker that executes pending export runs.
 *
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
 */
class ExportWorkerJob extends TimedJob {
	/**
	 * Poll interval in seconds (1 minute) so a pending run is picked up
	 * within ~60 seconds (REQ-BIE-004-02).
	 *
	 * @var int
	 */
	private const INTERVAL = 60;

	/**
	 * Max pending runs processed per tick (bounds a single worker invocation).
	 *
	 * @var int
	 */
	private const BATCH = 10;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory (required by TimedJob).
	 * @param ExportRunServiceInterface $runs The run service.
	 * @param ExportExecutionServiceInterface $execution The run executor.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private ExportRunServiceInterface $runs,
		private ExportExecutionServiceInterface $execution,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Execute the worker: drain up to BATCH pending runs.
	 *
	 * @param mixed $argument The job argument (unused; required by TimedJob).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-02
	 */
	protected function run(mixed $argument): void {
		$pending = $this->pendingRuns();
		if ($pending === null) {
			return;
		}

		$processed = $this->executeBatch(pending: $pending);

		if ($processed > 0) {
			$this->logger->info('ExportWorkerJob: processed pending export runs', ['count' => $processed]);
		}
	}//end run()

	/**
	 * The pending export runs; null (logged) when they cannot be listed.
	 *
	 * @return iterable<array<string, mixed>>|null The pending runs.
	 */
	private function pendingRuns(): iterable|null {
		try {
			return $this->runs->listRuns(filters: ['status' => 'pending']);
		} catch (\Throwable $e) {
			$this->logger->error('ExportWorkerJob: failed to list pending runs', ['error' => $e->getMessage()]);
			return null;
		}
	}//end pendingRuns()

	/**
	 * Execute up to one batch of pending runs; a failed run is logged and still counted.
	 *
	 * @param iterable<array<string, mixed>> $pending The pending runs.
	 *
	 * @return int The number of runs processed.
	 */
	private function executeBatch(iterable $pending): int {
		$processed = 0;
		foreach ($pending as $run) {
			if ($processed >= self::BATCH) {
				break;
			}

			$this->executeOne(run: $run);
			$processed++;
		}

		return $processed;
	}//end executeBatch()

	/**
	 * Execute one run, logging a failure.
	 *
	 * @param array<string, mixed> $run The export run.
	 *
	 * @return void
	 */
	private function executeOne(array $run): void {
		try {
			$this->execution->executeRun(run: $run);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ExportWorkerJob: run execution failed',
				['runId' => ($run['id'] ?? $run['uuid'] ?? ''), 'error' => $e->getMessage()]
			);
		}
	}//end executeOne()
}//end class
