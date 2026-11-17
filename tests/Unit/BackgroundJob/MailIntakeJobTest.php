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

	public function testARunLogsWhatTheIntakeDid(): void {
		$intake = $this->createMock(MailIntakeService::class);
		$totals = ['mailboxes' => 1, 'scanned' => 2, 'tickets' => 1, 'skipped' => 1];
		$intake->expects($this->once())->method('run')->willReturn($totals);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Pipelinq: ticket mailbox intake complete', $totals);

		$job = new MailIntakeJob($this->createMock(ITimeFactory::class), $intake, $logger);
		self::runJob($job);

		$this->assertSame(300, $job->getInterval());
	}

	public function testNoMailboxesLogsNothing(): void {
		$intake = $this->createMock(MailIntakeService::class);
		$intake->method('run')->willReturn(['mailboxes' => 0, 'scanned' => 0, 'tickets' => 0, 'skipped' => 0]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		self::runJob(new MailIntakeJob($this->createMock(ITimeFactory::class), $intake, $logger));
	}

	public function testAFailingIntakeIsLoggedNotThrown(): void {
		$intake = $this->createMock(MailIntakeService::class);
		$intake->method('run')->willThrowException(new RuntimeException('mail table gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Pipelinq: the ticket mailbox intake failed', ['exception' => 'mail table gone']);

		self::runJob(new MailIntakeJob($this->createMock(ITimeFactory::class), $intake, $logger));
	}

	private static function runJob(MailIntakeJob $job): void {
		$method = new ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}
}
