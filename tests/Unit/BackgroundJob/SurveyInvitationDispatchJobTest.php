<?php

/**
 * Unit tests for the run() of SurveyInvitationDispatchJob: what is written
 * back for each scheduled invitation.
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
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\BackgroundJob\SurveyInvitationDispatchJob;
use OCA\Pipelinq\Service\SurveyDispatchService;
use OCA\Pipelinq\Service\SurveyInvitationSender;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Expired invitations are marked expired, due ones sent or marked failed.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\SurveyInvitationDispatchJob
 */
class SurveyInvitationDispatchJobTest extends TestCase {

	/**
	 * Invitations written back, as [invitation, uuid].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $written = [];

	/**
	 * Invitations handed to the sender, by id.
	 *
	 * @var array<int, string>
	 */
	private array $sent = [];

	/**
	 * Errors logged, as [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $errors = [];

	/**
	 * Run the job once over the given scheduled invitations.
	 *
	 * @param array<int, array<string, mixed>> $scheduled The invitations the dispatch service reads.
	 *
	 * @return void
	 */
	private function runJob(array $scheduled): void {
		$dispatch = $this->getMockBuilder(SurveyDispatchService::class)
			->disableOriginalConstructor()
			->onlyMethods(['read', 'write'])
			->getMock();
		$dispatch->expects($this->once())
			->method('read')
			->with(['status' => 'scheduled'])
			->willReturn($scheduled);
		$dispatch->method('write')->willReturnCallback(
			function (array $invitation, ?string $uuid = null): bool {
				$this->written[] = [$invitation, $uuid];
				return true;
			}
		);

		$sender = $this->getMockBuilder(SurveyInvitationSender::class)
			->disableOriginalConstructor()
			->onlyMethods(['send'])
			->getMock();
		$sender->method('send')->willReturnCallback(
			function (array $invitation, string $address = ''): bool {
				$this->sent[] = (string)$invitation['id'];
				if ($invitation['id'] === 'throws') {
					throw new RuntimeException('mail server gone');
				}

				return ($invitation['id'] !== 'refused');
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		$job = new SurveyInvitationDispatchJob($this->createMock(ITimeFactory::class), $dispatch, $sender, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * An expired invitation is written back as expired and never sent.
	 *
	 * @return void
	 */
	public function testAnExpiredInvitationIsMarkedExpired(): void {
		$this->runJob([['id' => 'old', 'status' => 'scheduled', 'expiresAt' => '2000-01-01T00:00:00+00:00']]);

		$this->assertSame([], $this->sent);
		$this->assertSame([[['id' => 'old', 'status' => 'expired', 'expiresAt' => '2000-01-01T00:00:00+00:00'], 'old']], $this->written);
	}//end testAnExpiredInvitationIsMarkedExpired()

	/**
	 * A sent invitation is written back as sent with the moment it went out.
	 *
	 * @return void
	 */
	public function testASentInvitationRecordsWhenItWentOut(): void {
		$before = new DateTimeImmutable('-1 second');
		$this->runJob([['uuid' => 'u-1', 'id' => 'ok', 'status' => 'scheduled', 'scheduledFor' => '2000-01-01T00:00:00+00:00']]);
		$after = new DateTimeImmutable('+1 second');

		$this->assertSame(['ok'], $this->sent);
		$this->assertCount(1, $this->written);
		[$invitation, $uuid] = $this->written[0];
		$this->assertSame('ok', $uuid);
		$this->assertSame('sent', $invitation['status']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $invitation['sentAt']);
		$sentAt = new DateTimeImmutable($invitation['sentAt']);
		$this->assertGreaterThanOrEqual($before, $sentAt);
		$this->assertLessThanOrEqual($after, $sentAt);
	}//end testASentInvitationRecordsWhenItWentOut()

	/**
	 * A refused or throwing hand-off is written back as failed, never retried
	 * silently; the throw is logged, and the uuid falls back to `uuid`.
	 *
	 * @return void
	 */
	public function testAFailedHandOffIsMarkedFailed(): void {
		$this->runJob(
			[
				['id' => 'refused', 'status' => 'scheduled'],
				['id' => 'throws', 'status' => 'scheduled'],
			]
		);

		$this->assertSame(['refused', 'throws'], $this->sent);
		$this->assertSame(
			[
				[['id' => 'refused', 'status' => 'failed'], 'refused'],
				[['id' => 'throws', 'status' => 'failed'], 'throws'],
			],
			$this->written
		);
		$this->assertSame(
			[['SurveyInvitationDispatchJob: the channel hand-off threw', ['uuid' => 'throws', 'exception' => 'mail server gone']]],
			$this->errors
		);
	}//end testAFailedHandOffIsMarkedFailed()

	/**
	 * An invitation whose schedule date cannot be read waits rather than
	 * being sent, and an unreadable expiry does not expire it.
	 *
	 * @return void
	 */
	public function testUnreadableDatesNeitherSendNorExpire(): void {
		$this->runJob([['id' => 'garbled', 'status' => 'scheduled', 'scheduledFor' => 'next tuesdayish', 'expiresAt' => 'never-ever']]);

		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->written);
	}//end testUnreadableDatesNeitherSendNorExpire()

	/**
	 * The uuid written back falls back from `id` to `uuid`.
	 *
	 * @return void
	 */
	public function testTheUuidFallsBackToTheUuidField(): void {
		$this->runJob([['uuid' => 'u-9', 'status' => 'scheduled', 'expiresAt' => '2000-01-01T00:00:00+00:00']]);

		$this->assertSame('u-9', $this->written[0][1]);
	}//end testTheUuidFallsBackToTheUuidField()
}//end class
