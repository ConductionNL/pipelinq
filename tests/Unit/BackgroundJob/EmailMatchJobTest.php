<?php

/**
 * Unit tests for EmailMatchJob.
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
 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-emails-must-be-automatically-linked-to-crm-contacts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\EmailMatchJob;
use OCA\Pipelinq\Service\EmailMatchService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for EmailMatchJob.
 *
 * The job is a thin orchestrator over EmailMatchService — these tests
 * assert that it (a) calls runForUser for each enumerated user,
 * (b) accumulates counts across users, and (c) keeps going when one
 * user's run throws (calling writeStatus with a static error message).
 */
class EmailMatchJobTest extends TestCase {

	/**
	 * Build a test user mock.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function buildUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end buildUser()

	/**
	 * Build a user manager that iterates a fixed list.
	 *
	 * @param array<int,IUser> $users The user list.
	 *
	 * @return IUserManager
	 */
	private function buildUserManager(array $users): IUserManager {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $cb) use ($users): void {
				foreach ($users as $user) {
					$cb($user);
				}
			}
		);
		return $userManager;
	}//end buildUserManager()

	/**
	 * Invoke the protected `run` method via reflection.
	 *
	 * @param EmailMatchJob $job The job instance.
	 *
	 * @return void
	 */
	private function invokeRun(EmailMatchJob $job): void {
		$reflection = new ReflectionMethod($job, 'run');
		$reflection->setAccessible(true);
		$reflection->invoke($job, null);

	}//end invokeRun()

	/**
	 * The job calls runForUser for each user.
	 *
	 * @return void
	 */
	public function testCallsRunForUserPerUser(): void {
		$service = $this->createMock(EmailMatchService::class);
		$service->expects($this->exactly(2))->method('runForUser')->willReturn(
			['linked' => 1, 'scanned' => 2]
		);

		$job = new EmailMatchJob(
			time: $this->buildTimeFactory(),
			emailMatchService: $service,
			userManager: $this->buildUserManager(
				[$this->buildUser('alice'), $this->buildUser('bob')]
			),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->invokeRun(job: $job);

	}//end testCallsRunForUserPerUser()

	/**
	 * Per-user failures do not stop the loop and trigger a status write.
	 *
	 * @return void
	 */
	public function testContinuesOnPerUserFailure(): void {
		$service = $this->createMock(EmailMatchService::class);
		$service->method('runForUser')->willReturnCallback(
			static function (string $userId): array {
				if ($userId === 'alice') {
					throw new \RuntimeException('boom');
				}

				return ['linked' => 3, 'scanned' => 4];
			}
		);

		$service->expects($this->once())
			->method('writeStatus')
			->with(
				$this->equalTo('alice'),
				$this->equalTo(0),
				$this->equalTo(0),
				$this->equalTo('Match run failed')
			);

		$job = new EmailMatchJob(
			time: $this->buildTimeFactory(),
			emailMatchService: $service,
			userManager: $this->buildUserManager(
				[$this->buildUser('alice'), $this->buildUser('bob')]
			),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->invokeRun(job: $job);

	}//end testContinuesOnPerUserFailure()

	/**
	 * The job logs the aggregated link / scan / error counts.
	 *
	 * @return void
	 */
	public function testLogsAggregateSummary(): void {
		$service = $this->createMock(EmailMatchService::class);
		$service->method('runForUser')->willReturn(['linked' => 2, 'scanned' => 5]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('info');

		$job = new EmailMatchJob(
			time: $this->buildTimeFactory(),
			emailMatchService: $service,
			userManager: $this->buildUserManager([$this->buildUser('alice')]),
			logger: $logger,
		);

		$this->invokeRun(job: $job);

	}//end testLogsAggregateSummary()

	/**
	 * Build a stub ITimeFactory.
	 *
	 * @return ITimeFactory
	 */
	private function buildTimeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());
		return $time;
	}//end buildTimeFactory()

}//end class
