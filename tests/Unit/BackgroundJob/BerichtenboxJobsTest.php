<?php

/**
 * Unit tests for the two Berichtenbox timed jobs — DispatchQueuedMessagesJob
 * and FallbackEmailJob: the service-unavailable, success and failure paths and
 * the exact log line each one writes.
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
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-FALLBACK-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\DispatchQueuedMessagesJob;
use OCA\Pipelinq\BackgroundJob\FallbackEmailJob;
use OCA\Pipelinq\Kernel\Contracts\BerichtenboxServiceInterface;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for DispatchQueuedMessagesJob and FallbackEmailJob.
 */
class BerichtenboxJobsTest extends TestCase {

	/**
	 * Log lines written during one run, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $lines = [];

	/**
	 * A logger mock that records every call into $this->lines.
	 *
	 * @return LoggerInterface
	 */
	private function recordingLogger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->lines[] = [$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end recordingLogger()

	/**
	 * Build a job of the given class around a container that resolves the
	 * service, or throws when $service is null, and invoke its run().
	 *
	 * @param string                   $class   The job class.
	 * @param BerichtenboxService|null $service The service, or null for unavailable.
	 *
	 * @return TimedJob The job that ran.
	 */
	private function runJob(string $class, ?BerichtenboxService $service): TimedJob {
		$container = $this->createMock(ContainerInterface::class);
		if ($service === null) {
			$container->method('get')->willThrowException(new RuntimeException('no service'));
		} else {
			$container->method('get')->with(BerichtenboxServiceInterface::class)->willReturn($service);
		}

		$job = new $class($this->createMock(ITimeFactory::class), $container, $this->recordingLogger());
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		return $job;
	}//end runJob()

	/**
	 * Both jobs warn and stop when the service cannot be resolved.
	 *
	 * @return void
	 */
	public function testServiceUnavailableWarns(): void {
		$this->runJob(DispatchQueuedMessagesJob::class, null);
		$this->runJob(FallbackEmailJob::class, null);

		$this->assertSame(
			[
				['warning', 'DispatchQueuedMessagesJob: BerichtenboxService unavailable.', ['exception' => 'no service']],
				['warning', 'FallbackEmailJob: BerichtenboxService unavailable.', ['exception' => 'no service']],
			],
			$this->lines
		);
	}//end testServiceUnavailableWarns()

	/**
	 * The dispatcher drains one batch of 100 and logs the count.
	 *
	 * @return void
	 */
	public function testDispatcherLogsTheCount(): void {
		$service = $this->createMock(BerichtenboxService::class);
		$service->expects($this->once())->method('dispatchQueuedMessages')->with(100)->willReturn(7);
		$job = $this->runJob(DispatchQueuedMessagesJob::class, $service);

		$this->assertSame([['info', 'DispatchQueuedMessagesJob processed messages.', ['count' => 7]]], $this->lines);
		$this->assertSame(300, $job->getInterval());
	}//end testDispatcherLogsTheCount()

	/**
	 * The fallback job processes the fallback queue and logs what it sent.
	 *
	 * @return void
	 */
	public function testFallbackLogsWhatItSent(): void {
		$service = $this->createMock(BerichtenboxService::class);
		$service->expects($this->once())->method('processFallbackQueue')->willReturn(3);
		$job = $this->runJob(FallbackEmailJob::class, $service);

		$this->assertSame([['info', 'FallbackEmailJob: processed fallbacks.', ['sent' => 3]]], $this->lines);
		$this->assertSame(86400, $job->getInterval());
	}//end testFallbackLogsWhatItSent()

	/**
	 * A failing pass is caught and logged as an error, never thrown.
	 *
	 * @return void
	 */
	public function testFailingPassLogsAnError(): void {
		$service = $this->createMock(BerichtenboxService::class);
		$service->method('dispatchQueuedMessages')->willThrowException(new RuntimeException('dispatch broke'));
		$service->method('processFallbackQueue')->willThrowException(new RuntimeException('fallback broke'));
		$this->runJob(DispatchQueuedMessagesJob::class, $service);
		$this->runJob(FallbackEmailJob::class, $service);

		$this->assertSame(
			[
				['error', 'DispatchQueuedMessagesJob run failed.', ['exception' => 'dispatch broke']],
				['error', 'FallbackEmailJob run failed.', ['exception' => 'fallback broke']],
			],
			$this->lines
		);
	}//end testFailingPassLogsAnError()
}//end class
