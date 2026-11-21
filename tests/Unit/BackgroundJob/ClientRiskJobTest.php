<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\ClientRiskJob;
use OCA\Pipelinq\Service\ClientRiskService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The daily recompute runs every client once and logs what it wrote.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\ClientRiskJob
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
 */
class ClientRiskJobTest extends TestCase {

	/**
	 * Run the job over a risk service double.
	 *
	 * @param ClientRiskService $risk   The risk service.
	 * @param LoggerInterface   $logger The logger.
	 *
	 * @return ClientRiskJob
	 */
	private function runJob(ClientRiskService $risk, LoggerInterface $logger): ClientRiskJob {
		$job = new ClientRiskJob($this->createMock(ITimeFactory::class), $risk, $logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
		return $job;
	}//end runJob()

	/**
	 * The job recomputes every client once a day and logs the tally.
	 *
	 * @return void
	 */
	public function testItRecomputesEveryClientDaily(): void {
		$risk = $this->createMock(ClientRiskService::class);
		$risk->expects($this->once())->method('recomputeAll')->willReturn(['written' => 2, 'unchanged' => 40]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('ClientRiskJob: client risk recomputed', ['written' => 2, 'unchanged' => 40]);

		$job = $this->runJob($risk, $logger);

		$this->assertSame(86400, $job->getInterval());
	}//end testItRecomputesEveryClientDaily()

	/**
	 * A failed recompute is logged as an error, not thrown.
	 *
	 * @return void
	 */
	public function testAFailureIsLogged(): void {
		$risk = $this->createMock(ClientRiskService::class);
		$risk->method('recomputeAll')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('ClientRiskJob: the risk recompute failed', ['exception' => 'down']);
		$logger->expects($this->never())->method('info');

		$this->runJob($risk, $logger);
	}//end testAFailureIsLogged()
}//end class
