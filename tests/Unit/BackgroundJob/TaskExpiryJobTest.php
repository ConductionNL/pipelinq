<?php

/**
 * Unit tests for TaskExpiryJob.
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
use OCA\Pipelinq\BackgroundJob\TaskExpiryJob;
use OCA\Pipelinq\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TaskExpiryJob.
 */
class TaskExpiryJobTest extends TestCase
{
    /**
     * The time factory mock.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The notification service mock.
     *
     * @var NotificationService&MockObject
     */
    private NotificationService $notificationService;

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
        $this->timeFactory         = $this->createMock(ITimeFactory::class);
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->timeFactory->method('getTime')->willReturn(time());
    }//end setUp()

    /**
     * Build the job under test.
     *
     * @return TaskExpiryJob
     */
    private function buildJob(): TaskExpiryJob
    {
        return new TaskExpiryJob(
            time: $this->timeFactory,
            appConfig: $this->appConfig,
            notificationService: $this->notificationService,
            logger: $this->logger,
        );
    }//end buildJob()

    /**
     * Test that the job can be instantiated.
     * Test that the job can be instantiated without errors.
     *
     * @return void
     */
    public function testJobCanBeInstantiated(): void
    {
        $this->assertInstanceOf(TaskExpiryJob::class, $this->buildJob());
    }//end testJobCanBeInstantiated()

    /**
     * Test that the job skips when register is not configured.
        $job = $this->buildJob();

        $this->assertInstanceOf(TaskExpiryJob::class, $job);
    }//end testJobCanBeInstantiated()

    /**
     * Test that the job skips processing when register is not configured.
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

        $ref = new \ReflectionMethod($this->buildJob(), 'run');
        $ref->setAccessible(true);
        $ref->invoke($this->buildJob(), null);
    }//end testJobSkipsWhenRegisterNotConfigured()

    /**
     * Test that the job logs start/completion when fully configured.
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', ''],
                [Application::APP_ID, 'task_schema', '', ''],
            ]);

        // No notification should be sent if config is missing.
        $this->notificationService
            ->expects($this->never())
            ->method($this->anything());

        $job = $this->buildJob();

        // Call the protected run() via reflection to test in isolation.
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSkipsWhenRegisterNotConfigured()

    /**
     * Test that the job skips processing when task_schema is not configured.
     *
     * @return void
     */
    public function testJobSkipsWhenTaskSchemaNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', 'some-register-id'],
                [Application::APP_ID, 'task_schema', '', ''],
            ]);

        $this->notificationService
            ->expects($this->never())
            ->method($this->anything());

        $job = $this->buildJob();

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSkipsWhenTaskSchemaNotConfigured()

    /**
     * Test that the job logs its start and completion when config is present.
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
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', 'register-uuid'],
                [Application::APP_ID, 'task_schema', '', 'schema-uuid'],
            ]);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $job = $this->buildJob();

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobLogsStartAndCompletionWithConfig()
}//end class
