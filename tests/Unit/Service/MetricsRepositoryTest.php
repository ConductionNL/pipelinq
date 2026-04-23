<?php

/**
 * Unit tests for MetricsRepository.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\MetricsRepository;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MetricsRepository.
 */
class MetricsRepositoryTest extends TestCase
{
    /**
     * Test getLeadCounts returns empty on exception.
     *
     * @return void
     */
    public function testGetLeadCountsReturnsEmptyOnException(): void
    {
        $db     = $this->createMock(IDBConnection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $db->method('getQueryBuilder')
            ->willThrowException(new \RuntimeException('DB error'));

        $logger->expects($this->once())->method('warning');

        $repo = new MetricsRepository($db, $logger);
        $this->assertSame([], $repo->getLeadCounts());
    }//end testGetLeadCountsReturnsEmptyOnException()

    /**
     * Test getLeadValueByPipeline returns empty on exception.
     *
     * @return void
     */
    public function testGetLeadValueReturnsEmptyOnException(): void
    {
        $db     = $this->createMock(IDBConnection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $db->method('getQueryBuilder')
            ->willThrowException(new \RuntimeException('DB error'));

        $repo = new MetricsRepository($db, $logger);
        $this->assertSame([], $repo->getLeadValueByPipeline());
    }//end testGetLeadValueReturnsEmptyOnException()

    /**
     * Test countObjectsBySchemaPattern returns 0 on exception.
     *
     * @return void
     */
    public function testCountReturnsZeroOnException(): void
    {
        $db     = $this->createMock(IDBConnection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $db->method('getQueryBuilder')
            ->willThrowException(new \RuntimeException('DB error'));

        $repo = new MetricsRepository($db, $logger);
        $this->assertSame(0, $repo->countObjectsBySchemaPattern('%client%'));
    }//end testCountReturnsZeroOnException()

    /**
     * Test getRequestCounts returns empty on exception.
     *
     * @return void
     */
    public function testGetRequestCountsReturnsEmptyOnException(): void
    {
        $db     = $this->createMock(IDBConnection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $db->method('getQueryBuilder')
            ->willThrowException(new \RuntimeException('DB error'));

        $repo = new MetricsRepository($db, $logger);
        $this->assertSame([], $repo->getRequestCounts());
    }//end testGetRequestCountsReturnsEmptyOnException()
}//end class
