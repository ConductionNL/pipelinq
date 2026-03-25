<?php

/**
 * Unit tests for CallbackOverdueJob.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\CallbackOverdueJob;
use OCA\Pipelinq\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CallbackOverdueJob.
 */
class CallbackOverdueJobTest extends TestCase
{
    /**
     * Mock time factory.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * Mock app config.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * Mock notification service.
     *
     * @var NotificationService&MockObject
     */
    private NotificationService $notificationService;

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
        $this->timeFactory         = $this->createMock(ITimeFactory::class);
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->timeFactory->method('getTime')->willReturn(time());
    }//end setUp()

    /**
     * Build the job under test.
     *
     * @return CallbackOverdueJob
     */
    private function buildJob(): CallbackOverdueJob
    {
        return new CallbackOverdueJob(
            $this->timeFactory,
            $this->appConfig,
            $this->notificationService,
            $this->logger,
        );
    }//end buildJob()

    /**
     * Test that the job can be instantiated.
     *
     * @return void
     */
    public function testJobCanBeInstantiated(): void
    {
        $this->assertInstanceOf(CallbackOverdueJob::class, $this->buildJob());
    }//end testJobCanBeInstantiated()

    /**
     * Test that the job skips when register is not configured.
     *
     * @return void
     */
    public function testJobSkipsWhenRegisterNotConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturnMap([
            [Application::APP_ID, 'register', '', ''],
            [Application::APP_ID, 'task_schema', '', ''],
        ]);

        $this->notificationService->expects($this->never())->method($this->anything());

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSkipsWhenRegisterNotConfigured()

    /**
     * Test that the job skips when task_schema is not configured.
     *
     * @return void
     */
    public function testJobSkipsWhenTaskSchemaNotConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturnMap([
            [Application::APP_ID, 'register', '', 'register-uuid'],
            [Application::APP_ID, 'task_schema', '', ''],
        ]);

        $this->notificationService->expects($this->never())->method($this->anything());

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSkipsWhenTaskSchemaNotConfigured()

    /**
     * Test that the job logs start and completion when fully configured.
     *
     * @return void
     */
    public function testJobLogsStartAndCompletionWithConfig(): void
    {
        $this->appConfig->method('getValueString')->willReturnMap([
            [Application::APP_ID, 'register', '', 'register-uuid'],
            [Application::APP_ID, 'task_schema', '', 'schema-uuid'],
        ]);

        $this->logger->expects($this->atLeastOnce())->method('info');

        $job = $this->buildJob();
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobLogsStartAndCompletionWithConfig()

    /**
     * Test wasRecentlyNotified returns false for unknown task.
     *
     * @return void
     */
    public function testWasRecentlyNotifiedReturnsFalseForUnknown(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $job = $this->buildJob();
        $this->assertFalse($job->wasRecentlyNotified('unknown-task'));
    }//end testWasRecentlyNotifiedReturnsFalseForUnknown()

    /**
     * Test wasRecentlyNotified returns true for recently notified task.
     *
     * @return void
     */
    public function testWasRecentlyNotifiedReturnsTrueForRecent(): void
    {
        $recentTime = (string) (time() - 3600);
        $this->appConfig->method('getValueString')->willReturn($recentTime);

        $job = $this->buildJob();
        $this->assertTrue($job->wasRecentlyNotified('task-123'));
    }//end testWasRecentlyNotifiedReturnsTrueForRecent()

    /**
     * Test wasRecentlyNotified returns false for old notification.
     *
     * @return void
     */
    public function testWasRecentlyNotifiedReturnsFalseForOld(): void
    {
        $oldTime = (string) (time() - 100000);
        $this->appConfig->method('getValueString')->willReturn($oldTime);

        $job = $this->buildJob();
        $this->assertFalse($job->wasRecentlyNotified('task-123'));
    }//end testWasRecentlyNotifiedReturnsFalseForOld()
}//end class
