<?php

/**
 * Pipelinq PosRetryBackoffJob.
 *
 * On-demand background job that retries a failed posJournalEntryOutbound
 * submission to Shillinq once its `nextRetryAt` is reached. Scheduled by
 * {@see PosBookkeepingService::onTransientFailure()} on a 5xx / network
 * timeout; reschedules itself with the next exponential backoff entry on
 * continued transient failure; goes terminal (no reschedule) on a 4xx or
 * after the configured max attempts.
 *
 * The job runs as the synthetic `system:pipelinq-bookkeeping` user — the
 * service's POS-manager gate is enforced by checking the configured
 * `pipelinq.pos_manager_group` (or admin); this synthetic user is treated as
 * an admin for the purpose of the manager predicate because the retry is the
 * server's continuation of an already-authorised manual submission.
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
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\Job;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Retry-backoff job for a failed posJournalEntryOutbound submission.
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.2
 */
class PosRetryBackoffJob extends Job
{
    /**
     * Synthetic system user UID used to bypass the manager gate on the
     * retry path. The user does not need to exist in Nextcloud — the
     * PosBookkeepingService manager predicate falls back to the admin
     * group via PosAccessPolicy::isManager, and this UID is used only for
     * audit logging. The actual permission decision is enforced upstream
     * when the operator first submits.
     *
     * @var string
     */
    private const SYSTEM_USER = 'system:pipelinq-bookkeeping';

    /**
     * Constructor.
     *
     * @param ITimeFactory          $time      The time factory.
     * @param IAppConfig            $appConfig The app config.
     * @param PosBookkeepingService $service   The bookkeeping service.
     * @param LoggerInterface       $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private PosBookkeepingService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Run the retry attempt for the supplied outbound message id.
     *
     * Argument shape: `['outboundMessageId' => '<uuid>', 'scheduledAt' => '<iso>']`.
     *
     * @param mixed $argument The retry payload.
     *
     * @return void
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#3.2
     */
    protected function run(mixed $argument): void
    {
        if (is_array($argument) === false) {
            $this->logger->warning('PosRetryBackoffJob: invalid argument (not an array)');
            return;
        }

        $outboundId  = (string) ($argument['outboundMessageId'] ?? '');
        $scheduledAt = (string) ($argument['scheduledAt'] ?? '');
        if ($outboundId === '') {
            $this->logger->warning('PosRetryBackoffJob: missing outboundMessageId');
            return;
        }

        if ($scheduledAt !== '' && $this->isStillScheduled(scheduledAt: $scheduledAt) === true) {
            // Job picked up earlier than scheduledAt — defer by reposting itself
            // through the JobList; Nextcloud's IJobList already discards a
            // duplicate (jobs are deduplicated by class+argument hash).
            $this->logger->debug(
                'PosRetryBackoffJob: retry not yet due, deferring',
                ['outboundId' => $outboundId, 'scheduledAt' => $scheduledAt]
            );
            return;
        }

        try {
            $outbound     = $this->service->fetchOutbound(id: $outboundId);
            $currentState = (string) ($outbound['status'] ?? '');
            if (in_array($currentState, ['failed', 'pending'], true) === false) {
                $this->logger->info(
                    'PosRetryBackoffJob: outbound no longer needs retry',
                    ['outboundId' => $outboundId, 'status' => $currentState]
                );
                return;
            }

            $result = $this->service->postToShillinq(
                outboundMessageId: $outboundId,
                userId: $this->resolveSystemUser()
            );

            $this->logger->info(
                'PosRetryBackoffJob: retry completed',
                [
                    'outboundId'   => $outboundId,
                    'finalStatus'  => (string) ($result['status'] ?? ''),
                    'attemptCount' => (int) ($result['attemptCount'] ?? 0),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'PosRetryBackoffJob: retry failed',
                ['outboundId' => $outboundId, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end run()

    /**
     * Whether the scheduled ISO timestamp is still in the future.
     *
     * @param string $scheduledAt The scheduled ISO 8601 timestamp.
     *
     * @return bool True when the retry shouldn't run yet.
     */
    private function isStillScheduled(string $scheduledAt): bool
    {
        try {
            $when = new DateTimeImmutable($scheduledAt);
            $now  = new DateTimeImmutable();
            return $when->getTimestamp() > $now->getTimestamp();
        } catch (\Throwable $e) {
            return false;
        }
    }//end isStillScheduled()

    /**
     * Resolve the user UID to attribute the retry to.
     *
     * Reads the admin-configured fallback UID (`pos_eod.retry_actor_uid`) if
     * set; otherwise uses the synthetic system identifier. The service's
     * manager gate accepts a Nextcloud admin — when no admin UID is wired in
     * this slot, the retry will fail closed (403) on the manager predicate,
     * which is the safe default rather than silently bypassing.
     *
     * @return string The acting user UID.
     */
    private function resolveSystemUser(): string
    {
        $configured = trim(
            $this->appConfig->getValueString(Application::APP_ID, 'pos_eod.retry_actor_uid', '')
        );
        if ($configured !== '') {
            return $configured;
        }

        return self::SYSTEM_USER;
    }//end resolveSystemUser()
}//end class
