<?php

/**
 * Unit tests for HealthController.
 *
 * The public health endpoint reports OpenRegister's health-check result for
 * this app, with its status code and CORS headers when the manifest asks for
 * them, and degrades to a 200 "degraded" answer when OpenRegister's
 * observability engine cannot be reached.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\HealthController;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Tests for HealthController::index.
 *
 * @covers \OCA\Pipelinq\Controller\HealthController
 */
class HealthControllerTest extends TestCase {

	/**
	 * Mock container that hands out the engine doubles.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Controller under test.
	 *
	 * @var HealthController
	 */
	private HealthController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getAppValue')->with('pipelinq', 'installed_version', '')->willReturn('1.4.0');
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);

		$this->controller = new HealthController(
			request: $this->createMock(originalClassName: IRequest::class),
			config: $config,
			container: $this->container
		);
	}//end setUp()

	/**
	 * Hand out a manifest loader and executor that produce the given result.
	 *
	 * @param string $status The health status.
	 * @param int $httpStatus The status code the engine chose.
	 * @param bool $cors Whether the manifest enables CORS.
	 *
	 * @return void
	 */
	private function engine(string $status, int $httpStatus, bool $cors): void {
		$manifest = (object)['cors' => $cors];
		$loader = new class($manifest) {
			/**
			 * Store the manifest.
			 *
			 * @param object $manifest The manifest.
			 */
			public function __construct(private object $manifest) {
			}

			/**
			 * Load the manifest of an app.
			 *
			 * @param string $appId The app id.
			 *
			 * @return object
			 */
			public function load(string $appId): object {
				return $this->manifest;
			}

			/**
			 * The installed version of an app.
			 *
			 * @param string $appId The app id.
			 *
			 * @return string
			 */
			public function appVersion(string $appId): string {
				return $appId . '-2.0.0';
			}
		};
		$executor = new class($status, $httpStatus) {
			/**
			 * Store the result.
			 *
			 * @param string $status The status.
			 * @param int $httpStatus The status code.
			 */
			public function __construct(private string $status, private int $httpStatus) {
			}

			/**
			 * Run the checks.
			 *
			 * @param object $manifest The manifest.
			 *
			 * @return object
			 */
			public function execute(object $manifest): object {
				return (object)[
					'status' => $this->status,
					'checks' => ['database' => 'ok', 'openregister' => $this->status],
					'httpStatusCode' => $this->httpStatus,
				];
			}
		};

		$this->container->method('get')->willReturnMap(
			[
				['OCA\OpenRegister\AppHost\Observability\ManifestLoader', $loader],
				['OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor', $executor],
			]
		);
	}//end engine()

	/**
	 * The headers set on a response, read without booting the server that getHeaders() asks.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private static function headers(\OCP\AppFramework\Http\Response $response): array {
		return (new \ReflectionProperty(class: \OCP\AppFramework\Http\Response::class, property: 'headers'))->getValue($response);
	}//end headers()

	/**
	 * A healthy engine result is reported with its own status code and no CORS headers.
	 *
	 * @return void
	 */
	public function testReportsTheEngineResult(): void {
		$this->engine(status: 'ok', httpStatus: Http::STATUS_OK, cors: false);

		$response = $this->controller->index();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(
			expected: [
				'status' => 'ok',
				'app' => 'pipelinq',
				'version' => 'pipelinq-2.0.0',
				'checks' => ['database' => 'ok', 'openregister' => 'ok'],
			],
			actual: $response->getData()
		);
		self::assertArrayNotHasKey(key: 'Access-Control-Allow-Origin', array: self::headers(response: $response));
	}//end testReportsTheEngineResult()

	/**
	 * A failing check keeps the engine's status code, and a CORS manifest opens the endpoint to other origins.
	 *
	 * @return void
	 */
	public function testAFailingCheckWithCors(): void {
		$this->engine(status: 'down', httpStatus: Http::STATUS_SERVICE_UNAVAILABLE, cors: true);

		$response = $this->controller->index();

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		self::assertSame(expected: 'down', actual: $response->getData()['status']);
		self::assertSame(expected: '*', actual: self::headers(response: $response)['Access-Control-Allow-Origin']);
		self::assertSame(expected: 'GET, OPTIONS', actual: self::headers(response: $response)['Access-Control-Allow-Methods']);
	}//end testAFailingCheckWithCors()

	/**
	 * Without OpenRegister's engine the endpoint still answers, as degraded.
	 *
	 * @return void
	 */
	public function testDegradesWithoutTheEngine(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('not installed'));

		$response = $this->controller->index();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(
			expected: [
				'status' => 'degraded',
				'app' => 'pipelinq',
				'version' => '1.4.0',
				'checks' => ['openregister' => 'unavailable'],
			],
			actual: $response->getData()
		);
	}//end testDegradesWithoutTheEngine()
}//end class
