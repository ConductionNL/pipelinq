<?php

/**
 * Pipelinq BillingHandoffRetryJob.
 *
 * Periodic background job for the shillinq time-intake billing handoff
 * (time-billing-handoff-emit). Per the orchestrator's binding ruling for
 * this slice, the job NEVER re-attempts the intake call itself — it runs
 * without a Nextcloud session, and shillinq's time-intake resolves its
 * tenant (administration) from the acting user's session server-side, so a
 * sessionless re-send has no reliable seam. Instead it re-notifies
 * administrators for every `billingSyncStatus = failed` batch still
 * outstanding, so the failure is never silently forgotten; the guaranteed
 * re-send is the manual "Send to billing" action, re-triggered in session
 * context, which recomputes the identical deterministic batchId for the
 * still-unbilled entries (shillinq's idempotency then either completes the
 * batch or returns the same actionable error).
 *
 * Mirrors {@see \OCA\Pipelinq\BackgroundJob\PosRetryBackoffJob}'s polling
 * shape (TimedJob, 15-minute interval, best-effort, never throws — a
 * notification outage must never fail the cron run).
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/time-approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\TimeBillingHandoffService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Periodic re-notification job for failed billing-handoff batches.
 *
 * @spec openspec/specs/time-approval-workflow/spec.md
 */
class BillingHandoffRetryJob extends TimedJob
{
    /**
     * Polling interval in seconds (15 minutes) — matches PosRetryBackoffJob.
     *
     * @var int
     */
    private const INTERVAL = 900;

    /**
     * Constructor.
     *
     * @param ITimeFactory              $time           The time factory.
     * @param TimeBillingHandoffService $handoffService The billing handoff service.
     * @param LoggerInterface           $logger         The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private TimeBillingHandoffService $handoffService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Re-notify administrators for every batch still `failed`.
     *
     * Best-effort: never throws, so a notification-delivery outage never
     * fails the cron run.
     *
     * @param mixed $argument Optional payload (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
     *
     * @spec openspec/specs/time-approval-workflow/spec.md
     */
    protected function run(mixed $argument): void
    {
        try {
            $batchIds = $this->handoffService->notifyPendingFailures();
        } catch (Throwable $e) {
            $this->logger->warning(
                'BillingHandoffRetryJob: failed to re-notify pending billing-handoff failures',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        if (empty($batchIds) === false) {
            $this->logger->info(
                'BillingHandoffRetryJob: re-notified administrators for failed billing-handoff batches',
                ['batchIds' => $batchIds]
            );
        }
    }//end run()
}//end class
