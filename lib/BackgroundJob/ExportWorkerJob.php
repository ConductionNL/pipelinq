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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Export\ExportExecutionService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background worker that executes pending export runs.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
 */
class ExportWorkerJob extends TimedJob
{
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
     * @param ITimeFactory           $time      The time factory (required by TimedJob).
     * @param ExportRunService       $runs      The run service.
     * @param ExportExecutionService $execution The run executor.
     * @param LoggerInterface        $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private ExportRunService $runs,
        private ExportExecutionService $execution,
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
     * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-02
     */
    protected function run(mixed $argument): void
    {
        try {
            $pending = $this->runs->listRuns(filters: ['status' => 'pending']);
        } catch (\Throwable $e) {
            $this->logger->error('ExportWorkerJob: failed to list pending runs', ['error' => $e->getMessage()]);
            return;
        }

        $processed = 0;
        foreach ($pending as $run) {
            if ($processed >= self::BATCH) {
                break;
            }

            try {
                $this->execution->executeRun(run: $run);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'ExportWorkerJob: run execution failed',
                    ['runId' => ($run['id'] ?? $run['uuid'] ?? ''), 'error' => $e->getMessage()]
                );
            }

            $processed++;
        }//end foreach

        if ($processed > 0) {
            $this->logger->info('ExportWorkerJob: processed pending export runs', ['count' => $processed]);
        }
    }//end run()
}//end class
