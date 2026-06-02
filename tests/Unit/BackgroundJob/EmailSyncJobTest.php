<?php

/**
 * Unit tests for EmailSyncJob.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\EmailSyncJob;
use OCA\Pipelinq\Service\EmailSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EmailSyncJob.
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-5.1
 */
class EmailSyncJobTest extends TestCase
{
    /**
     * Mock time factory.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * Mock email sync service.
     *
     * @var EmailSyncService&MockObject
     */
    private EmailSyncService $emailSyncService;

    /**
     * Mock user manager.
     *
     * @var IUserManager&MockObject
     */
    private IUserManager $userManager;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->timeFactory      = $this->createMock(ITimeFactory::class);
        $this->emailSyncService = $this->createMock(EmailSyncService::class);
        $this->userManager      = $this->createMock(IUserManager::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->timeFactory->method('getTime')->willReturn(time());
    }//end setUp()

    /**
     * Build the job under test.
     *
     * @return EmailSyncJob
     */
    private function buildJob(): EmailSyncJob
    {
        return new EmailSyncJob(
            $this->timeFactory,
            $this->emailSyncService,
            $this->userManager,
            $this->logger,
        );
    }//end buildJob()

    /**
     * Test that the job can be instantiated.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testJobCanBeInstantiated(): void
    {
        $this->assertInstanceOf(EmailSyncJob::class, $this->buildJob());
    }//end testJobCanBeInstantiated()

    /**
     * Test that the job runs without error when no users have sync enabled.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testJobRunsWithNoSyncUsers(): void
    {
        $this->userManager->method('callForAllUsers')
            ->willReturnCallback(static function (callable $callback): void {
                // No users — callback never called.
            });

        $this->emailSyncService->expects($this->never())->method('updateLastSyncTime');

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobRunsWithNoSyncUsers()

    /**
     * Test that the job calls the email leaf link API on match (mock the service).
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testJobCallsUpdateForEnabledUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-sync-enabled');

        $this->userManager->method('callForAllUsers')
            ->willReturnCallback(static function (callable $callback) use ($user): void {
                $callback($user);
            });

        $this->emailSyncService->method('isSyncEnabled')
            ->with('user-sync-enabled')
            ->willReturn(true);

        $this->emailSyncService->expects($this->once())
            ->method('updateLastSyncTime')
            ->with('user-sync-enabled', 0, null);

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobCallsUpdateForEnabledUser()

    /**
     * Test that the job continues when one user's run throws an exception.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testJobContinuesAfterUserError(): void
    {
        $user1 = $this->createMock(IUser::class);
        $user1->method('getUID')->willReturn('user-error');

        $user2 = $this->createMock(IUser::class);
        $user2->method('getUID')->willReturn('user-ok');

        $this->userManager->method('callForAllUsers')
            ->willReturnCallback(static function (callable $callback) use ($user1, $user2): void {
                $callback($user1);
                $callback($user2);
            });

        $callCount = 0;
        $this->emailSyncService->method('isSyncEnabled')
            ->willReturnCallback(static function (string $uid) use (&$callCount): bool {
                $callCount++;
                if ($uid === 'user-error') {
                    throw new \RuntimeException('Mail account unreachable');
                }

                return true;
            });

        // user-ok should still be updated.
        $this->emailSyncService->expects($this->atLeastOnce())
            ->method('updateLastSyncTime');

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobContinuesAfterUserError()

    /**
     * Test that users with sync disabled are skipped.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testJobSkipsDisabledUsers(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user-disabled');

        $this->userManager->method('callForAllUsers')
            ->willReturnCallback(static function (callable $callback) use ($user): void {
                $callback($user);
            });

        $this->emailSyncService->method('isSyncEnabled')
            ->with('user-disabled')
            ->willReturn(false);

        $this->emailSyncService->expects($this->never())->method('updateLastSyncTime');

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSkipsDisabledUsers()
}//end class
