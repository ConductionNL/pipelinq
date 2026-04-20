<?php

/**
 * Unit tests for DashboardController.
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

use OCA\Pipelinq\Controller\DashboardController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DashboardController.
 */
class DashboardControllerTest extends TestCase
{
    /**
     * Test page returns TemplateResponse.
     *
     * @return void
     */
    public function testPageReturnsTemplateResponse(): void
    {
        $request    = $this->createMock(IRequest::class);
        $controller = new DashboardController($request);

        $response = $controller->page();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('index', $response->getTemplateName());
    }//end testPageReturnsTemplateResponse()
}//end class
