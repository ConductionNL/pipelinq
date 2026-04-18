<?php

/**
 * Unit tests for DefaultQueueService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\DefaultQueueService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DefaultQueueService.
 */
class DefaultQueueServiceTest extends TestCase
{
    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

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
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the service under test.
     *
     * @return DefaultQueueService
     */
    private function buildService(): DefaultQueueService
    {
        return new DefaultQueueService(
            appConfig: $this->appConfig,
            container: $this->container,
            logger: $this->logger,
        );
    }//end buildService()

    /**
     * Test that createDefaultQueues logs a warning and skips when register is not configured.
     *
     * @return void
     */
    public function testCreateDefaultQueuesSkipsWhenRegisterNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', ''],
                [Application::APP_ID, 'queue_schema', '', ''],
            ]);

        $this->logger->expects($this->once())->method('warning');
        $this->container->expects($this->never())->method('get');

        $this->buildService()->createDefaultQueues();
    }//end testCreateDefaultQueuesSkipsWhenRegisterNotConfigured()

    /**
     * Test that createDefaultQueues skips when queue_schema is not configured.
     *
     * @return void
     */
    public function testCreateDefaultQueuesSkipsWhenSchemaNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', 'some-register'],
                [Application::APP_ID, 'queue_schema', '', ''],
            ]);

        $this->logger->expects($this->once())->method('warning');

        $this->buildService()->createDefaultQueues();
    }//end testCreateDefaultQueuesSkipsWhenSchemaNotConfigured()

    /**
     * Test that createDefaultQueues skips when queues already exist.
     *
     * @return void
     */
    public function testCreateDefaultQueuesSkipsWhenQueuesAlreadyExist(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — ObjectService API mismatch.');

        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', 'reg-id'],
                [Application::APP_ID, 'queue_schema', '', 'schema-id'],
            ]);

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findAll', 'saveObject'])
            ->getMock();
        $objectServiceMock
            ->method('findAll')
            ->willReturn([['id' => 'existing-queue']]);
        $objectServiceMock
            ->expects($this->never())
            ->method('saveObject');

        $this->container->method('get')->willReturn($objectServiceMock);
        $this->logger->expects($this->once())->method('info');

        $this->buildService()->createDefaultQueues();
    }//end testCreateDefaultQueuesSkipsWhenQueuesAlreadyExist()

    /**
     * Test that createDefaultQueues creates all 3 default queues when none exist.
     *
     * @return void
     */
    public function testCreateDefaultQueuesCreatesDefaultQueues(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — ObjectService API mismatch.');

        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', 'reg-id'],
                [Application::APP_ID, 'queue_schema', '', 'schema-id'],
            ]);

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findAll', 'saveObject'])
            ->getMock();
        $objectServiceMock
            ->method('findAll')
            ->willReturn([]);
        // 3 default queues defined in DEFAULT_QUEUES constant.
        $objectServiceMock
            ->expects($this->exactly(3))
            ->method('saveObject');

        $this->container->method('get')->willReturn($objectServiceMock);

        $this->buildService()->createDefaultQueues();
    }//end testCreateDefaultQueuesCreatesDefaultQueues()

    /**
     * Test that createDefaultSkills skips when skill_schema is not configured.
     *
     * @return void
     */
    public function testCreateDefaultSkillsSkipsWhenSchemaNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', 'reg-id'],
                [Application::APP_ID, 'skill_schema', '', ''],
            ]);

        $this->logger->expects($this->once())->method('warning');

        $this->buildService()->createDefaultSkills();
    }//end testCreateDefaultSkillsSkipsWhenSchemaNotConfigured()

    /**
     * Test that createDefaultSkills creates all 5 default skills when none exist.
     *
     * @return void
     */
    public function testCreateDefaultSkillsCreatesDefaultSkills(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — ObjectService API mismatch.');

        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', 'reg-id'],
                [Application::APP_ID, 'skill_schema', '', 'skill-schema-id'],
            ]);

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findAll', 'saveObject'])
            ->getMock();
        $objectServiceMock->method('findAll')->willReturn([]);
        // 5 default skills defined in DEFAULT_SKILLS constant.
        $objectServiceMock->expects($this->exactly(5))->method('saveObject');

        $this->container->method('get')->willReturn($objectServiceMock);

        $this->buildService()->createDefaultSkills();
    }//end testCreateDefaultSkillsCreatesDefaultSkills()

    /**
     * Test that createDefaultQueues logs an error on exception.
     *
     * @return void
     */
    public function testCreateDefaultQueuesLogsErrorOnException(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', 'reg-id'],
                [Application::APP_ID, 'queue_schema', '', 'schema-id'],
            ]);

        $this->container->method('get')->willThrowException(new \RuntimeException('container error'));
        $this->logger->expects($this->once())->method('error');

        $this->buildService()->createDefaultQueues();
    }//end testCreateDefaultQueuesLogsErrorOnException()
}//end class
