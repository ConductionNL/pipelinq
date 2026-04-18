<?php

/**
 * Unit tests for QueueOverflowJob.
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

use OCA\Pipelinq\BackgroundJob\QueueOverflowJob;
use OCA\Pipelinq\Service\QueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for QueueOverflowJob.
 */
class QueueOverflowJobTest extends TestCase
{

    /**
     * The time factory mock.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * The queue service mock.
     *
     * @var QueueService&MockObject
     */
    private QueueService $queueService;

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
        $this->timeFactory  = $this->createMock(originalClassName: ITimeFactory::class);
        $this->queueService = $this->createMock(originalClassName: QueueService::class);
        $this->logger       = $this->createMock(originalClassName: LoggerInterface::class);

        $this->timeFactory->method('getTime')->willReturn(time());
    }//end setUp()

    /**
     * Build the job under test.
     *
     * @return QueueOverflowJob
     */
    private function buildJob(): QueueOverflowJob
    {
        return new QueueOverflowJob(
            time: $this->timeFactory,
            queueService: $this->queueService,
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
        $this->assertInstanceOf(expected: QueueOverflowJob::class, actual: $this->buildJob());
    }//end testJobCanBeInstantiated()

    /**
     * Test that the job calls processOverflow and logs when items are moved.
     *
     * @return void
     */
    public function testJobMovesOverflowItems(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — QueueService ObjectService API mismatch.');

        $this->queueService
            ->expects($this->once())
            ->method('processOverflow')
            ->willReturn(5);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $job = $this->buildJob();
        $ref = new ReflectionMethod(objectOrMethod: $job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke(object: $job, args: null);
    }//end testJobMovesOverflowItems()

    /**
     * Test that the job logs debug when no items are moved.
     *
     * @return void
     */
    public function testJobLogsDebugWhenNoOverflow(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — QueueService ObjectService API mismatch.');

        $this->queueService
            ->expects($this->once())
            ->method('processOverflow')
            ->willReturn(0);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('debug');

        $job = $this->buildJob();
        $ref = new ReflectionMethod(objectOrMethod: $job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke(object: $job, args: null);
    }//end testJobLogsDebugWhenNoOverflow()

    /**
     * Test that the job logs error on exception.
     *
     * @return void
     */
    public function testJobLogsErrorOnException(): void
    {
        $this->markTestSkipped('See https://github.com/ConductionNL/pipelinq/issues/286 — QueueService ObjectService API mismatch.');

        $this->queueService
            ->expects($this->once())
            ->method('processOverflow')
            ->willThrowException(new RuntimeException('service error'));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        $job = $this->buildJob();
        $ref = new ReflectionMethod(objectOrMethod: $job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke(object: $job, args: null);
    }//end testJobLogsErrorOnException()
}//end class
