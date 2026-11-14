<?php

/**
 * Unit tests for the Prometheus metrics endpoint.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MetricsController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use OCP\AppFramework\Http\Response;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * The /metrics endpoint renders the AppHost observability output, or degrades
 * to a readable 503 when the engine is absent.
 *
 * @covers \OCA\Pipelinq\Controller\MetricsController
 */
class MetricsControllerTest extends TestCase {

	/**
	 * The controller over a container that resolves the given services.
	 *
	 * @param array<string, mixed> $services Service id => instance or Throwable.
	 *
	 * @return MetricsController The controller.
	 */
	private function controller(array $services): MetricsController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				$value = ($services[$id] ?? null);
				if ($value instanceof \Throwable) {
					throw $value;
				}

				if ($value === null) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $value;
			}
		);

		return new MetricsController($this->createMock(IRequest::class), $container);
	}//end controller()

	/**
	 * The manifest the loader returns is rendered by the engine and served as Prometheus text.
	 *
	 * @return void
	 */
	public function testMetricsAreRendered(): void {
		$loader = new class {
			/**
			 * @param string $appId The app id.
			 *
			 * @return array<string, string> The manifest.
			 */
			public function load(string $appId): array {
				return ['app' => $appId];
			}
		};
		$engine = new class {
			/**
			 * @param array<string, string> $manifest The manifest.
			 *
			 * @return string The rendered metrics.
			 */
			public function render(array $manifest): string {
				return '# HELP up 1' . "\n" . 'up{app="' . $manifest['app'] . '"} 1' . "\n";
			}
		};
		$response = $this->controller([
			'OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader' => $loader,
			'OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine' => $engine,
		])->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('# HELP up 1' . "\n" . 'up{app="pipelinq"} 1' . "\n", $response->render());
		$this->assertSame('text/plain; version=0.0.4; charset=utf-8', $this->headerOf($response, 'Content-Type'));
	}//end testMetricsAreRendered()

	/**
	 * Without the observability engine the endpoint answers 503 with a readable note.
	 *
	 * @return void
	 */
	public function testMissingEngineDegradesTo503(): void {
		$response = $this->controller([
			'OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader' => new RuntimeException('absent'),
		])->index();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertStringContainsString('metrics unavailable', $response->render());
		$this->assertSame('text/plain; version=0.0.4; charset=utf-8', $this->headerOf($response, 'Content-Type'));
	}//end testMissingEngineDegradesTo503()

	/**
	 * Read one header a response set, without the server container getHeaders() needs.
	 *
	 * @param Response $response The response.
	 * @param string   $name     The header name.
	 *
	 * @return string|null The header value.
	 */
	private function headerOf(Response $response, string $name): ?string {
		$property = new ReflectionProperty(Response::class, 'headers');

		return ($property->getValue($response)[$name] ?? null);
	}//end headerOf()

}//end class
