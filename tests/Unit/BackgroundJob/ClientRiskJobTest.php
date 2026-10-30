<?php

/**
 * Unit tests for ClientRiskJob: daily, delegates to the risk service, never throws.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\ClientRiskJob;
use OCA\Pipelinq\Service\ClientRiskService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the daily churn risk job.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */
class ClientRiskJobTest extends TestCase {

	/**
	 * Run the job's protected run().
	 *
	 * @param ClientRiskJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(ClientRiskJob $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * The job runs once a day and recomputes every client.
	 *
	 * @return void
	 */
	public function testRunsDailyAndRecomputesEveryClient(): void {
		$risk = $this->createMock(ClientRiskService::class);
		$risk->expects($this->once())->method('recomputeAll')->willReturn(3);

		$job = new ClientRiskJob($this->createMock(ITimeFactory::class), $risk, $this->createMock(LoggerInterface::class));
		$this->runJob($job);

		$this->assertSame(86400, $job->getInterval());
	}//end testRunsDailyAndRecomputesEveryClient()

	/**
	 * appinfo/info.xml registers the job, or Nextcloud never runs it.
	 *
	 * @return void
	 */
	public function testInfoXmlRegistersTheJob(): void {
		$info = simplexml_load_string(file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));
		$jobs = array_map('strval', iterator_to_array($info->{'background-jobs'}->job, false));

		$this->assertContains(ClientRiskJob::class, $jobs);
	}//end testInfoXmlRegistersTheJob()

	/**
	 * A failing recompute is logged, not thrown.
	 *
	 * @return void
	 */
	public function testFailureIsLoggedNotThrown(): void {
		$risk = $this->createMock(ClientRiskService::class);
		$risk->method('recomputeAll')->willThrowException(new \RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->runJob(new ClientRiskJob($this->createMock(ITimeFactory::class), $risk, $logger));
	}//end testFailureIsLoggedNotThrown()
}//end class
