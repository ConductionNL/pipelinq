<?php

/**
 * Unit tests for MetricsController.
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

use OCA\Pipelinq\Controller\MetricsController;
use OCA\Pipelinq\Service\MetricsFormatter;
use OCA\Pipelinq\Service\MetricsRepository;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MetricsController.
 */
class MetricsControllerTest extends TestCase
{
    /**
     * Test index returns Prometheus formatted text.
     *
     * @return void
     */
    public function testIndexReturnsPrometheusText(): void
    {
        $request    = $this->createMock(IRequest::class);
        $appManager = $this->createMock(IAppManager::class);
        $repository = $this->createMock(MetricsRepository::class);
        $formatter  = new MetricsFormatter();

        $appManager->method('getAppVersion')->willReturn('1.0.0');
        $repository->method('getLeadCounts')->willReturn([]);
        $repository->method('getLeadValueByPipeline')->willReturn([]);
        $repository->method('countObjectsBySchemaPattern')->willReturn(0);
        $repository->method('getRequestCounts')->willReturn([]);

        $controller = new MetricsController($request, $appManager, $repository, $formatter);
        $response   = $controller->index();

        $this->assertInstanceOf(TextPlainResponse::class, $response);
    }//end testIndexReturnsPrometheusText()
}//end class
