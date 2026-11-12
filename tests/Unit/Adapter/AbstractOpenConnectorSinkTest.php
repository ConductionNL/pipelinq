<?php

/**
 * Unit tests for AbstractOpenConnectorSink.
 *
 * Exercises the shared probe/transfer logic through a minimal concrete sink and
 * a fake OpenConnector CallService resolved from a mocked container.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Adapter;

use OCA\Pipelinq\Adapter\AbstractOpenConnectorSink;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Adapter\AbstractOpenConnectorSink
 * @uses   \OCA\Pipelinq\Support\FleetAppId
 */
class AbstractOpenConnectorSinkTest extends TestCase {

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the logger mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A container that returns the given service under the given class name and throws otherwise.
	 *
	 * @param object|null $service The service, or null for an absent app.
	 * @param string      $fqcn    The class name it is registered under.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(?object $service, string $fqcn = 'OCA\Integriq\Service\CallService'): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service, $fqcn) {
				if ($service !== null && $id === $fqcn) {
					return $service;
				}

				throw new RuntimeException('not registered: '.$id);
			}
		);
		return $container;
	}//end container()

	/**
	 * A minimal concrete sink that echoes the transfer result as JSON.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return AbstractOpenConnectorSink The sink.
	 */
	private function sink(ContainerInterface $container): AbstractOpenConnectorSink {
		return new class($container, $this->logger) extends AbstractOpenConnectorSink {
			public function getType(): string {
				return 'fake';
			}

			protected function acknowledge(array $result): string {
				return json_encode($result, JSON_THROW_ON_ERROR);
			}
		};
	}//end sink()

	/**
	 * testConnection is false when OpenConnector/Integriq is not installed.
	 *
	 * @return void
	 */
	public function testConnectionIsFalseWithoutCallService(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->assertFalse($this->sink($this->container(null))->testConnection(['k' => 'v'], ['bucket' => 'b']));
	}//end testConnectionIsFalseWithoutCallService()

	/**
	 * testConnection forwards credentials and destination to checkConnection and casts its result.
	 *
	 * @return void
	 */
	public function testConnectionDelegatesToCheckConnection(): void {
		$service = new class {
			public array $calls = [];
			public mixed $answer = 1;

			public function checkConnection(array $credentials, array $destination): mixed {
				$this->calls[] = [$credentials, $destination];
				return $this->answer;
			}
		};

		$sink = $this->sink($this->container($service));
		$this->assertTrue($sink->testConnection(['key' => 'k'], ['bucket' => 'b']));
		$this->assertSame([[['key' => 'k'], ['bucket' => 'b']]], $service->calls);

		$service->answer = 0;
		$this->assertFalse($sink->testConnection(['key' => 'k'], ['bucket' => 'b']));
	}//end testConnectionDelegatesToCheckConnection()

	/**
	 * The service is also found under the legacy OpenConnector namespace.
	 *
	 * @return void
	 */
	public function testConnectionResolvesTheLegacyNamespace(): void {
		$service = new class {
			public function checkConnection(array $credentials, array $destination): bool {
				return true;
			}
		};

		$sink = $this->sink($this->container($service, 'OCA\OpenConnector\Service\CallService'));

		$this->assertTrue($sink->testConnection(['key' => 'k'], []));
	}//end testConnectionResolvesTheLegacyNamespace()

	/**
	 * Without checkConnection, reachability means credentials are present.
	 *
	 * @return void
	 */
	public function testConnectionFallsBackToCredentialPresence(): void {
		$sink = $this->sink($this->container(new class {
		}));

		$this->assertTrue($sink->testConnection(['key' => 'k'], []));
		$this->assertFalse($sink->testConnection([], ['bucket' => 'b']));
	}//end testConnectionFallsBackToCredentialPresence()

	/**
	 * A throwing probe is logged as a warning and reported as false.
	 *
	 * @return void
	 */
	public function testConnectionLogsAndSwallowsAThrowingProbe(): void {
		$service = new class {
			public function checkConnection(array $credentials, array $destination): bool {
				throw new RuntimeException('boom');
			}
		};

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Pipelinq: export sink connectivity test failed',
				['type' => 'fake', 'exception' => 'boom']
			);

		$this->assertFalse($this->sink($this->container($service))->testConnection(['k' => 'v'], []));
	}//end testConnectionLogsAndSwallowsAThrowingProbe()

	/**
	 * upload passes every argument to put() and acknowledges its array result.
	 *
	 * @return void
	 */
	public function testUploadForwardsToPutAndAcknowledgesTheResult(): void {
		$service = new class {
			public array $calls = [];

			public function put(array $credentials, array $destination, string $remotePath, string $contents): array {
				$this->calls[] = func_get_args();
				return ['etag' => 'abc'];
			}
		};

		$ack = $this->sink($this->container($service))->upload(['key' => 'k'], ['bucket' => 'b'], 'out/x.csv', 'a,b');

		$this->assertSame('{"etag":"abc"}', $ack);
		$this->assertSame([[['key' => 'k'], ['bucket' => 'b'], 'out/x.csv', 'a,b']], $service->calls);
	}//end testUploadForwardsToPutAndAcknowledgesTheResult()

	/**
	 * A non-array put() result becomes a path-only result.
	 *
	 * @return void
	 */
	public function testUploadTurnsANonArrayResultIntoThePath(): void {
		$service = new class {
			public function put(array $credentials, array $destination, string $remotePath, string $contents): bool {
				return true;
			}
		};

		$ack = $this->sink($this->container($service))->upload([], [], 'out/y.json', '{}');

		$this->assertSame('{"path":"out\/y.json"}', $ack);
	}//end testUploadTurnsANonArrayResultIntoThePath()

	/**
	 * upload throws when the CallService is not available.
	 *
	 * @return void
	 */
	public function testUploadThrowsWithoutCallService(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenConnector CallService is not available for export upload.');

		$this->sink($this->container(null))->upload([], [], 'p', 'c');
	}//end testUploadThrowsWithoutCallService()

	/**
	 * upload throws loudly when the CallService has no put().
	 *
	 * @return void
	 */
	public function testUploadThrowsWhenPutIsMissing(): void {
		$service = new class {
			public function call(): array {
				return [];
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenConnector CallService does not support file uploads.');

		$this->sink($this->container($service))->upload([], [], 'p', 'c');
	}//end testUploadThrowsWhenPutIsMissing()
}//end class
