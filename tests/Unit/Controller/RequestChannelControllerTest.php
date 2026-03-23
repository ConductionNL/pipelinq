<?php

/**
 * Unit tests for RequestChannelController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\RequestChannelController;
use OCA\Pipelinq\Service\SystemTagService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RequestChannelController.
 */
class RequestChannelControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var RequestChannelController
     */
    private RequestChannelController $controller;

    /**
     * Mock system tag service.
     *
     * @var SystemTagService
     */
    private SystemTagService $tagService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $request          = $this->createMock(IRequest::class);
        $this->tagService = $this->createMock(SystemTagService::class);

        $this->controller = new RequestChannelController($request, $this->tagService);
    }//end setUp()

    /**
     * Test index returns tags.
     *
     * @return void
     */
    public function testIndexReturnsTags(): void
    {
        $this->tagService->method('getTags')->willReturn([
            ['id' => 1, 'name' => 'Email'],
        ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }//end testIndexReturnsTags()

    /**
     * Test destroy returns success.
     *
     * @return void
     */
    public function testDestroyReturnsSuccess(): void
    {
        $this->tagService->expects($this->once())->method('removeTag');

        $response = $this->controller->destroy('5');

        $data = $response->getData();
        $this->assertTrue($data['success']);
    }//end testDestroyReturnsSuccess()

    /**
     * Test destroy returns error on exception.
     *
     * @return void
     */
    public function testDestroyReturnsErrorOnException(): void
    {
        $this->tagService->method('removeTag')
            ->willThrowException(new \RuntimeException('Not found'));

        $response = $this->controller->destroy('99');

        $this->assertSame(500, $response->getStatus());
    }//end testDestroyReturnsErrorOnException()
}//end class
