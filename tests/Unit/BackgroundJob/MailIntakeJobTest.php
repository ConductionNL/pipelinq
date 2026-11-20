<?php

/**
 * MailIntakeJob tests: the job runs the intake every five minutes and never throws.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\MailIntakeJob;
use OCA\Pipelinq\Service\MailIntake\MailIntakeService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for {@see MailIntakeJob}.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\MailIntakeJob
 */
final class MailIntakeJobTest extends TestCase {

	/**
	 * A run logs what the intake did.
	 *
	 * @return void
	 */
	public function testARunLogsWhatTheIntakeDid(): void {
		$intake = $this->createMock(originalClassName: MailIntakeService::class);
		$totals = ['mailboxes' => 1, 'scanned' => 2, 'tickets' => 1, 'skipped' => 1];
		$intake->expects($this->once())->method('run')->willReturn($totals);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Pipelinq: ticket mailbox intake complete', $totals);

		$job = new MailIntakeJob(time: $this->createMock(originalClassName: ITimeFactory::class), intake: $intake, logger: $logger);
		self::runJob(job: $job);

		$this->assertSame(expected: 300, actual: $job->getInterval());
	}

	/**
	 * No mailboxes logs nothing.
	 *
	 * @return void
	 */
	public function testNoMailboxesLogsNothing(): void {
		$intake = $this->createMock(originalClassName: MailIntakeService::class);
		$intake->method('run')->willReturn(['mailboxes' => 0, 'scanned' => 0, 'tickets' => 0, 'skipped' => 0]);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		self::runJob(job: new MailIntakeJob(time: $this->createMock(originalClassName: ITimeFactory::class), intake: $intake, logger: $logger));
	}

	/**
	 * A failing intake is logged not thrown.
	 *
	 * @return void
	 */
	public function testAFailingIntakeIsLoggedNotThrown(): void {
		$intake = $this->createMock(originalClassName: MailIntakeService::class);
		$intake->method('run')->willThrowException(new RuntimeException('mail table gone'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Pipelinq: the ticket mailbox intake failed', ['exception' => 'mail table gone']);

		self::runJob(job: new MailIntakeJob(time: $this->createMock(originalClassName: ITimeFactory::class), intake: $intake, logger: $logger));
	}

	/**
	 * Stand-in for runJob().
	 *
	 * @param MailIntakeJob $job The job.
	 *
	 * @return void
	 */
	private static function runJob(MailIntakeJob $job): void {
		$method = new ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}
}
