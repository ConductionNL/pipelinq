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

use OCA\Pipelinq\BackgroundJob\CallTranscriptionJob;
use OCA\Pipelinq\Service\CallReview\CallTranscriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The queued job transcribes the ticket its argument names.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\CallTranscriptionJob
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.3
 */
class CallTranscriptionJobTest extends TestCase {

	/**
	 * Run the job with an argument.
	 *
	 * @param CallTranscriptionService $service  The transcription service.
	 * @param LoggerInterface          $logger   The logger.
	 * @param mixed                    $argument The job argument.
	 *
	 * @return void
	 */
	private function runJob(CallTranscriptionService $service, LoggerInterface $logger, mixed $argument): void {
		$job = new CallTranscriptionJob($this->createMock(ITimeFactory::class), $service, $logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * The named ticket is transcribed and the outcome logged.
	 *
	 * @return void
	 */
	public function testTheNamedTicketIsTranscribed(): void {
		$service = $this->createMock(CallTranscriptionService::class);
		$service->expects($this->once())->method('transcribe')->with('cm-1')->willReturn('done');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('CallTranscriptionJob: call transcribed', ['ticket' => 'cm-1', 'status' => 'done']);

		$this->runJob($service, $logger, ['ticketId' => 'cm-1']);
	}//end testTheNamedTicketIsTranscribed()

	/**
	 * Without a ticket nothing runs; a failure is logged, not thrown.
	 *
	 * @return void
	 */
	public function testNoTicketOrAFailure(): void {
		$service = $this->createMock(CallTranscriptionService::class);
		$service->expects($this->never())->method('transcribe');
		$this->runJob($service, $this->createMock(LoggerInterface::class), null);
		$this->runJob($service, $this->createMock(LoggerInterface::class), ['ticketId' => '']);

		$failing = $this->createMock(CallTranscriptionService::class);
		$failing->method('transcribe')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('CallTranscriptionJob: transcription failed', ['ticket' => 'cm-2', 'exception' => 'down']);
		$this->runJob($failing, $logger, ['ticketId' => 'cm-2']);
	}//end testNoTicketOrAFailure()
}//end class
