<?php

/**
 * Integration tests for ExportWorkerJob.
 *
 * Covers REQ-BIE-004 pickup/lock/status semantics through the public
 * surface area of the job (ExportRunService + ExportExecutionService),
 * with the heavy collaborators mocked so the test can run in CI without
 * a running OpenRegister + OpenConnector. The integration character is in
 * exercising the orchestration of the real ExportWorkerJob::run() method
 * end-to-end — listing pending runs, dispatching each through
 * ExportExecutionService, and continuing on failure — rather than in
 * spinning up a live warehouse.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Integration\Job
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Integration\Job;

use OCA\Pipelinq\BackgroundJob\ExportWorkerJob;
use OCA\Pipelinq\Service\Export\ExportExecutionService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests the ExportWorkerJob orchestration end-to-end.
 */
class ExportWorkerJobTest extends TestCase {

	/**
	 * The time factory mock.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory $timeFactory;

	/**
	 * The export run service mock.
	 *
	 * @var ExportRunService&MockObject
	 */
	private ExportRunService $runs;

	/**
	 * The export execution service mock.
	 *
	 * @var ExportExecutionService&MockObject
	 */
	private ExportExecutionService $execution;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->timeFactory = $this->createMock(originalClassName: ITimeFactory::class);
		$this->runs = $this->createMock(originalClassName: ExportRunService::class);
		$this->execution = $this->createMock(originalClassName: ExportExecutionService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->timeFactory->method('getTime')->willReturn(time());

	}//end setUp()

	/**
	 * Build the job under test.
	 *
	 * @return ExportWorkerJob
	 */
	private function buildJob(): ExportWorkerJob {
		return new ExportWorkerJob(
			time: $this->timeFactory,
			runs: $this->runs,
			execution: $this->execution,
			logger: $this->logger,
		);
	}//end buildJob()

	/**
	 * Invoke the protected run() method.
	 *
	 * @param ExportWorkerJob $job The job instance.
	 *
	 * @return void
	 */
	private function invokeRun(ExportWorkerJob $job): void {
		$ref = new ReflectionMethod(objectOrMethod: $job, method: 'run');
		$ref->setAccessible(accessible: true);
		$ref->invoke($job, null);
	}//end invokeRun()

	/**
	 * Test that the job can be instantiated.
	 *
	 * @return void
	 */
	public function testJobCanBeInstantiated(): void {
		$this->assertInstanceOf(expected: ExportWorkerJob::class, actual: $this->buildJob());

	}//end testJobCanBeInstantiated()

	/**
	 * Test that pending runs are picked up and each is dispatched through
	 * ExportExecutionService — REQ-BIE-004 pickup semantics.
	 *
	 * @return void
	 */
	public function testPendingRunsArePickedUpAndExecuted(): void {
		$pending = [
			['id' => 'run-1', 'jobId' => 'job-1', 'status' => 'pending'],
			['id' => 'run-2', 'jobId' => 'job-2', 'status' => 'pending'],
		];

		$this->runs
			->expects($this->once())
			->method('listRuns')
			->with(['status' => 'pending'])
			->willReturn($pending);

		$this->execution
			->expects($this->exactly(2))
			->method('executeRun')
			->willReturnOnConsecutiveCalls(
				['id' => 'run-1', 'status' => 'succeeded'],
				['id' => 'run-2', 'status' => 'succeeded'],
			);

		$this->logger
			->expects($this->atLeastOnce())
			->method('info');

		$this->invokeRun(job: $this->buildJob());

	}//end testPendingRunsArePickedUpAndExecuted()

	/**
	 * Test that the distributed lock contract is respected — when
	 * ExportExecutionService reports skipped_overlap (its public marker for
	 * the per-job lock being held by another run), the worker continues to
	 * the next pending run rather than retrying or aborting.
	 *
	 * The lock itself is enforced inside ExportExecutionService::executeRun()
	 * (covered separately); here we assert the worker honours the contract.
	 *
	 * @return void
	 */
	public function testWorkerHonoursDistributedLockSkipMarker(): void {
		$pending = [
			['id' => 'run-1', 'jobId' => 'job-1', 'status' => 'pending'],
			['id' => 'run-2', 'jobId' => 'job-1', 'status' => 'pending'],
		];

		$this->runs
			->expects($this->once())
			->method('listRuns')
			->with(['status' => 'pending'])
			->willReturn($pending);

		// First run executes; second run for the same job reports
		// skipped_overlap (lock held by the first invocation).
		$this->execution
			->expects($this->exactly(2))
			->method('executeRun')
			->willReturnOnConsecutiveCalls(
				['id' => 'run-1', 'status' => 'succeeded'],
				['id' => 'run-2', 'status' => 'skipped_overlap'],
			);

		$this->invokeRun(job: $this->buildJob());

	}//end testWorkerHonoursDistributedLockSkipMarker()

	/**
	 * Test the status transition pipeline (pending → running → succeeded)
	 * — the worker delegates these transitions to ExportExecutionService,
	 * which the worker MUST invoke exactly once per pending run.
	 *
	 * @return void
	 */
	public function testStatusTransitionsAreDrivenByExecutionService(): void {
		$run = ['id' => 'run-1', 'jobId' => 'job-1', 'status' => 'pending'];

		$this->runs
			->expects($this->once())
			->method('listRuns')
			->willReturn([$run]);

		// ExportExecutionService::executeRun() owns the
		// pending → running → succeeded transition (markRunning + completeRun);
		// the worker must invoke it once per pending run.
		$this->execution
			->expects($this->once())
			->method('executeRun')
			->with($run)
			->willReturn(['id' => 'run-1', 'status' => 'succeeded']);

		$this->invokeRun(job: $this->buildJob());

	}//end testStatusTransitionsAreDrivenByExecutionService()

	/**
	 * Test that an exception thrown by ExportExecutionService::executeRun()
	 * is logged and the worker continues with subsequent pending runs.
	 *
	 * @return void
	 */
	public function testExceptionDuringExecuteRunIsLoggedAndContinues(): void {
		$pending = [
			['id' => 'run-1', 'jobId' => 'job-1'],
			['id' => 'run-2', 'jobId' => 'job-2'],
		];

		$this->runs
			->expects($this->once())
			->method('listRuns')
			->willReturn($pending);

		$this->execution
			->expects($this->exactly(2))
			->method('executeRun')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new RuntimeException('boom')),
				['id' => 'run-2', 'status' => 'succeeded'],
			);

		$this->logger
			->expects($this->atLeastOnce())
			->method('error');

		$this->invokeRun(job: $this->buildJob());

	}//end testExceptionDuringExecuteRunIsLoggedAndContinues()

	/**
	 * Test that a failure in listRuns() is logged and the worker exits
	 * gracefully (no executeRun calls, no crash).
	 *
	 * @return void
	 */
	public function testListRunsFailureIsHandled(): void {
		$this->runs
			->expects($this->once())
			->method('listRuns')
			->willThrowException(new RuntimeException('store error'));

		$this->execution
			->expects($this->never())
			->method('executeRun');

		$this->logger
			->expects($this->atLeastOnce())
			->method('error');

		$this->invokeRun(job: $this->buildJob());

	}//end testListRunsFailureIsHandled()

	/**
	 * Test that the worker bounds itself to its BATCH size per tick.
	 * The class constant is 10; we feed 15 pending runs and assert only 10
	 * dispatches occur.
	 *
	 * @return void
	 */
	public function testWorkerHonoursBatchCapPerTick(): void {
		$pending = [];
		for ($i = 0; $i < 15; $i++) {
			$pending[] = ['id' => 'run-' . $i, 'jobId' => 'job-' . $i];
		}

		$this->runs
			->expects($this->once())
			->method('listRuns')
			->willReturn($pending);

		$this->execution
			->expects($this->exactly(10))
			->method('executeRun')
			->willReturn(['status' => 'succeeded']);

		$this->invokeRun(job: $this->buildJob());

	}//end testWorkerHonoursBatchCapPerTick()
}//end class
