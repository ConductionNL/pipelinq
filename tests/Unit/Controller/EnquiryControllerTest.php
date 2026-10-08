<?php

/**
 * Verifies the public enquiry intake endpoint's HTTP contract.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Controller\EnquiryController;
use OCA\Pipelinq\Service\EnquiryIntakeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The endpoint is anonymous and cross-origin, so its status codes and its CORS
 * headers ARE its contract: a visitor who submitted successfully is told it
 * failed if the header is missing, and a refusal that leaks which rule it hit
 * lets a caller map the allowlist.
 */
class EnquiryControllerTest extends TestCase {

	private IRequest $request;

	private EnquiryIntakeService $intake;

	/**
	 * Build the controller with a request that reports the given params/Origin.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param string               $origin The Origin header value.
	 *
	 * @return EnquiryController The controller under test.
	 */
	private function controller(array $params = [], string $origin = ''): EnquiryController {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn($params);
		$this->request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Origin' ? $origin : '')
		);

		$this->intake = $this->createMock(EnquiryIntakeService::class);

		return new EnquiryController(
			$this->request,
			$this->intake,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * The headers this response had ADDED to it.
	 *
	 * Read by reflection on purpose. `Response::getHeaders()` resolves an
	 * IRequest out of `Server::get()` to merge in X-Request-Id and the CSP,
	 * which needs the `OC` runtime the unit suite is deliberately isolated
	 * from. The stored property holds exactly what the controller set, which
	 * is the thing under test.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response.
	 *
	 * @return array<string, string> The added headers.
	 */
	private function addedHeaders(\OCP\AppFramework\Http\Response $response): array {
		$property = (new \ReflectionClass(\OCP\AppFramework\Http\Response::class))->getProperty('headers');
		$property->setAccessible(true);

		return (array)$property->getValue($response);
	}//end addedHeaders()

	/**
	 * A stored enquiry answers 201 with its id.
	 *
	 * @return void
	 */
	public function testAcceptedSubmissionReturns201WithTheId(): void {
		$controller = $this->controller(['title' => 'Hi', 'source' => 'website-contact']);
		$this->intake->method('submit')->willReturn('uuid-1');

		$response = $controller->submit();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'uuid-1'], $response->getData());
	}//end testAcceptedSubmissionReturns201WithTheId()

	/**
	 * The controller hands the service the WHOLE request body. If it ever
	 * bound named parameters again there would be two field lists to keep in
	 * step, which is the defect the single whitelist exists to prevent.
	 *
	 * @return void
	 */
	public function testPassesTheWholeRequestBodyToTheService(): void {
		$params = ['title' => 'Hi', 'source' => 'website-contact', 'status' => 'converted'];
		$controller = $this->controller($params);
		$this->intake->expects($this->once())
			->method('submit')
			->with($params)
			->willReturn('uuid-1');

		$controller->submit();
	}//end testPassesTheWholeRequestBodyToTheService()

	/**
	 * A refusal is a 400, and says nothing about WHICH rule refused.
	 *
	 * @return void
	 */
	public function testRefusalReturns400WithAnOpaqueMessage(): void {
		$controller = $this->controller(['source' => 'nope']);
		$this->intake->method('submit')
			->willThrowException(new InvalidArgumentException('unknown source: nope'));

		$response = $controller->submit();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$body = $response->getData();
		$this->assertStringNotContainsStringIgnoringCase(
			'source',
			(string)$body['error'],
			'The refusal must not name the rule it hit, or a caller can map the allowlist'
		);
		$this->assertStringNotContainsStringIgnoringCase('honeypot', (string)$body['error']);
	}//end testRefusalReturns400WithAnOpaqueMessage()

	/**
	 * A server fault is a 503, not a 400: the submitter did nothing wrong and
	 * should be told to try again, not to fix their message.
	 *
	 * @return void
	 */
	public function testServerFaultReturns503(): void {
		$controller = $this->controller(['title' => 'Hi']);
		$this->intake->method('submit')
			->willThrowException(new RuntimeException('register not configured'));

		$response = $controller->submit();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testServerFaultReturns503()

	/**
	 * An uncaught throw on a #[PublicPage] endpoint hands a stack trace to an
	 * anonymous caller, so every Throwable must become a response.
	 *
	 * @return void
	 */
	public function testAnyThrowableBecomesAResponse(): void {
		$controller = $this->controller(['title' => 'Hi']);
		$this->intake->method('submit')->willThrowException(new \Error('boom'));

		$response = $controller->submit();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testAnyThrowableBecomesAResponse()

	/**
	 * Without the reflected Origin the browser hands the page an opaque error,
	 * so a visitor who submitted successfully is told it failed.
	 *
	 * @return void
	 */
	public function testReflectsTheOriginOnSuccess(): void {
		$controller = $this->controller(['title' => 'Hi'], 'https://www.conduction.nl');
		$this->intake->method('submit')->willReturn('uuid-1');

		$headers = $this->addedHeaders(response: $controller->submit());

		$this->assertSame('https://www.conduction.nl', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('Origin', $headers['Vary']);
	}//end testReflectsTheOriginOnSuccess()

	/**
	 * A refusal is read cross-origin too, so it needs the header just as much.
	 *
	 * @return void
	 */
	public function testReflectsTheOriginOnRefusal(): void {
		$controller = $this->controller(['title' => 'Hi'], 'https://www.conduction.nl');
		$this->intake->method('submit')
			->willThrowException(new InvalidArgumentException('refused'));

		$headers = $this->addedHeaders(response: $controller->submit());

		$this->assertSame('https://www.conduction.nl', $headers['Access-Control-Allow-Origin']);
	}//end testReflectsTheOriginOnRefusal()

	/**
	 * No Origin, no CORS header. A same-origin caller does not need one, and
	 * echoing an empty value would be a header that means nothing.
	 *
	 * @return void
	 */
	public function testOmitsCorsHeaderWhenThereIsNoOrigin(): void {
		$controller = $this->controller(['title' => 'Hi']);
		$this->intake->method('submit')->willReturn('uuid-1');

		$headers = $this->addedHeaders(response: $controller->submit());

		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
	}//end testOmitsCorsHeaderWhenThereIsNoOrigin()
}//end class
