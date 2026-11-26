<?php

/**
 * Unit tests: the export background jobs write their run records as the
 * system account when cron runs them with nobody signed in.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/report-periods-and-delivery/spec.md#requirement-a-scheduled-report-can-be-delivered-by-mail-req-rpd-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\ExportSchedulerJob;
use OCA\Pipelinq\BackgroundJob\ExportWorkerJob;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportExecutionService;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Cron runs the export jobs with nobody signed in; OpenRegister refuses an
 * anonymous create ("User 'Anonymous' does not have permission to 'create'
 * objects in schema 'Export Run'"), so no scheduled export ever ran.
 */
class ExportJobsActAsSystemAccountTest extends TestCase {

	/**
	 * The session's active user, as the jobs see it.
	 *
	 * @var IUser|null
	 */
	private ?IUser $active = null;

	/**
	 * The system account.
	 *
	 * @var IUser
	 */
	private IUser $system;

	/**
	 * With nobody signed in, the scheduler creates the pending run as the
	 * system account and leaves the session empty afterwards.
	 *
	 * @return void
	 */
	public function testTheSchedulerWritesThePendingRunAsTheSystemAccount(): void {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime('2026-11-25 18:20:00 UTC'));

		$jobs = $this->createMock(originalClassName: ExportJobService::class);
		$jobs->method('listJobs')->willReturn(
			[['id' => 'j-mail', 'enabled' => true, 'scheduleCron' => '* * * * *', 'mode' => 'full']]
		);

		$writers = [];
		$runs    = $this->createMock(originalClassName: ExportRunService::class);
		$runs->method('createPendingRun')->willReturnCallback(
			function () use (&$writers): array {
				$writers[] = $this->active;
				return [];
			}
		);

		$job = new ExportSchedulerJob(
			time: $time,
			jobs: $jobs,
			runs: $runs,
			cron: new CronExpressionHelper(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			systemAccount: $this->anonymousSession(),
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(expected: [$this->system], actual: $writers);
		$this->assertNull(actual: $this->active);
	}//end testTheSchedulerWritesThePendingRunAsTheSystemAccount()

	/**
	 * With nobody signed in, the worker lists and executes the pending runs as
	 * the system account and leaves the session empty afterwards.
	 *
	 * @return void
	 */
	public function testTheWorkerExecutesPendingRunsAsTheSystemAccount(): void {
		$listers = [];
		$runs    = $this->createMock(originalClassName: ExportRunService::class);
		$runs->method('listRuns')->willReturnCallback(
			function () use (&$listers): array {
				$listers[] = $this->active;
				return [['id' => 'r-1', 'status' => 'pending']];
			}
		);

		$executors = [];
		$execution = $this->createMock(originalClassName: ExportExecutionService::class);
		$execution->method('executeRun')->willReturnCallback(
			function () use (&$executors): array {
				$executors[] = $this->active;
				return [];
			}
		);

		$job = new ExportWorkerJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			runs: $runs,
			execution: $execution,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			systemAccount: $this->anonymousSession()
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(expected: [$this->system], actual: $listers);
		$this->assertSame(expected: [$this->system], actual: $executors);
		$this->assertNull(actual: $this->active);
	}//end testTheWorkerExecutesPendingRunsAsTheSystemAccount()

	/**
	 * A system account over a session with nobody signed in, whose active user
	 * this test tracks.
	 *
	 * @return SystemServiceAccount The account.
	 */
	private function anonymousSession(): SystemServiceAccount {
		$this->system = $this->createMock(originalClassName: IUser::class);
		$this->active = null;

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->active);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->active = $user;
			}
		);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturn($this->system);

		return new SystemServiceAccount(
			userManager: $users,
			userSession: $session,
			random: $this->createMock(originalClassName: ISecureRandom::class)
		);
	}//end anonymousSession()
}//end class
