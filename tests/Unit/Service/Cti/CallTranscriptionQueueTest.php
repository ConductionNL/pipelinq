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

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\BackgroundJob\CallTranscriptionJob;
use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\CtiContactMatcher;
use OCA\Pipelinq\Service\CtiDispositionService;
use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\TicketService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An attached recording queues its transcription only when an administrator switched it on.
 *
 * @covers \OCA\Pipelinq\Service\CtiService
 * @uses   \OCA\Pipelinq\Service\PhoneNormaliser
 * @uses   \OCA\Pipelinq\Service\Cti\CallRecording
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.2
 */
class CallTranscriptionQueueTest extends TestCase {

	/**
	 * CtiService over a ticket store, a job list and the switch.
	 *
	 * @param string   $enabled   The switch's value.
	 * @param IJobList $jobs      The job list.
	 * @param bool     $saveFails Whether the recording write throws.
	 *
	 * @return CtiService
	 */
	private function service(string $enabled, IJobList $jobs, bool $saveFails = false): CtiService {
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(true);
		if ($saveFails === true) {
			$tickets->method('save')->willThrowException(new RuntimeException('store down'));
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['call_transcription_enabled' => $enabled][$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(IJobList::class)->willReturn($jobs);

		return new CtiService(
			$container,
			$appConfig,
			$this->createMock(AdapterRegistry::class),
			new PhoneNormaliser($appConfig, $this->createMock(LoggerInterface::class)),
			$this->createMock(CtiContactMatcher::class),
			$this->createMock(CtiDispositionService::class),
			$tickets,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Switched on, the attached recording's ticket is queued for transcription.
	 *
	 * @return void
	 */
	public function testSwitchedOnTheCallIsQueued(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(CallTranscriptionJob::class, ['ticketId' => 'cm-1']);

		$this->assertTrue($this->service('true', $jobs)->attachRecording('cm-1', 'https://rec/1', '2027-01-01T00:00:00Z'));
	}//end testSwitchedOnTheCallIsQueued()

	/**
	 * Switched off (the default), nothing is queued.
	 *
	 * @return void
	 */
	public function testSwitchedOffNothingIsQueued(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');

		$this->assertTrue($this->service('false', $jobs)->attachRecording('cm-1', 'https://rec/1', ''));
		$this->assertTrue($this->service('', $jobs)->attachRecording('cm-1', 'https://rec/1', ''));
	}//end testSwitchedOffNothingIsQueued()

	/**
	 * A recording that was not stored is not transcribed, and a failed queue never fails the attach.
	 *
	 * @return void
	 */
	public function testOnlyAStoredRecordingIsQueued(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');
		$this->assertFalse($this->service('true', $jobs, saveFails: true)->attachRecording('cm-1', 'https://rec/1', ''));

		$broken = $this->createMock(IJobList::class);
		$broken->method('add')->willThrowException(new RuntimeException('db down'));
		$this->assertTrue($this->service('true', $broken)->attachRecording('cm-1', 'https://rec/1', ''));
	}//end testOnlyAStoredRecordingIsQueued()
}//end class
