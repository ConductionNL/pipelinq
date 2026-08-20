<?php

/**
 * Unit tests for WalkInQueueRebalanceJob — delegate-only job that calls
 * WalkInQueueService::rebalance().
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
 * @spec openspec/changes/appointment-booking-09-walkin-queue/specs/appointment-booking/spec.md#req-apt-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\WalkInQueueRebalanceJob;
use OCA\Pipelinq\Service\WalkInQueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for WalkInQueueRebalanceJob.
 */
class WalkInQueueRebalanceJobTest extends TestCase {

	/**
	 * Build the job under test with a configurable rebalance return value.
	 *
	 * @param int $touchedCount Value returned from WalkInQueueService::rebalance.
	 * @param bool $shouldThrow When true, the service throws to exercise the catch.
	 *
	 * @return array{0: WalkInQueueRebalanceJob, 1: WalkInQueueService, 2: LoggerInterface}
	 */
	private function buildJob(int $touchedCount = 0, bool $shouldThrow = false): array {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());

		$service = $this->createMock(WalkInQueueService::class);
		if ($shouldThrow === true) {
			$service->method('rebalance')->willThrowException(new \RuntimeException('boom'));
		} else {
			$service->method('rebalance')->willReturn($touchedCount);
		}

		$logger = $this->createMock(LoggerInterface::class);

		return [new WalkInQueueRebalanceJob($time, $service, $logger), $service, $logger];
	}//end buildJob()

	/**
	 * Test that the job can be instantiated.
	 *
	 * @return void
	 */
	public function testJobCanBeInstantiated(): void {
		[$job] = $this->buildJob();
		$this->assertInstanceOf(WalkInQueueRebalanceJob::class, $job);

	}//end testJobCanBeInstantiated()

	/**
	 * Test that run() delegates to WalkInQueueService::rebalance.
	 *
	 * @return void
	 */
	public function testRunDelegatesToService(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());

		$service = $this->createMock(WalkInQueueService::class);
		$service->expects($this->once())->method('rebalance')->willReturn(3);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('info');

		$job = new WalkInQueueRebalanceJob($time, $service, $logger);
		$ref = new \ReflectionMethod($job, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, null);

	}//end testRunDelegatesToService()

	/**
	 * Test that run() logs (without rethrowing) when the service throws.
	 *
	 * @return void
	 */
	public function testRunSwallowsServiceExceptions(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());

		$service = $this->createMock(WalkInQueueService::class);
		$service->method('rebalance')->willThrowException(new \RuntimeException('boom'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning');

		$job = new WalkInQueueRebalanceJob($time, $service, $logger);
		$ref = new \ReflectionMethod($job, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, null);

	}//end testRunSwallowsServiceExceptions()
}//end class
