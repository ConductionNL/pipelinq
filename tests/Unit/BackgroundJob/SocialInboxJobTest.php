<?php

/**
 * SocialInboxJob tests: the job runs the social inbox intake every five minutes and never throws.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\SocialInboxJob;
use OCA\Pipelinq\Service\Social\SocialInboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for {@see SocialInboxJob}.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\SocialInboxJob
 */
final class SocialInboxJobTest extends TestCase {

	/**
	 * A run logs what the intake did.
	 *
	 * @return void
	 */
	public function testARunLogsWhatTheIntakeDid(): void {
		$intake = $this->createMock(originalClassName: SocialInboxService::class);
		$totals = ['accounts' => 2, 'messages' => 3, 'tickets' => 3, 'refused' => 1];
		$intake->expects($this->once())->method('run')->willReturn($totals);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Pipelinq: social inbox intake complete', $totals);

		$job = new SocialInboxJob(time: $this->createMock(originalClassName: ITimeFactory::class), inbox: $intake, logger: $logger);
		self::runJob(job: $job);

		$this->assertSame(expected: 300, actual: $job->getInterval());
	}

	/**
	 * No connected accounts logs nothing.
	 *
	 * @return void
	 */
	public function testNoConnectedAccountsLogsNothing(): void {
		$intake = $this->createMock(originalClassName: SocialInboxService::class);
		$intake->method('run')->willReturn(['accounts' => 0, 'messages' => 0, 'tickets' => 0, 'refused' => 0]);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		self::runJob(job: new SocialInboxJob(time: $this->createMock(originalClassName: ITimeFactory::class), inbox: $intake, logger: $logger));
	}

	/**
	 * A failing intake is logged not thrown.
	 *
	 * @return void
	 */
	public function testAFailingIntakeIsLoggedNotThrown(): void {
		$intake = $this->createMock(originalClassName: SocialInboxService::class);
		$intake->method('run')->willThrowException(new RuntimeException('broker unreachable'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Pipelinq: the social inbox intake failed', ['exception' => 'broker unreachable']);

		self::runJob(job: new SocialInboxJob(time: $this->createMock(originalClassName: ITimeFactory::class), inbox: $intake, logger: $logger));
	}

	/**
	 * Stand-in for runJob().
	 *
	 * @param SocialInboxJob $job The job.
	 *
	 * @return void
	 */
	private static function runJob(SocialInboxJob $job): void {
		$method = new ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}
}
