<?php

/**
 * Unit tests for XWikiController.
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
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-10.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\XWikiController;
use OCA\Pipelinq\Service\XWikiService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for XWikiController.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-10.2
 */
class XWikiControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var XWikiController
     */
    private XWikiController $controller;

    /**
     * Mock xWiki service.
     *
     * @var XWikiService
     */
    private XWikiService $xwikiService;

    /**
     * Mock request.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->xwikiService = $this->createMock(XWikiService::class);

        $this->controller = new XWikiController(
            $this->request,
            $this->xwikiService,
        );
    }//end setUp()

    // -------------------------------------------------------------------------
    // status()
    // -------------------------------------------------------------------------

    /**
     * status() returns JSONResponse with available=true.
     *
     * @return void
     */
    public function testStatusReturnsAvailableTrue(): void
    {
        $this->xwikiService->method('getStatus')->willReturn([
            'available' => true,
            'version'   => '16.0.0',
            'url'       => 'http://localhost:8088',
        ]);

        $response = $this->controller->status();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['available']);
        $this->assertSame('16.0.0', $data['version']);
    }//end testStatusReturnsAvailableTrue()

    /**
     * status() returns JSONResponse with available=false when service says so.
     *
     * @return void
     */
    public function testStatusReturnsAvailableFalse(): void
    {
        $this->xwikiService->method('getStatus')->willReturn([
            'available' => false,
            'error'     => 'Could not reach xWiki instance',
        ]);

        $response = $this->controller->status();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertFalse($data['available']);
    }//end testStatusReturnsAvailableFalse()

    // -------------------------------------------------------------------------
    // search()
    // -------------------------------------------------------------------------

    /**
     * search() delegates to xWikiService and returns 200.
     *
     * @return void
     */
    public function testSearchDelegatesToService(): void
    {
        $this->request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'q'      => 'paspoort',
                    'space'  => 'Kennisbank',
                    'tags'   => '',
                    'limit'  => 10,
                    'offset' => 0,
                    default  => $default,
                };
            });

        $this->xwikiService->method('search')->willReturn([
            'results' => [
                ['id' => 'xwiki:KB.Paspoort', 'title' => 'Paspoort aanvragen', 'space' => 'Kennisbank', 'modified' => '', 'url' => ''],
            ],
            'total'   => 1,
            'limit'   => 10,
            'offset'  => 0,
            'x_cache' => 'MISS',
        ]);

        $response = $this->controller->search();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(1, $data['results']);
        $this->assertSame('Paspoort aanvragen', $data['results'][0]['title']);
        // x_cache should be stripped from response body.
        $this->assertArrayNotHasKey('x_cache', $data);
    }//end testSearchDelegatesToService()

    // -------------------------------------------------------------------------
    // pages()
    // -------------------------------------------------------------------------

    /**
     * pages() returns 400 when space parameter is missing.
     *
     * @return void
     */
    public function testPagesReturnsBadRequestWhenSpaceMissing(): void
    {
        $this->request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'space'  => '',
                    'limit'  => 20,
                    'offset' => 0,
                    default  => $default,
                };
            });

        $response = $this->controller->pages();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testPagesReturnsBadRequestWhenSpaceMissing()

    /**
     * pages() delegates to xWikiService and returns 200.
     *
     * @return void
     */
    public function testPagesDelegatesToService(): void
    {
        $this->request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'space'  => 'Kennisbank',
                    'limit'  => 20,
                    'offset' => 0,
                    default  => $default,
                };
            });

        $this->xwikiService->method('getPages')->willReturn([
            'results' => [
                ['id' => 'xwiki:KB.Paspoort', 'title' => 'Paspoort', 'url' => ''],
            ],
            'total'   => 1,
            'limit'   => 20,
            'offset'  => 0,
            'x_cache' => 'MISS',
        ]);

        $response = $this->controller->pages();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
    }//end testPagesDelegatesToService()

    // -------------------------------------------------------------------------
    // page()
    // -------------------------------------------------------------------------

    /**
     * page() returns page content on success.
     *
     * @return void
     */
    public function testPageReturnsContent(): void
    {
        $this->xwikiService->method('getPageContent')->willReturn([
            'id'       => 'xwiki:Kennisbank.Paspoort',
            'title'    => 'Paspoort aanvragen',
            'content'  => '<p>Informatie over paspoort.</p>',
            'space'    => 'Kennisbank',
            'modified' => '',
            'url'      => 'http://localhost:8088/xwiki/bin/view/Kennisbank/Paspoort',
        ]);

        $response = $this->controller->page('xwiki', 'Kennisbank.Paspoort');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertSame('Paspoort aanvragen', $data['title']);
        $this->assertStringContainsString('<p>', $data['content']);
    }//end testPageReturnsContent()

    /**
     * page() returns 404 when page is not found.
     *
     * @return void
     */
    public function testPageReturns404WhenNotFound(): void
    {
        $this->xwikiService->method('getPageContent')->willReturn([
            'error'   => 'Page not found',
            'content' => '',
        ]);

        $response = $this->controller->page('xwiki', 'Nonexistent.Page');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testPageReturns404WhenNotFound()

    /**
     * page() returns 503 when xWiki is not configured.
     *
     * @return void
     */
    public function testPageReturns503WhenNotConfigured(): void
    {
        $this->xwikiService->method('getPageContent')->willReturn([
            'error'   => 'xWiki URL not configured',
            'content' => '',
        ]);

        $response = $this->controller->page('xwiki', 'Some.Page');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
    }//end testPageReturns503WhenNotConfigured()
}//end class
