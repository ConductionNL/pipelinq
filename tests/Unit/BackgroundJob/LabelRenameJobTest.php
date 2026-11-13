<?php

/**
 * Unit tests for LabelRenameJob: the queued rewrite of a renamed label, run as the administrator who asked for it.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\LabelRenameJob;
use OCA\Pipelinq\Service\Labels\LabelRenameService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for LabelRenameJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\LabelRenameJob
 */
class LabelRenameJobTest extends TestCase {

	/**
	 * Run the job's protected run() with an argument.
	 *
	 * @param LabelRenameJob $job      The job.
	 * @param mixed          $argument The argument.
	 *
	 * @return void
	 */
	private static function runJob(LabelRenameJob $job, mixed $argument): void {
		(new ReflectionMethod(LabelRenameJob::class, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * The rewrite runs as the administrator, with the old and new label, and the session is cleared after.
	 *
	 * @return void
	 */
	public function testTheRewriteRunsAsTheAdministrator(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('admin')->willReturn($user);
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->exactly(2))->method('setUser')->willReturnCallback(
			static function (?IUser $set) use ($user): void {
				static $calls = 0;
				$calls++;
				TestCase::assertSame($calls === 1 ? $user : null, $set);
			}
		);
		$service = $this->createMock(LabelRenameService::class);
		$service->expects($this->once())->method('rewriteAll')->with('vip', 'priority', 'admin')->willReturn(7);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('LabelRenameJob: items rewritten', ['count' => 7]);

		self::runJob(new LabelRenameJob($this->createMock(ITimeFactory::class), $service, $users, $session, $logger), ['userId' => 'admin', 'from' => 'vip', 'to' => 'priority']);
	}//end testTheRewriteRunsAsTheAdministrator()

	/**
	 * Without the administrator nothing is rewritten.
	 *
	 * @return void
	 */
	public function testAMissingAdministratorRewritesNothing(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('')->willReturn(null);
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->never())->method('setUser');
		$service = $this->createMock(LabelRenameService::class);
		$service->expects($this->never())->method('rewriteAll');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('LabelRenameJob: the administrator no longer exists; nothing rewritten');

		self::runJob(new LabelRenameJob($this->createMock(ITimeFactory::class), $service, $users, $session, $logger), null);
	}//end testAMissingAdministratorRewritesNothing()

	/**
	 * A failed rewrite is logged and the session is still cleared.
	 *
	 * @return void
	 */
	public function testAFailedRewriteIsLoggedAndTheSessionCleared(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$session = $this->createMock(IUserSession::class);
		$cleared = false;
		$session->method('setUser')->willReturnCallback(
			static function (?IUser $set) use (&$cleared): void {
				$cleared = ($set === null);
			}
		);
		$service = $this->createMock(LabelRenameService::class);
		$service->method('rewriteAll')->willThrowException(new RuntimeException('store down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('LabelRenameJob: rewrite failed', ['exception' => 'store down']);

		self::runJob(new LabelRenameJob($this->createMock(ITimeFactory::class), $service, $users, $session, $logger), ['userId' => 'admin', 'from' => 'a', 'to' => 'b']);
		$this->assertTrue($cleared);
	}//end testAFailedRewriteIsLoggedAndTheSessionCleared()
}//end class
