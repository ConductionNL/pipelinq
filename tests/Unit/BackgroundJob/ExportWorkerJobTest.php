<?php

/**
 * Unit tests for ExportWorkerJob: pending runs are executed in batches of ten, failures isolated.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\ExportWorkerJob;
use OCA\Pipelinq\Service\Export\ExportExecutionService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The export worker's pass over pending runs.
 */
#[CoversClass(ExportWorkerJob::class)]
class ExportWorkerJobTest extends TestCase {

	/**
	 * @var ExportRunService&MockObject
	 */
	private ExportRunService&MockObject $runs;

	/**
	 * @var ExportExecutionService&MockObject
	 */
	private ExportExecutionService&MockObject $execution;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->runs      = $this->createMock(ExportRunService::class);
		$this->execution = $this->createMock(ExportExecutionService::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run one pass.
	 *
	 * @return void
	 */
	private function pass(): void {
		$job = new ExportWorkerJob($this->createMock(ITimeFactory::class), $this->runs, $this->execution, $this->logger);
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end pass()

	/**
	 * The worker asks only for pending runs and executes each, then logs the count.
	 *
	 * @return void
	 */
	public function testExecutesEveryPendingRun(): void {
		$pending = [['id' => 'r1'], ['id' => 'r2']];
		$this->runs->expects($this->once())->method('listRuns')->with(['status' => 'pending'])->willReturn($pending);
		$seen = [];
		$this->execution->expects($this->exactly(2))->method('executeRun')
			->willReturnCallback(function (array $run) use (&$seen): array {
				$seen[] = $run['id'];
				return [];
			});
		$this->logger->expects($this->once())->method('info')
			->with('ExportWorkerJob: processed pending export runs', ['count' => 2]);

		$this->pass();

		$this->assertSame(['r1', 'r2'], $seen);
	}//end testExecutesEveryPendingRun()

	/**
	 * At most ten runs are executed per pass.
	 *
	 * @return void
	 */
	public function testExecutesAtMostTenRunsPerPass(): void {
		$pending = array_map(static fn (int $i): array => ['id' => 'r' . $i], range(1, 13));
		$this->runs->method('listRuns')->willReturn($pending);
		$this->execution->expects($this->exactly(10))->method('executeRun')->willReturn([]);
		$this->logger->expects($this->once())->method('info')->with($this->anything(), ['count' => 10]);

		$this->pass();
	}//end testExecutesAtMostTenRunsPerPass()

	/**
	 * A failing run is logged with its id (or uuid) and the others still run.
	 *
	 * @return void
	 */
	public function testAFailingRunDoesNotStopTheOthers(): void {
		$this->runs->method('listRuns')->willReturn([['uuid' => 'u-1'], ['id' => 'r2'], []]);
		$this->execution->expects($this->exactly(3))->method('executeRun')
			->willReturnCallback(static function (array $run): array {
				if (($run['uuid'] ?? '') === 'u-1' || $run === []) {
					throw new \RuntimeException('disk full');
				}

				return [];
			});
		$errors = [];
		$this->logger->method('error')->willReturnCallback(function (string $message, array $context) use (&$errors): void {
			$errors[] = [$message, $context];
		});
		$this->logger->expects($this->once())->method('info')->with($this->anything(), ['count' => 3]);

		$this->pass();

		$this->assertSame(
			[
				['ExportWorkerJob: run execution failed', ['runId' => 'u-1', 'error' => 'disk full']],
				['ExportWorkerJob: run execution failed', ['runId' => '', 'error' => 'disk full']],
			],
			$errors
		);
	}//end testAFailingRunDoesNotStopTheOthers()

	/**
	 * No pending runs: nothing executed and nothing logged.
	 *
	 * @return void
	 */
	public function testNoPendingRunsLogsNothing(): void {
		$this->runs->method('listRuns')->willReturn([]);
		$this->execution->expects($this->never())->method('executeRun');
		$this->logger->expects($this->never())->method('info');

		$this->pass();
	}//end testNoPendingRunsLogsNothing()

	/**
	 * When the pending runs cannot be listed, the error is logged and nothing runs.
	 *
	 * @return void
	 */
	public function testAFailedListingIsLoggedAndStops(): void {
		$this->runs->method('listRuns')->willThrowException(new \RuntimeException('register missing'));
		$this->execution->expects($this->never())->method('executeRun');
		$this->logger->expects($this->once())->method('error')
			->with('ExportWorkerJob: failed to list pending runs', ['error' => 'register missing']);

		$this->pass();
	}//end testAFailedListingIsLoggedAndStops()
}//end class
