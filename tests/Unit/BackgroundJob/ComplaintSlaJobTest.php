<?php

/**
 * Unit tests for ComplaintSlaJob.
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
use OCA\Pipelinq\BackgroundJob\ComplaintSlaJob;
use OCA\Pipelinq\Service\ComplaintSlaService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ComplaintSlaJob.
 */
class ComplaintSlaJobTest extends TestCase
{
    /**
     * The time factory mock.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * The complaint SLA service mock.
     *
     * @var ComplaintSlaService&MockObject
     */
    private ComplaintSlaService $complaintSlaService;

    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * The container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * Set up the test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->timeFactory         = $this->createMock(ITimeFactory::class);
        $this->complaintSlaService = $this->createMock(ComplaintSlaService::class);
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->container           = $this->createMock(ContainerInterface::class);

        $this->timeFactory->method('getTime')->willReturn(time());
    }//end setUp()

    /**
     * Build the job under test.
     *
     * @return ComplaintSlaJob
     */
    private function buildJob(): ComplaintSlaJob
    {
        return new ComplaintSlaJob(
            $this->timeFactory,
            $this->complaintSlaService,
            $this->appConfig,
            $this->logger,
            $this->container,
        );
    }//end buildJob()

    /**
     * Test that the job can be instantiated.
     *
     * @return void
     */
    public function testJobCanBeInstantiated(): void
    {
        $job = $this->buildJob();

        $this->assertInstanceOf(ComplaintSlaJob::class, $job);
    }//end testJobCanBeInstantiated()

    /**
     * Test that the job skips when register is not configured.
     *
     * @return void
     */
    public function testJobSkipsWhenRegisterNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', ''],
                [Application::APP_ID, 'complaint_schema', '', ''],
            ]);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Skipping'));

        $job = $this->buildJob();

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSkipsWhenRegisterNotConfigured()

    /**
     * Test that the job skips when complaint_schema is not configured.
     *
     * @return void
     */
    public function testJobSkipsWhenComplaintSchemaNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', 'some-register-id'],
                [Application::APP_ID, 'complaint_schema', '', ''],
            ]);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Skipping'));

        $job = $this->buildJob();

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobSkipsWhenComplaintSchemaNotConfigured()

    /**
     * Test that the job logs start and completion when fully configured.
     *
     * @return void
     */
    public function testJobLogsStartAndCompletionWithConfig(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', 'register-uuid'],
                [Application::APP_ID, 'complaint_schema', '', 'schema-uuid'],
            ]);

        $this->logger
            ->expects($this->exactly(2))
            ->method('info')
            ->with($this->logicalOr(
                $this->stringContains('Starting'),
                $this->stringContains('completed'),
            ));

        $job = $this->buildJob();

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);
    }//end testJobLogsStartAndCompletionWithConfig()
}//end class
