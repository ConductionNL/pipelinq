<?php

/**
 * Unit tests for LabelRenameJob: runs the rewrite as the requesting administrator and always clears the session.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\LabelRenameJob;
use OCA\Pipelinq\Service\Labels\LabelRenameService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The queued label rename.
 */
#[CoversClass(LabelRenameJob::class)]
class LabelRenameJobTest extends TestCase {

	/**
	 * @var LabelRenameService&MockObject
	 */
	private LabelRenameService&MockObject $service;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service     = $this->createMock(LabelRenameService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run the job with an argument.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 */
	private function runJob(mixed $argument): void {
		$job = new LabelRenameJob(
			$this->createMock(ITimeFactory::class),
			$this->service,
			$this->userManager,
			$this->userSession,
			$this->logger
		);
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, $argument);
	}//end runJob()

	/**
	 * The administrator.
	 *
	 * @return IUser&MockObject
	 */
	private function admin(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userManager->method('get')->with('admin')->willReturn($user);
		return $user;
	}//end admin()

	/**
	 * A vanished administrator rewrites nothing and never touches the session.
	 *
	 * @return void
	 */
	public function testAMissingAdministratorRewritesNothing(): void {
		$this->userManager->method('get')->with('gone')->willReturn(null);
		$this->service->expects($this->never())->method('rewriteAll');
		$this->userSession->expects($this->never())->method('setUser');
		$this->logger->expects($this->once())->method('warning')
			->with('LabelRenameJob: the administrator no longer exists; nothing rewritten');

		$this->runJob(['userId' => 'gone', 'from' => 'a', 'to' => 'b']);
	}//end testAMissingAdministratorRewritesNothing()

	/**
	 * The rewrite runs as the administrator, logs the count and clears the session after.
	 *
	 * @return void
	 */
	public function testRewritesAsTheAdministratorAndClearsTheSession(): void {
		$admin = $this->admin();
		$calls = [];
		$this->userSession->expects($this->exactly(2))->method('setUser')
			->willReturnCallback(function ($user) use (&$calls): void {
				$calls[] = $user;
			});
		$this->service->expects($this->once())->method('rewriteAll')
			->with('Hot lead', 'Warm lead', 'admin')
			->willReturnCallback(function () use (&$calls, $admin): int {
				$this->assertSame([$admin], $calls, 'the session holds the administrator during the rewrite');
				return 12;
			});
		$this->logger->expects($this->once())->method('info')->with('LabelRenameJob: items rewritten', ['count' => 12]);

		$this->runJob(['userId' => 'admin', 'from' => 'Hot lead', 'to' => 'Warm lead']);

		$this->assertSame([$admin, null], $calls);
	}//end testRewritesAsTheAdministratorAndClearsTheSession()

	/**
	 * A failing rewrite is logged and the session is still cleared.
	 *
	 * @return void
	 */
	public function testAFailingRewriteStillClearsTheSession(): void {
		$admin = $this->admin();
		$calls = [];
		$this->userSession->method('setUser')->willReturnCallback(function ($user) use (&$calls): void {
			$calls[] = $user;
		});
		$this->service->method('rewriteAll')->willThrowException(new \TypeError('bad label'));
		$this->logger->expects($this->once())->method('warning')
			->with('LabelRenameJob: rewrite failed', ['exception' => 'bad label']);

		$this->runJob(['userId' => 'admin', 'from' => 'x', 'to' => 'y']);

		$this->assertSame([$admin, null], $calls);
	}//end testAFailingRewriteStillClearsTheSession()

	/**
	 * Missing from/to arguments are passed as empty strings.
	 *
	 * @return void
	 */
	public function testMissingLabelsArePassedAsEmptyStrings(): void {
		$this->admin();
		$this->service->expects($this->once())->method('rewriteAll')->with('', '', 'admin')->willReturn(0);

		$this->runJob(['userId' => 'admin']);
	}//end testMissingLabelsArePassedAsEmptyStrings()
}//end class
