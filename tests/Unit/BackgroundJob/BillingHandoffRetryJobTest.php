<?php

/**
 * Unit tests for BillingHandoffRetryJob.
 *
 * Per the orchestrator's binding ruling for time-billing-handoff-emit, the
 * job never re-attempts the shillinq intake call itself — it only re-notifies
 * administrators for still-failed batches, via
 * {@see \OCA\Pipelinq\Service\TimeBillingHandoffService::notifyPendingFailures()}.
 * These tests exercise the job's polling shape against a mocked service,
 * mirroring {@see \OCA\Pipelinq\Tests\Unit\Service\PosBookkeepingServiceTest}'s
 * best-effort-never-throws convention.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/time-billing-handoff-emit/specs/time-approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\BillingHandoffRetryJob;
use OCA\Pipelinq\Service\TimeBillingHandoffService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BillingHandoffRetryJob.
 */
class BillingHandoffRetryJobTest extends TestCase
{

    /**
     * The job calls the service's notifyPendingFailures() exactly once per
     * run, and never any dispatch/send method — it re-notifies only.
     *
     * @return void
     */
    public function testRunCallsNotifyPendingFailuresOnce(): void
    {
        $service = $this->createMock(TimeBillingHandoffService::class);
        $service->expects($this->once())
            ->method('notifyPendingFailures')
            ->willReturn(['batch-a', 'batch-b']);
        $service->expects($this->never())->method('sendToBilling');

        $job = new BillingHandoffRetryJob(
            $this->createMock(ITimeFactory::class),
            $service,
            $this->createMock(LoggerInterface::class),
        );

        $this->invokeRun($job);
    }//end testRunCallsNotifyPendingFailuresOnce()

    /**
     * When there is nothing to notify, the job still completes without error.
     *
     * @return void
     */
    public function testRunWithNoFailuresCompletesCleanly(): void
    {
        $service = $this->createMock(TimeBillingHandoffService::class);
        $service->method('notifyPendingFailures')->willReturn([]);

        $job = new BillingHandoffRetryJob(
            $this->createMock(ITimeFactory::class),
            $service,
            $this->createMock(LoggerInterface::class),
        );

        $this->invokeRun($job);
        $this->addToAssertionCount(1);
    }//end testRunWithNoFailuresCompletesCleanly()

    /**
     * The job is best-effort: a notification-delivery outage (the service
     * throwing) never propagates out of run() so a cron sweep is never
     * failed by it.
     *
     * @return void
     */
    public function testRunNeverThrowsWhenNotificationFails(): void
    {
        $service = $this->createMock(TimeBillingHandoffService::class);
        $service->method('notifyPendingFailures')->willThrowException(new \RuntimeException('mailer down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $job = new BillingHandoffRetryJob(
            $this->createMock(ITimeFactory::class),
            $service,
            $logger,
        );

        $this->invokeRun($job);
        $this->addToAssertionCount(1);
    }//end testRunNeverThrowsWhenNotificationFails()

    /**
     * Invoke the protected TimedJob::run() via reflection (same pattern used
     * across the codebase's other TimedJob tests).
     *
     * @param BillingHandoffRetryJob $job The job instance.
     *
     * @return void
     */
    private function invokeRun(BillingHandoffRetryJob $job): void
    {
        $method = new \ReflectionMethod(objectOrMethod: $job, method: 'run');
        $method->invoke($job, null);
    }//end invokeRun()
}//end class
