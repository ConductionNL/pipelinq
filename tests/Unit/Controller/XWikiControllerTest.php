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
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnMap([
			['q', null, 'paspoort'],
			['space', null, 'Kennisbank'],
			['tags', null, 'a,b'],
			['limit', null, '5'],
			['offset', null, '0'],
		]);

		$service = $this->createMock(originalClassName: XWikiService::class);
		$service->expects($this->once())
			->method('search')
			->with('paspoort', ['space' => 'Kennisbank', 'tags' => ['a', 'b'], 'limit' => 5, 'offset' => 0])
			->willReturn(['results' => [['id' => '1', 'title' => 'Paspoort']], 'total' => 1, 'limit' => 5, 'offset' => 0]);

		$controller = new XWikiController(request: $request, xwiki: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$response = $controller->search();

		$this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
		$data = $response->getData();
		$this->assertSame(expected: 1, actual: $data['total']);
		$this->assertSame(expected: 'Paspoort', actual: $data['results'][0]['title']);
	}//end testSearchReturnsServicePayload()

	/**
	 * Search degrades to an unavailable envelope when the service throws.
	 *
	 * @return void
	 */
	public function testSearchHandlesServiceFailure(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturn(null);
		$service = $this->createMock(originalClassName: XWikiService::class);
		$service->method('search')->willThrowException(new RuntimeException('boom'));

		$controller = new XWikiController(request: $request, xwiki: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$data = $controller->search()->getData();

		$this->assertSame(expected: 'unavailable', actual: $data['error']);
		$this->assertSame(expected: [], actual: $data['results']);
	}//end testSearchHandlesServiceFailure()

	/**
	 * Pages endpoint delegates with space + paging.
	 *
	 * @return void
	 */
	public function testPagesReturnsServicePayload(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnMap([
			['space', null, 'Kennisbank'],
			['limit', null, '10'],
			['offset', null, '0'],
		]);

		$service = $this->createMock(originalClassName: XWikiService::class);
		$service->expects($this->once())
			->method('getPages')
			->with('Kennisbank', 10, 0)
			->willReturn(['results' => [], 'total' => 0, 'limit' => 10, 'offset' => 0]);

		$controller = new XWikiController(request: $request, xwiki: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$response = $controller->pages();

		$this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
		$this->assertSame(expected: 0, actual: $response->getData()['total']);
	}//end testPagesReturnsServicePayload()

	/**
	 * Page endpoint re-sanitises content.
	 *
	 * @return void
	 */
	public function testPageResanitises(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$service = $this->createMock(originalClassName: XWikiService::class);
		$service->method('getPageContent')
			->with('xwiki', 'Kennisbank.Paspoort')
			->willReturn(
				[
					'title' => 'Paspoort',
					'content' => '<p>ok</p><script>alert(1)</script>',
					'url' => 'http://x',
					'modified' => '',
					'space' => 'Kennisbank',
					'id' => '1',
				]
			);
		$service->expects($this->once())
			->method('sanitiseHtml')
			->willReturn('<p>ok</p>');

		$controller = new XWikiController(request: $request, xwiki: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$data = $controller->page('xwiki', 'Kennisbank.Paspoort')->getData();

		$this->assertSame(expected: '<p>ok</p>', actual: $data['content']);
		$this->assertSame(expected: 'Paspoort', actual: $data['title']);
	}//end testPageResanitises()

	/**
	 * Status endpoint forwards the service payload.
	 *
	 * @return void
	 */
	public function testStatus(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$service = $this->createMock(originalClassName: XWikiService::class);
		$service->method('getStatus')->willReturn(['available' => true, 'version' => '15.10', 'baseUrl' => 'http://xwiki', 'source' => 'direct-url']);

		$controller = new XWikiController(request: $request, xwiki: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$data = $controller->status()->getData();

		$this->assertTrue(condition: $data['available']);
		$this->assertSame(expected: '15.10', actual: $data['version']);
	}//end testStatus()

	/**
	 * Status degrades when the service throws.
	 *
	 * @return void
	 */
	public function testStatusHandlesFailure(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$service = $this->createMock(originalClassName: XWikiService::class);
		$service->method('getStatus')->willThrowException(new RuntimeException('boom'));

		$controller = new XWikiController(request: $request, xwiki: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$data = $controller->status()->getData();

		$this->assertFalse(condition: $data['available']);
		$this->assertSame(expected: 'error', actual: $data['source']);
	}//end testStatusHandlesFailure()
	/**
	 * A failing pages proxy is logged and answers an empty page list marked unavailable.
	 *
	 * @return void
	 */
	public function testPagesHandlesServiceFailure(): void {
		$service = $this->createMock(originalClassName: XWikiService::class);
		$service->method('getPages')->willThrowException(new \RuntimeException('xwiki down'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('xWiki pages proxy failed');
		$controller = new XWikiController(request: $this->createMock(originalClassName: IRequest::class), xwiki: $service, logger: $logger);

		$response = $controller->pages();

		$this->assertSame(['results' => [], 'total' => 0, 'limit' => 10, 'offset' => 0, 'error' => 'unavailable'], $response->getData());
	}//end testPagesHandlesServiceFailure()

	/**
	 * A failing page proxy is logged and answers an empty page marked unavailable.
	 *
	 * @return void
	 */
	public function testPageHandlesServiceFailure(): void {
		$service = $this->createMock(originalClassName: XWikiService::class);
		$service->method('getPageContent')->willThrowException(new \RuntimeException('xwiki down'));
		$service->expects($this->never())->method('sanitiseHtml');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('xWiki page proxy failed');
		$controller = new XWikiController(request: $this->createMock(originalClassName: IRequest::class), xwiki: $service, logger: $logger);

		$response = $controller->page(wiki: 'xwiki', page: 'Main.WebHome');

		$this->assertSame(
			['title' => '', 'content' => '', 'url' => '', 'modified' => '', 'space' => '', 'id' => '', 'error' => 'unavailable'],
			$response->getData()
		);
	}//end testPageHandlesServiceFailure()

}//end class
