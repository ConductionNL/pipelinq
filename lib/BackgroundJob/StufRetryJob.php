<?php

/**
 * Pipelinq StufRetryJob.
 *
 * On-demand background job that retries an outbound StUF kennisgeving whose
 * previous attempt produced a transient failure. Scheduled by
 * StufAdapterService at exponential-backoff intervals (5s, 30s, 2m, 10m).
 * Each invocation reuses the SAME referentienummer for idempotency.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Stuf\StufAdapterService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\Job;
use Psr\Log\LoggerInterface;

/**
 * On-demand background job that retries a single StufMessage.
 */
class StufRetryJob extends Job
{
    /**
     * Constructor.
     *
     * @param ITimeFactory       $time    The time factory.
     * @param StufAdapterService $adapter The adapter service.
     * @param LoggerInterface    $logger  The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private StufAdapterService $adapter,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Execute the retry.
     *
     * @param mixed $argument The job payload: {stufMessageId: string, runAt?: int}.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
     */
    protected function run(mixed $argument): void
    {
        if (is_array(value: $argument) === true) {
            $payload = $argument;
        } else {
            $payload = [];
        }

        $stufMessageId = (string) ($payload['stufMessageId'] ?? '');
        $runAt         = (int) ($payload['runAt'] ?? 0);

        if ($stufMessageId === '') {
            $this->logger->warning(message: 'StufRetryJob: missing stufMessageId in payload');
            return;
        }

        if ($runAt > 0 && time() < $runAt) {
            // Re-defer: the cron picked us up early.
            return;
        }

        try {
            $this->adapter->retrySend(stufMessageId: $stufMessageId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: 'StufRetryJob failed for {id}: {error}',
                context: ['id' => $stufMessageId, 'error' => $e->getMessage()]
            );
        }
    }//end run()
}//end class
