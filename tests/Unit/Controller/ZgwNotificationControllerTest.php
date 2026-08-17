<?php

/**
 * Unit tests for ZgwNotificationController.
 *
 * Covers the bearer-auth handshake (REQ-ZGW-007): valid token → 202
 * Accepted + dispatcher invoked; missing/unknown token → 422 (webhook
 * signature failure, matching the BlastWebhook/AppointmentPaymentWebhook
 * convention); bad JSON → 400. Dispatcher behaviour is exercised in
 * NrcNotificationListenerTest.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ZgwNotificationController;
use OCA\Pipelinq\Listener\NrcNotificationListener;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZgwNotificationController.
 */
class ZgwNotificationControllerTest extends TestCase {
	/**
	 * Build a controller subclass that returns a fixed raw body.
	 *
	 * @param IRequest $request Request mock.
	 * @param ZgwRegisterAccess $registers Register access mock.
	 * @param ZgwApiClient $api API client mock.
	 * @param NrcNotificationListener $listener Listener mock.
	 * @param LoggerInterface $logger Logger mock.
	 * @param string $stubBody Body fixture.
	 *
	 * @return ZgwNotificationController
	 */
	private function makeController(
		IRequest $request,
		ZgwRegisterAccess $registers,
		ZgwApiClient $api,
		NrcNotificationListener $listener,
		LoggerInterface $logger,
		string $stubBody,
	): ZgwNotificationController {
		return new class($request, $registers, $api, $listener, $logger, $stubBody) extends ZgwNotificationController {
			/** @var string */
			private string $stubBody;
			/**
			 * Constructor.
			 *
			 * @param IRequest $request Request.
			 * @param ZgwRegisterAccess $registers Register access.
			 * @param ZgwApiClient $api API client.
			 * @param NrcNotificationListener $listener Listener.
			 * @param LoggerInterface $logger Logger.
			 * @param string $stubBody Body fixture.
			 */
			public function __construct(
				IRequest $request,
				ZgwRegisterAccess $registers,
				ZgwApiClient $api,
				NrcNotificationListener $listener,
				LoggerInterface $logger,
				string $stubBody,
			) {
				parent::__construct($request, $registers, $api, $listener, $logger);
				$this->stubBody = $stubBody;
			}
			/**
			 * Override raw body for tests.
			 *
			 * @return string
			 */
			protected function readRawBody(): string {
				return $this->stubBody;
			}
		};
	}//end makeController()

	/**
	 * Test: missing Authorization header → 422.
	 *
	 * @return void
	 */
	public function testMissingBearerReturns422(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$api = $this->createMock(ZgwApiClient::class);
		$listener = $this->createMock(NrcNotificationListener::class);
		$listener->expects(self::never())->method('dispatch');

		$controller = $this->makeController($request,
			$registers,
			$api,
			$listener,
			$this->createMock(LoggerInterface::class),
			'{"channel":"zaken"}'
		);

		$response = $controller->inbox();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testMissingBearerReturns422()

	/**
	 * Test: unknown bearer (no matching abonnement) → 422.
	 *
	 * @return void
	 */
	public function testUnknownBearerReturns422(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([
			['Authorization', 'Bearer wrong-bearer'],
		]);
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findAll')->willReturn([
			['callbackAuth' => 'right-bearer', 'active' => true, 'endpointId' => 'ep-1'],
		]);
		$api = $this->createMock(ZgwApiClient::class);
		$api->method('resolveClientSecret')->willReturnArgument(0);

		$listener = $this->createMock(NrcNotificationListener::class);
		$listener->expects(self::never())->method('dispatch');

		$controller = $this->makeController($request,
			$registers,
			$api,
			$listener,
			$this->createMock(LoggerInterface::class),
			'{}'
		);

		$response = $controller->inbox();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testUnknownBearerReturns422()

	/**
	 * Test: matching bearer + valid JSON → 202 Accepted + dispatch.
	 *
	 * @return void
	 */
	public function testMatchingBearerDispatchesAndReturns202(): void {
		$abonnement = ['callbackAuth' => 'right-bearer', 'active' => true, 'endpointId' => 'ep-1'];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([
			['Authorization', 'Bearer right-bearer'],
		]);
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findAll')->willReturn([$abonnement]);
		$api = $this->createMock(ZgwApiClient::class);
		$api->method('resolveClientSecret')->willReturnArgument(0);

		$listener = $this->createMock(NrcNotificationListener::class);
		$listener->expects(self::once())
			->method('dispatch')
			->with(
				self::callback(static fn (array $a): bool => ($a['callbackAuth'] ?? '') === 'right-bearer'),
				self::callback(static fn (array $b): bool => ($b['channel'] ?? '') === 'zaken')
			);

		$controller = $this->makeController($request,
			$registers,
			$api,
			$listener,
			$this->createMock(LoggerInterface::class),
			'{"channel":"zaken","resource":"status","action":"create","hoofdObject":"https://open-zaak/zaken/abc","resourceUrl":"https://open-zaak/statussen/def"}'
		);

		$response = $controller->inbox();
		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
	}//end testMatchingBearerDispatchesAndReturns202()

	/**
	 * Test: invalid JSON body → 400.
	 *
	 * @return void
	 */
	public function testInvalidJsonBodyReturns400(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([
			['Authorization', 'Bearer right-bearer'],
		]);
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findAll')->willReturn([
			['callbackAuth' => 'right-bearer', 'active' => true, 'endpointId' => 'ep-1'],
		]);
		$api = $this->createMock(ZgwApiClient::class);
		$api->method('resolveClientSecret')->willReturnArgument(0);
		$listener = $this->createMock(NrcNotificationListener::class);
		$listener->expects(self::never())->method('dispatch');

		$controller = $this->makeController($request,
			$registers,
			$api,
			$listener,
			$this->createMock(LoggerInterface::class),
			'not-json'
		);

		$response = $controller->inbox();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testInvalidJsonBodyReturns400()

}//end class
