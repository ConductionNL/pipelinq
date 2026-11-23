<?php

/**
 * Unit tests for the background jobs that run one service sweep and log it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\BudgetPeriodResetJob;
use OCA\Pipelinq\BackgroundJob\CostReconciliationJob;
use OCA\Pipelinq\BackgroundJob\CtiEventLogCleanupJob;
use OCA\Pipelinq\BackgroundJob\PortalCleanupJob;
use OCA\Pipelinq\BackgroundJob\ScheduledTaskJob;
use OCA\Pipelinq\BackgroundJob\SearchConsoleImportJob;
use OCA\Pipelinq\BackgroundJob\SocialMetricsPullJob;
use OCA\Pipelinq\BackgroundJob\SocialPublishJob;
use OCA\Pipelinq\BackgroundJob\TemplateApprovalSyncJob;
use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\CostReconciliationService;
use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Service\Portal\PortalCleanupService;
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCA\Pipelinq\Service\SearchConsole\SearchConsoleImportService;
use OCA\Pipelinq\Service\SocialMetricsService;
use OCA\Pipelinq\Service\SocialPostService;
use OCA\Pipelinq\Service\TemplateApprovalSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins each job's interval, the service call it makes, what it logs on success
 * and that a failure is logged instead of escaping the cron run.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\BudgetPeriodResetJob
 * @covers \OCA\Pipelinq\BackgroundJob\CostReconciliationJob
 * @covers \OCA\Pipelinq\BackgroundJob\CtiEventLogCleanupJob
 * @covers \OCA\Pipelinq\BackgroundJob\PortalCleanupJob
 * @covers \OCA\Pipelinq\BackgroundJob\ScheduledTaskJob
 * @covers \OCA\Pipelinq\BackgroundJob\SearchConsoleImportJob
 * @covers \OCA\Pipelinq\BackgroundJob\SocialMetricsPullJob
 * @covers \OCA\Pipelinq\BackgroundJob\SocialPublishJob
 * @covers \OCA\Pipelinq\BackgroundJob\TemplateApprovalSyncJob
 */
class ServiceSweepJobsTest extends TestCase {

	/**
	 * Every log line, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * A logger that records every line.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$this->logs = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * Run a job's protected run().
	 *
	 * @param TimedJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(TimedJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The daily budget reset logs how many rows it reset.
	 *
	 * @return void
	 */
	public function testBudgetResetLogsTheRowsReset(): void {
		$service = $this->createMock(BudgetService::class);
		$service->expects($this->once())->method('resetPeriods')->willReturn(4);
		$job = new BudgetPeriodResetJob($this->createMock(ITimeFactory::class), $service, $this->logger());

		$this->runJob($job);

		$this->assertSame(86400, $job->getInterval());
		$this->assertSame([['info', 'BudgetPeriodResetJob complete', ['rowsReset' => 4]]], $this->logs);
	}//end testBudgetResetLogsTheRowsReset()

	/**
	 * A failing budget reset is logged with its message.
	 *
	 * @return void
	 */
	public function testBudgetResetFailureIsLogged(): void {
		$service = $this->createMock(BudgetService::class);
		$service->method('resetPeriods')->willThrowException(new RuntimeException('db gone'));
		$this->runJob(new BudgetPeriodResetJob($this->createMock(ITimeFactory::class), $service, $this->logger()));

		$this->assertSame([['error', 'BudgetPeriodResetJob failed', ['exception' => 'db gone']]], $this->logs);
	}//end testBudgetResetFailureIsLogged()

	/**
	 * The cost reconciliation logs the service's summary as its context.
	 *
	 * @return void
	 */
	public function testCostReconciliationLogsItsSummary(): void {
		$service = $this->createMock(CostReconciliationService::class);
		$service->expects($this->once())->method('reconcile')->willReturn(['matched' => 3, 'unmatched' => 1]);
		$job = new CostReconciliationJob($this->createMock(ITimeFactory::class), $service, $this->logger());

		$this->runJob($job);

		$this->assertSame(86400, $job->getInterval());
		$this->assertSame([['info', 'CostReconciliationJob complete', ['matched' => 3, 'unmatched' => 1]]], $this->logs);

		$service = $this->createMock(CostReconciliationService::class);
		$service->method('reconcile')->willThrowException(new RuntimeException('no rates'));
		$this->runJob(new CostReconciliationJob($this->createMock(ITimeFactory::class), $service, $this->logger()));
		$this->assertSame([['error', 'CostReconciliationJob failed', ['exception' => 'no rates']]], $this->logs);
	}//end testCostReconciliationLogsItsSummary()

	/**
	 * The CTI cleanup logs the number of purged events, and a failure.
	 *
	 * @return void
	 */
	public function testCtiCleanupLogsTheDeletedCount(): void {
		$service = $this->createMock(CtiService::class);
		$service->expects($this->once())->method('purgeOldEventLog')->willReturn(12);
		$job = new CtiEventLogCleanupJob($this->createMock(ITimeFactory::class), $service, $this->logger());

		$this->runJob($job);

		$this->assertSame(86400, $job->getInterval());
		$this->assertSame([['info', 'CTI event log cleanup completed', ['deleted' => 12]]], $this->logs);

		$service = $this->createMock(CtiService::class);
		$service->method('purgeOldEventLog')->willThrowException(new RuntimeException('locked'));
		$this->runJob(new CtiEventLogCleanupJob($this->createMock(ITimeFactory::class), $service, $this->logger()));
		$this->assertSame([['error', 'CTI event log cleanup failed', ['exception' => 'locked']]], $this->logs);
	}//end testCtiCleanupLogsTheDeletedCount()

	/**
	 * The portal cleanup logs only when it pseudonymised someone.
	 *
	 * @return void
	 */
	public function testPortalCleanupLogsOnlyAPositiveCount(): void {
		$service = $this->createMock(PortalCleanupService::class);
		$service->method('run')->willReturnOnConsecutiveCalls(0, 2);
		$job = new PortalCleanupJob($this->createMock(ITimeFactory::class), $service, $this->logger());

		$this->runJob($job);
		$this->assertSame([], $this->logs);

		$this->runJob($job);
		$this->assertSame(86400, $job->getInterval());
		$this->assertSame([['info', 'Pipelinq portal: pseudonymised 2 closed-account contacts', []]], $this->logs);
	}//end testPortalCleanupLogsOnlyAPositiveCount()

	/**
	 * A failing portal cleanup is logged.
	 *
	 * @return void
	 */
	public function testPortalCleanupFailureIsLogged(): void {
		$service = $this->createMock(PortalCleanupService::class);
		$service->method('run')->willThrowException(new RuntimeException('no mapper'));
		$this->runJob(new PortalCleanupJob($this->createMock(ITimeFactory::class), $service, $this->logger()));

		$this->assertSame([['error', 'Pipelinq portal: cleanup job failed', ['exception' => 'no mapper']]], $this->logs);
	}//end testPortalCleanupFailureIsLogged()

	/**
	 * The scheduled task job runs every five minutes, time-sensitive, and logs
	 * the exception object itself on failure.
	 *
	 * @return void
	 */
	public function testScheduledTasksRunAndAFailureKeepsTheException(): void {
		$service = $this->createMock(ScheduledTaskService::class);
		$service->expects($this->once())->method('processScheduledTasks');
		$job = new ScheduledTaskJob($this->createMock(ITimeFactory::class), $service, $this->logger());

		$this->runJob($job);

		$this->assertSame(300, $job->getInterval());
		$this->assertTrue($job->isTimeSensitive());
		$this->assertSame([], $this->logs);

		$failure = new RuntimeException('boom');
		$service = $this->createMock(ScheduledTaskService::class);
		$service->method('processScheduledTasks')->willThrowException($failure);
		$this->runJob(new ScheduledTaskJob($this->createMock(ITimeFactory::class), $service, $this->logger()));
		$this->assertSame([['error', 'ScheduledTaskJob failed', ['exception' => $failure]]], $this->logs);
	}//end testScheduledTasksRunAndAFailureKeepsTheException()

	/**
	 * Search Console imports nothing without a key or without properties.
	 *
	 * @return void
	 */
	public function testSearchConsoleSkipsWhenNotConfigured(): void {
		foreach ([[false, ['sc-domain:x']], [true, []]] as [$hasKey, $properties]) {
			$service = $this->createMock(SearchConsoleImportService::class);
			$service->method('hasKey')->willReturn($hasKey);
			$service->method('properties')->willReturn($properties);
			$service->expects($this->never())->method('importRecent');
			$job = new SearchConsoleImportJob($this->createMock(ITimeFactory::class), $service, $this->logger());

			$this->runJob($job);

			$this->assertFalse($job->isTimeSensitive());
			$this->assertSame([['debug', 'SearchConsoleImportJob: not configured, skipping', []]], $this->logs);
		}
	}//end testSearchConsoleSkipsWhenNotConfigured()

	/**
	 * A configured Search Console imports the last three days and logs the
	 * result, or the failure.
	 *
	 * @return void
	 */
	public function testSearchConsoleImportsTheRecentDays(): void {
		$service = $this->createMock(SearchConsoleImportService::class);
		$service->method('hasKey')->willReturn(true);
		$service->method('properties')->willReturn(['sc-domain:x']);
		$service->expects($this->once())->method('importRecent')->with(3)->willReturn(['rows' => 9]);
		$this->runJob(new SearchConsoleImportJob($this->createMock(ITimeFactory::class), $service, $this->logger()));
		$this->assertSame([['info', 'SearchConsoleImportJob: run finished', ['rows' => 9]]], $this->logs);

		$service = $this->createMock(SearchConsoleImportService::class);
		$service->method('hasKey')->willReturn(true);
		$service->method('properties')->willReturn(['sc-domain:x']);
		$service->method('importRecent')->willThrowException(new RuntimeException('quota'));
		$job = new SearchConsoleImportJob($this->createMock(ITimeFactory::class), $service, $this->logger());
		$this->runJob($job);
		$this->assertSame(86400, $job->getInterval());
		$this->assertSame([['error', 'SearchConsoleImportJob: run failed', ['exception' => 'quota']]], $this->logs);
	}//end testSearchConsoleImportsTheRecentDays()

	/**
	 * The social metrics pull logs the pull summary, or the failure.
	 *
	 * @return void
	 */
	public function testSocialMetricsLogsThePull(): void {
		$service = $this->createMock(SocialMetricsService::class);
		$service->expects($this->once())->method('pullAll')->willReturn(['posts' => 5]);
		$job = new SocialMetricsPullJob($this->createMock(ITimeFactory::class), $service, $this->logger());
		$this->runJob($job);
		$this->assertSame(86400, $job->getInterval());
		$this->assertSame([['info', 'SocialMetricsPullJob complete', ['posts' => 5]]], $this->logs);

		$service = $this->createMock(SocialMetricsService::class);
		$service->method('pullAll')->willThrowException(new RuntimeException('rate limited'));
		$this->runJob(new SocialMetricsPullJob($this->createMock(ITimeFactory::class), $service, $this->logger()));
		$this->assertSame([['error', 'SocialMetricsPullJob failed', ['exception' => 'rate limited']]], $this->logs);
	}//end testSocialMetricsLogsThePull()

	/**
	 * The social publish job logs only when it attempted posts, and a failure.
	 *
	 * @return void
	 */
	public function testSocialPublishLogsOnlyAttempts(): void {
		$service = $this->createMock(SocialPostService::class);
		$service->method('publishDuePosts')->willReturnOnConsecutiveCalls(0, 3);
		$job = new SocialPublishJob($this->createMock(ITimeFactory::class), $service, $this->logger());
		$this->runJob($job);
		$this->assertSame([], $this->logs);
		$this->runJob($job);
		$this->assertSame(300, $job->getInterval());
		$this->assertSame([['info', 'SocialPublishJob published due posts', ['posts' => 3]]], $this->logs);

		$service = $this->createMock(SocialPostService::class);
		$service->method('publishDuePosts')->willThrowException(new RuntimeException('token expired'));
		$this->runJob(new SocialPublishJob($this->createMock(ITimeFactory::class), $service, $this->logger()));
		$this->assertSame([['error', 'SocialPublishJob failed', ['exception' => 'token expired']]], $this->logs);
	}//end testSocialPublishLogsOnlyAttempts()

	/**
	 * The template approval sync runs hourly and logs its summary, or the failure.
	 *
	 * @return void
	 */
	public function testTemplateApprovalSyncLogsItsSummary(): void {
		$service = $this->createMock(TemplateApprovalSyncService::class);
		$service->expects($this->once())->method('syncAll')->willReturn(['approved' => 1]);
		$job = new TemplateApprovalSyncJob($this->createMock(ITimeFactory::class), $service, $this->logger());
		$this->runJob($job);
		$this->assertSame(3600, $job->getInterval());
		$this->assertSame([['info', 'TemplateApprovalSyncJob complete', ['approved' => 1]]], $this->logs);

		$service = $this->createMock(TemplateApprovalSyncService::class);
		$service->method('syncAll')->willThrowException(new RuntimeException('provider down'));
		$this->runJob(new TemplateApprovalSyncJob($this->createMock(ITimeFactory::class), $service, $this->logger()));
		$this->assertSame([['error', 'TemplateApprovalSyncJob failed', ['exception' => 'provider down']]], $this->logs);
	}//end testTemplateApprovalSyncLogsItsSummary()
}//end class
