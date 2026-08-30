<?php

/**
 * Unit tests for XWikiController.
 *
 * Verifies that each proxy endpoint delegates to XWikiService, wraps the
 * payload in JSONResponse, and degrades to an `error=unavailable` envelope
 * when the service throws.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#10.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\XWikiController;
use OCA\Pipelinq\Service\XWikiService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for XWikiController.
 */
class XWikiControllerTest extends TestCase {
	/**
	 * Search endpoint returns the service payload.
	 *
	 * @return void
	 */
	public function testSearchReturnsServicePayload(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([
			['q', null, 'paspoort'],
			['space', null, 'Kennisbank'],
			['tags', null, 'a,b'],
			['limit', null, '5'],
			['offset', null, '0'],
		]);

		$service = $this->createMock(XWikiService::class);
		$service->expects($this->once())
			->method('search')
			->with('paspoort', 'Kennisbank', ['a', 'b'], 5, 0)
			->willReturn(['results' => [['id' => '1', 'title' => 'Paspoort']], 'total' => 1, 'limit' => 5, 'offset' => 0]);

		$controller = new XWikiController($request, $service, $this->createMock(LoggerInterface::class));
		$response = $controller->search();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertSame(1, $data['total']);
		$this->assertSame('Paspoort', $data['results'][0]['title']);
	}//end testSearchReturnsServicePayload()

	/**
	 * Search degrades to an unavailable envelope when the service throws.
	 *
	 * @return void
	 */
	public function testSearchHandlesServiceFailure(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$service = $this->createMock(XWikiService::class);
		$service->method('search')->willThrowException(new RuntimeException('boom'));

		$controller = new XWikiController($request, $service, $this->createMock(LoggerInterface::class));
		$data = $controller->search()->getData();

		$this->assertSame('unavailable', $data['error']);
		$this->assertSame([], $data['results']);
	}//end testSearchHandlesServiceFailure()

	/**
	 * Pages endpoint delegates with space + paging.
	 *
	 * @return void
	 */
	public function testPagesReturnsServicePayload(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([
			['space', null, 'Kennisbank'],
			['limit', null, '10'],
			['offset', null, '0'],
		]);

		$service = $this->createMock(XWikiService::class);
		$service->expects($this->once())
			->method('getPages')
			->with('Kennisbank', 10, 0)
			->willReturn(['results' => [], 'total' => 0, 'limit' => 10, 'offset' => 0]);

		$controller = new XWikiController($request, $service, $this->createMock(LoggerInterface::class));
		$response = $controller->pages();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(0, $response->getData()['total']);
	}//end testPagesReturnsServicePayload()

	/**
	 * Page endpoint re-sanitises content.
	 *
	 * @return void
	 */
	public function testPageResanitises(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(XWikiService::class);
		$service->method('getPageContent')
			->with('xwiki', 'Kennisbank.Paspoort')
			->willReturn(['title' => 'Paspoort', 'content' => '<p>ok</p><script>alert(1)</script>', 'url' => 'http://x', 'modified' => '', 'space' => 'Kennisbank', 'id' => '1']);
		$service->expects($this->once())
			->method('sanitiseHtml')
			->willReturn('<p>ok</p>');

		$controller = new XWikiController($request, $service, $this->createMock(LoggerInterface::class));
		$data = $controller->page('xwiki', 'Kennisbank.Paspoort')->getData();

		$this->assertSame('<p>ok</p>', $data['content']);
		$this->assertSame('Paspoort', $data['title']);
	}//end testPageResanitises()

	/**
	 * Status endpoint forwards the service payload.
	 *
	 * @return void
	 */
	public function testStatus(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(XWikiService::class);
		$service->method('getStatus')->willReturn(['available' => true, 'version' => '15.10', 'baseUrl' => 'http://xwiki', 'source' => 'direct-url']);

		$controller = new XWikiController($request, $service, $this->createMock(LoggerInterface::class));
		$data = $controller->status()->getData();

		$this->assertTrue($data['available']);
		$this->assertSame('15.10', $data['version']);
	}//end testStatus()

	/**
	 * Status degrades when the service throws.
	 *
	 * @return void
	 */
	public function testStatusHandlesFailure(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(XWikiService::class);
		$service->method('getStatus')->willThrowException(new RuntimeException('boom'));

		$controller = new XWikiController($request, $service, $this->createMock(LoggerInterface::class));
		$data = $controller->status()->getData();

		$this->assertFalse($data['available']);
		$this->assertSame('error', $data['source']);
	}//end testStatusHandlesFailure()
}//end class
