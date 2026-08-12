<?php

/**
 * Unit tests for BlastTrackingController.
 *
 * Covers:
 * - open() always returns the 1x1 GIF with the correct content-type/cache
 *   headers, regardless of token validity (fail-closed on the *record*)
 * - open() with a valid token records exactly once; an invalid token
 *   records nothing but still returns 200 + the pixel
 * - click() with a valid token 302-redirects to the token's target URL
 *   and records exactly once
 * - click() with an invalid/expired token returns 410 Gone and performs
 *   no redirect and no record (never trusts an unverified target)
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BlastTrackingController;
use OCA\Pipelinq\Service\TrackingLinkService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BlastTrackingController.
 *
 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#4.1
 */
class BlastTrackingControllerTest extends TestCase {
	/**
	 * Placeholder BlastDelivery id (design.md Seed Data section — nil
	 * UUID, never a realistic-looking value).
	 *
	 * @var string
	 */
	private const DELIVERY_ID = '00000000-0000-0000-0000-000000000000';

	private IRequest $request;
	private LoggerInterface $logger;

	/**
	 * Set up — mock collaborators shared across tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Read one header directly off a Response's private `$headers` array.
	 *
	 * `Response::getHeaders()` merges in defaults via `\OC::$server->get(...)`,
	 * which is unavailable in this standalone (no-NC) PHPUnit harness — so
	 * tests read the headers actually set by the controller via reflection
	 * instead of calling the (NC-runtime-dependent) getter.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response under test.
	 * @param string $name Header name.
	 *
	 * @return mixed The header value, or null when unset.
	 */
	private function headerValue(\OCP\AppFramework\Http\Response $response, string $name): mixed {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$property->setAccessible(true);
		$headers = $property->getValue($response);
		return ($headers[$name] ?? null);
	}//end headerValue()

	/**
	 * open() with a valid token returns the 1x1 GIF, the correct headers,
	 * and records exactly one open.
	 *
	 * @return void
	 */
	public function testOpenWithValidTokenRecordsAndReturnsPixel(): void {
		$tracking = $this->createMock(TrackingLinkService::class);
		$tracking->method('verifyToken')->with('good-token')->willReturn(['d' => self::DELIVERY_ID, 'u' => null]);
		$tracking->expects($this->once())->method('recordOpen')->with(self::DELIVERY_ID);

		$controller = new BlastTrackingController($this->request, $tracking, $this->logger);
		$response = $controller->open('good-token');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('image/gif', $this->headerValue($response, 'Content-Type'));
		$this->assertStringContainsString('no-store', (string)$this->headerValue($response, 'Cache-Control'));
		$this->assertNotSame('', $response->getData());
	}//end testOpenWithValidTokenRecordsAndReturnsPixel()

	/**
	 * open() with a bad/missing/expired token still returns the pixel
	 * (200) but records nothing — fail closed on the record, not the
	 * response.
	 *
	 * @return void
	 */
	public function testOpenWithInvalidTokenRecordsNothingButReturnsPixel(): void {
		$tracking = $this->createMock(TrackingLinkService::class);
		$tracking->method('verifyToken')->willReturn(null);
		$tracking->expects($this->never())->method('recordOpen');

		$controller = new BlastTrackingController($this->request, $tracking, $this->logger);
		$response = $controller->open('tampered-or-expired');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('image/gif', $this->headerValue($response, 'Content-Type'));
	}//end testOpenWithInvalidTokenRecordsNothingButReturnsPixel()

	/**
	 * open() never raises a 500 even when the record path throws.
	 *
	 * @return void
	 */
	public function testOpenNeverThrowsWhenRecordFails(): void {
		$tracking = $this->createMock(TrackingLinkService::class);
		$tracking->method('verifyToken')->willReturn(['d' => self::DELIVERY_ID, 'u' => null]);
		$tracking->method('recordOpen')->willThrowException(new \RuntimeException('OpenRegister unavailable'));

		$controller = new BlastTrackingController($this->request, $tracking, $this->logger);
		$response = $controller->open('good-token');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testOpenNeverThrowsWhenRecordFails()

	/**
	 * click() with a valid token records the click and 302-redirects to
	 * the token-bound target URL.
	 *
	 * @return void
	 */
	public function testClickWithValidTokenRedirectsAndRecords(): void {
		$tracking = $this->createMock(TrackingLinkService::class);
		$tracking->method('verifyToken')->with('good-token')->willReturn([
			'd' => self::DELIVERY_ID,
			'u' => 'https://pipelinq.nl/q4?utm_campaign=gemeente',
		]);
		$tracking->expects($this->once())
			->method('recordClick')
			->with(self::DELIVERY_ID, 'https://pipelinq.nl/q4?utm_campaign=gemeente');

		$controller = new BlastTrackingController($this->request, $tracking, $this->logger);
		$response = $controller->click('good-token');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(Http::STATUS_FOUND, $response->getStatus());
	}//end testClickWithValidTokenRedirectsAndRecords()

	/**
	 * click() with a tampered/expired token returns 410 Gone — no redirect,
	 * no record. The endpoint never trusts a caller-supplied target.
	 *
	 * @return void
	 */
	public function testClickWithInvalidTokenReturns410AndDoesNotRedirect(): void {
		$tracking = $this->createMock(TrackingLinkService::class);
		$tracking->method('verifyToken')->willReturn(null);
		$tracking->expects($this->never())->method('recordClick');

		$controller = new BlastTrackingController($this->request, $tracking, $this->logger);
		$response = $controller->click('tampered-token');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
	}//end testClickWithInvalidTokenReturns410AndDoesNotRedirect()

	/**
	 * click() with a token that verifies but carries no target URL (e.g.
	 * an open token presented at the click endpoint) also returns 410.
	 *
	 * @return void
	 */
	public function testClickWithMissingTargetUrlReturns410(): void {
		$tracking = $this->createMock(TrackingLinkService::class);
		$tracking->method('verifyToken')->willReturn(['d' => self::DELIVERY_ID, 'u' => null]);
		$tracking->expects($this->never())->method('recordClick');

		$controller = new BlastTrackingController($this->request, $tracking, $this->logger);
		$response = $controller->click('open-token-used-at-click');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
	}//end testClickWithMissingTargetUrlReturns410()
}//end class
