<?php

/**
 * Unit tests for ExportWorkerJob: pending export runs are executed, at most ten per tick.
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

use OCA\Pipelinq\BackgroundJob\ExportWorkerJob;
use OCA\Pipelinq\Service\Export\ExportExecutionService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for ExportWorkerJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\ExportWorkerJob
 */
class ExportWorkerJobTest extends TestCase {

	/**
	 * Build and run the job.
	 *
	 * @param ExportRunService       $runs      The run store.
	 * @param ExportExecutionService $execution The executor.
	 * @param LoggerInterface        $logger    The logger.
	 *
	 * @return void
	 */
	private function runJob(ExportRunService $runs, ExportExecutionService $execution, LoggerInterface $logger): void {
		$job = new ExportWorkerJob($this->createMock(ITimeFactory::class), $runs, $execution, $logger);
		(new ReflectionMethod(ExportWorkerJob::class, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * Pending runs are executed in order, at most ten per tick, and a failing run does not stop the rest.
	 *
	 * @return void
	 */
	public function testPendingRunsAreExecutedTenAtATime(): void {
		$pending = [];
		for ($i = 1; $i <= 12; $i++) {
			$pending[] = ['id' => 'run-'.$i];
		}

		$runs = $this->createMock(ExportRunService::class);
		$runs->expects($this->once())->method('listRuns')->with(['status' => 'pending'])->willReturn($pending);
		$executed = [];
		$execution = $this->createMock(ExportExecutionService::class);
		$execution->method('executeRun')->willReturnCallback(
			static function (array $run) use (&$executed): array {
				$executed[] = $run['id'];
				if ($run['id'] === 'run-2') {
					throw new RuntimeException('disk full');
				}

				return $run;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('ExportWorkerJob: run execution failed', ['runId' => 'run-2', 'error' => 'disk full']);
		$logger->expects($this->once())->method('info')->with('ExportWorkerJob: processed pending export runs', ['count' => 10]);

		$this->runJob($runs, $execution, $logger);
		$this->assertSame(['run-1', 'run-2', 'run-3', 'run-4', 'run-5', 'run-6', 'run-7', 'run-8', 'run-9', 'run-10'], $executed);
	}//end testPendingRunsAreExecutedTenAtATime()

	/**
	 * A failing run without an id is logged by its uuid, and nothing pending logs nothing.
	 *
	 * @return void
	 */
	public function testARunIsNamedByUuidAndAnEmptyQueueIsQuiet(): void {
		$runs = $this->createMock(ExportRunService::class);
		$runs->method('listRuns')->willReturnOnConsecutiveCalls([['uuid' => 'u-1']], []);
		$execution = $this->createMock(ExportExecutionService::class);
		$execution->method('executeRun')->willThrowException(new RuntimeException('x'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('ExportWorkerJob: run execution failed', ['runId' => 'u-1', 'error' => 'x']);
		$logger->expects($this->once())->method('info');

		$this->runJob($runs, $execution, $logger);
		$this->runJob($runs, $execution, $logger);
	}//end testARunIsNamedByUuidAndAnEmptyQueueIsQuiet()

	/**
	 * When the pending runs cannot be listed, nothing is executed.
	 *
	 * @return void
	 */
	public function testAFailedListingExecutesNothing(): void {
		$runs = $this->createMock(ExportRunService::class);
		$runs->method('listRuns')->willThrowException(new RuntimeException('db down'));
		$execution = $this->createMock(ExportExecutionService::class);
		$execution->expects($this->never())->method('executeRun');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('ExportWorkerJob: failed to list pending runs', ['error' => 'db down']);

		$this->runJob($runs, $execution, $logger);
	}//end testAFailedListingExecutesNothing()
}//end class
