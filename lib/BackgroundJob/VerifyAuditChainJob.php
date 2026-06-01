<?php

/**
 * Pipelinq VerifyAuditChainJob.
 *
 * Background job that iterates unverified Kassakoppeling audit log entries
 * (verified === null) and runs cryptographic signature + hash-chain
 * verification on each, updating the `verified` flag.
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
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\KassakoppelingAuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Timed job to verify Kassakoppeling audit log hash-chain integrity.
 *
 * Runs hourly. Fetches all unverified entries (verified === null) and calls
 * KassakoppelingAuditService::verifyEntry() for each, setting `verified`
 * to true or false. Results are logged for operational monitoring.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#4.1
 */
class VerifyAuditChainJob extends TimedJob
{
    /**
     * Run every hour (3600 seconds).
     */
    private const INTERVAL_SECONDS = 3600;

    /**
     * Maximum number of entries to verify per run to avoid timeouts.
     */
    private const MAX_PER_RUN = 100;

    /**
     * Constructor.
     *
     * @param ITimeFactory               $time         The time factory.
     * @param KassakoppelingAuditService $auditService The audit service.
     * @param LoggerInterface            $logger       The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly KassakoppelingAuditService $auditService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL_SECONDS);
    }//end __construct()

    /**
     * Execute the verification job.
     *
     * @param mixed $argument Unused job argument.
     *
     * @return void
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#4.1
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('VerifyAuditChainJob: starting verification run');

        try {
            // Fetch entries with verified === null (unverified).
            $allEntries = $this->auditService->listEntries([]);
            $pending    = array_filter($allEntries, static fn($e): bool => ($e['verified'] ?? 'x') === null);

            // Limit to MAX_PER_RUN to avoid PHP execution timeout on large backlogs.
            $batch = array_slice(array_values($pending), 0, self::MAX_PER_RUN);

            $verified = 0;
            $failed   = 0;

            foreach ($batch as $entry) {
                $id = (string) ($entry['id'] ?? $entry['uuid'] ?? '');
                if ($id === '') {
                    continue;
                }

                try {
                    $result = $this->auditService->verifyEntry($id);
                    if ($result === true) {
                        $verified++;
                    } else {
                        $failed++;
                        $this->logger->warning(
                            'VerifyAuditChainJob: tampered entry detected',
                            ['id' => $id, 'registerNumber' => $entry['registerNumber'] ?? '']
                        );
                    }
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'VerifyAuditChainJob: failed to verify entry',
                        ['id' => $id, 'exception' => $e->getMessage()]
                    );
                }
            }//end foreach

            $this->logger->info(
                'VerifyAuditChainJob: verification run complete',
                ['verified' => $verified, 'tampered' => $failed, 'processed' => count($batch)]
            );
        } catch (\Throwable $e) {
            $this->logger->error('VerifyAuditChainJob: run failed', ['exception' => $e->getMessage()]);
        }//end try
    }//end run()
}//end class
