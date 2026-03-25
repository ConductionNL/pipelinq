<?php

/**
 * Unit tests for QueueService.
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
use OCA\Pipelinq\Service\QueueService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for QueueService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class QueueServiceTest extends TestCase
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
     * Set up test fixtures.
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
     * @return QueueService
     */
    private function buildService(): QueueService
    {
        return new QueueService(
            appConfig: $this->appConfig,
            container: $this->container,
            logger: $this->logger,
        );
    }//end buildService()

    /**
     * Configure app config to return register and request schema IDs.
     *
     * @param string $register      The register ID.
     * @param string $requestSchema The request schema ID.
     * @param string $queueSchema   The queue schema ID.
     *
     * @return void
     */
    private function configureAppConfig(
        string $register = 'reg-id',
        string $requestSchema = 'req-schema-id',
        string $queueSchema = 'queue-schema-id',
    ): void {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', $register],
                [Application::APP_ID, 'request_schema', '', $requestSchema],
                [Application::APP_ID, 'queue_schema', '', $queueSchema],
            ]);
    }//end configureAppConfig()

    /**
     * Create a mock ObjectService.
     *
     * @return MockObject
     */
    private function createObjectServiceMock(): MockObject
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findAll', 'saveObject'])
            ->getMock();

        $this->container->method('get')->willReturn($mock);

        return $mock;
    }//end createObjectServiceMock()

    /**
     * Test getQueueDepth returns zero when register is not configured.
     *
     * @return void
     */
    public function testGetQueueDepthReturnsZeroWhenNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', ''],
                [Application::APP_ID, 'request_schema', '', ''],
                [Application::APP_ID, 'queue_schema', '', ''],
            ]);

        $this->logger->expects($this->once())->method('warning');

        $result = $this->buildService()->getQueueDepth('some-queue-id');
        $this->assertSame(0, $result);
    }//end testGetQueueDepthReturnsZeroWhenNotConfigured()

    /**
     * Test getQueueDepth returns item count from ObjectService.
     *
     * @return void
     */
    public function testGetQueueDepthReturnsItemCount(): void
    {
        $this->configureAppConfig();

        $objectService = $this->createObjectServiceMock();
        $objectService->method('findAll')->willReturn([
            ['id' => 'item-1'],
            ['id' => 'item-2'],
            ['id' => 'item-3'],
        ]);

        $result = $this->buildService()->getQueueDepth('queue-123');
        $this->assertSame(3, $result);
    }//end testGetQueueDepthReturnsItemCount()

    /**
     * Test getQueueDepth returns zero on exception.
     *
     * @return void
     */
    public function testGetQueueDepthReturnsZeroOnException(): void
    {
        $this->configureAppConfig();

        $this->container->method('get')->willThrowException(new \RuntimeException('service unavailable'));
        $this->logger->expects($this->once())->method('error');

        $result = $this->buildService()->getQueueDepth('queue-123');
        $this->assertSame(0, $result);
    }//end testGetQueueDepthReturnsZeroOnException()

    /**
     * Test isAtCapacity returns false when maxCapacity is null.
     *
     * @return void
     */
    public function testIsAtCapacityReturnsFalseWhenNoLimit(): void
    {
        $queue = ['id' => 'q1', 'maxCapacity' => null];

        $result = $this->buildService()->isAtCapacity($queue, 100);
        $this->assertFalse($result);
    }//end testIsAtCapacityReturnsFalseWhenNoLimit()

    /**
     * Test isAtCapacity returns true when at capacity.
     *
     * @return void
     */
    public function testIsAtCapacityReturnsTrueWhenAtLimit(): void
    {
        $queue = ['id' => 'q1', 'maxCapacity' => 50];

        $result = $this->buildService()->isAtCapacity($queue, 50);
        $this->assertTrue($result);
    }//end testIsAtCapacityReturnsTrueWhenAtLimit()

    /**
     * Test isAtCapacity returns true when over capacity.
     *
     * @return void
     */
    public function testIsAtCapacityReturnsTrueWhenOverLimit(): void
    {
        $queue = ['id' => 'q1', 'maxCapacity' => 50];

        $result = $this->buildService()->isAtCapacity($queue, 55);
        $this->assertTrue($result);
    }//end testIsAtCapacityReturnsTrueWhenOverLimit()

    /**
     * Test isAtCapacity returns false when under capacity.
     *
     * @return void
     */
    public function testIsAtCapacityReturnsFalseWhenUnderLimit(): void
    {
        $queue = ['id' => 'q1', 'maxCapacity' => 50];

        $result = $this->buildService()->isAtCapacity($queue, 30);
        $this->assertFalse($result);
    }//end testIsAtCapacityReturnsFalseWhenUnderLimit()

    /**
     * Test assignToQueue calls saveObject with correct queue field.
     *
     * @return void
     */
    public function testAssignToQueueUpdatesSaveObject(): void
    {
        $this->configureAppConfig();

        $objectService = $this->createObjectServiceMock();
        $objectService
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['id'] === 'request-123' && $data['queue'] === 'queue-456';
                }),
                $this->anything(),
                $this->equalTo('reg-id'),
                $this->equalTo('req-schema-id'),
            );

        $result = $this->buildService()->assignToQueue('request-123', 'queue-456');
        $this->assertTrue($result);
    }//end testAssignToQueueUpdatesSaveObject()

    /**
     * Test removeFromQueue clears the queue field.
     *
     * @return void
     */
    public function testRemoveFromQueueClearsQueueField(): void
    {
        $this->configureAppConfig();

        $objectService = $this->createObjectServiceMock();
        $objectService
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['id'] === 'request-123' && $data['queue'] === null;
                }),
                $this->anything(),
                $this->equalTo('reg-id'),
                $this->equalTo('req-schema-id'),
            );

        $result = $this->buildService()->removeFromQueue('request-123');
        $this->assertTrue($result);
    }//end testRemoveFromQueueClearsQueueField()

    /**
     * Test assignToQueue returns false when config is missing.
     *
     * @return void
     */
    public function testAssignToQueueReturnsFalseWhenNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', ''],
                [Application::APP_ID, 'request_schema', '', ''],
                [Application::APP_ID, 'queue_schema', '', ''],
            ]);

        $result = $this->buildService()->assignToQueue('req-1', 'queue-1');
        $this->assertFalse($result);
    }//end testAssignToQueueReturnsFalseWhenNotConfigured()

    /**
     * Test assignToQueue returns false on exception.
     *
     * @return void
     */
    public function testAssignToQueueReturnsFalseOnException(): void
    {
        $this->configureAppConfig();

        $this->container->method('get')->willThrowException(new \RuntimeException('fail'));
        $this->logger->expects($this->once())->method('error');

        $result = $this->buildService()->assignToQueue('req-1', 'queue-1');
        $this->assertFalse($result);
    }//end testAssignToQueueReturnsFalseOnException()

    /**
     * Test processOverflow returns zero when not configured.
     *
     * @return void
     */
    public function testProcessOverflowReturnsZeroWhenNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'register', '', ''],
                [Application::APP_ID, 'request_schema', '', ''],
                [Application::APP_ID, 'queue_schema', '', ''],
            ]);

        $this->logger->expects($this->once())->method('warning');

        $result = $this->buildService()->processOverflow();
        $this->assertSame(0, $result);
    }//end testProcessOverflowReturnsZeroWhenNotConfigured()
}//end class
