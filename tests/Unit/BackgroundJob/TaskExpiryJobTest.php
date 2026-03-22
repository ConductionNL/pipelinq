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
    /** @var ITimeFactory&MockObject */
    private ITimeFactory $timeFactory;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    /** @var NotificationService&MockObject */
    private NotificationService $notificationService;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->timeFactory         = $this->createMock(ITimeFactory::class);
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->timeFactory->method('getTime')->willReturn(time());
    }//end setUp()

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
     *
     * @return void
     */
    public function testJobCanBeInstantiated(): void
    {
        $this->assertInstanceOf(TaskExpiryJob::class, $this->buildJob());
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

        $ref = new \ReflectionMethod($this->buildJob(), 'run');
        $ref->setAccessible(true);
        $ref->invoke($this->buildJob(), null);
    }//end testJobSkipsWhenRegisterNotConfigured()

    /**
     * Test that the job logs start/completion when fully configured.
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
}//end class
