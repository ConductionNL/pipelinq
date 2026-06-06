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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-7
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
 */
class EmailSyncJobTest extends TestCase
{

    /**
     * The time factory mock.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * The email sync service mock.
     *
     * @var EmailSyncService&MockObject
     */
    private EmailSyncService $emailSyncService;

    /**
     * The user manager mock.
     *
     * @var IUserManager&MockObject
     */
    private IUserManager $userManager;

    /**
     * The logger mock.
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
        $this->timeFactory      = $this->createMock(originalClassName: ITimeFactory::class);
        $this->emailSyncService = $this->createMock(originalClassName: EmailSyncService::class);
        $this->userManager      = $this->createMock(originalClassName: IUserManager::class);
        $this->logger           = $this->createMock(originalClassName: LoggerInterface::class);
    }//end setUp()

    /**
     * Create a job instance under test.
     *
     * @return EmailSyncJob
     */
    private function makeJob(): EmailSyncJob
    {
        return new EmailSyncJob(
            time: $this->timeFactory,
            emailSyncService: $this->emailSyncService,
            userManager: $this->userManager,
            logger: $this->logger,
        );
    }//end makeJob()

    /**
     * Test that users with sync disabled are skipped.
     *
     * @return void
     */
    public function testSkipsUsersWithSyncDisabled(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('user1');

        $this->userManager->method('callForAllUsers')
            ->willReturnCallback(
                function (callable $callback) use ($user): void {
                    $callback($user);
                }
            );

        $this->emailSyncService->method('isSyncEnabled')
            ->with('user1')
            ->willReturn(false);

        $this->emailSyncService->expects($this->never())->method('getSyncAccounts');
        $this->emailSyncService->expects($this->never())->method('updateLastSyncTime');

        $job = $this->makeJob();
        // Invoke via reflection since run() is protected.
        $ref = new \ReflectionMethod($job, 'run');
        $ref->invoke($job, null);
    }//end testSkipsUsersWithSyncDisabled()

    /**
     * Test that users with sync enabled but no accounts are skipped.
     *
     * @return void
     */
    public function testSkipsUsersWithNoAccounts(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('user1');

        $this->userManager->method('callForAllUsers')
            ->willReturnCallback(
                function (callable $callback) use ($user): void {
                    $callback($user);
                }
            );

        $this->emailSyncService->method('isSyncEnabled')
            ->with('user1')
            ->willReturn(true);

        $this->emailSyncService->method('getSyncAccounts')
            ->with('user1')
            ->willReturn([]);

        $this->emailSyncService->expects($this->never())->method('updateLastSyncTime');

        $job = $this->makeJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->invoke($job, null);
    }//end testSkipsUsersWithNoAccounts()

    /**
     * Test that users with sync enabled and accounts get their sync time updated.
     *
     * @return void
     */
    public function testUpdatesSyncTimeForEnabledUser(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('user1');

        $this->userManager->method('callForAllUsers')
            ->willReturnCallback(
                function (callable $callback) use ($user): void {
                    $callback($user);
                }
            );

        $this->emailSyncService->method('isSyncEnabled')
            ->with('user1')
            ->willReturn(true);

        $this->emailSyncService->method('getSyncAccounts')
            ->with('user1')
            ->willReturn([1, 2]);

        $this->emailSyncService->expects($this->once())
            ->method('updateLastSyncTime')
            ->with('user1');

        $job = $this->makeJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->invoke($job, null);
    }//end testUpdatesSyncTimeForEnabledUser()
}//end class
