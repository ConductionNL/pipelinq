<?php

/**
 * Unit tests for HealthController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\HealthController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for HealthController.
 */
class HealthControllerTest extends TestCase
{
    /**
     * Test index returns ok status when healthy.
     *
     * @return void
     */
    public function testIndexReturnsOkWhenHealthy(): void
    {
        $request    = $this->createMock(IRequest::class);
        $db         = $this->createMock(IDBConnection::class);
        $appManager = $this->createMock(IAppManager::class);
        $logger     = $this->createMock(LoggerInterface::class);

        // Mock the query builder chain.
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('closeCursor');

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('createFunction')->willReturn('1');
        $qb->method('executeQuery')->willReturn($result);

        $db->method('getQueryBuilder')->willReturn($qb);
        $appManager->method('getAppVersion')->willReturn('1.0.0');

        $controller = new HealthController($request, $db, $appManager, $logger);
        $response   = $controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertSame('ok', $data['status']);
        $this->assertSame('1.0.0', $data['version']);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testIndexReturnsOkWhenHealthy()

    /**
     * Test index returns error status when database fails.
     *
     * @return void
     */
    public function testIndexReturnsErrorWhenDbFails(): void
    {
        $request    = $this->createMock(IRequest::class);
        $db         = $this->createMock(IDBConnection::class);
        $appManager = $this->createMock(IAppManager::class);
        $logger     = $this->createMock(LoggerInterface::class);

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('createFunction')->willReturn('1');
        $qb->method('executeQuery')->willThrowException(new \RuntimeException('DB error'));

        $db->method('getQueryBuilder')->willReturn($qb);
        $appManager->method('getAppVersion')->willReturn('1.0.0');

        $controller = new HealthController($request, $db, $appManager, $logger);
        $response   = $controller->index();

        $data = $response->getData();
        $this->assertSame('error', $data['status']);
        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
    }//end testIndexReturnsErrorWhenDbFails()
}//end class
