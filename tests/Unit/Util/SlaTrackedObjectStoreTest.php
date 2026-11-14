<?php

/**
 * Behavioural tests for the SlaTrackedObjectStore trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\SlaTrackedObjectStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SLA type from the ticket label; read/save failures log with the host name.
 *
 * @covers \OCA\Pipelinq\Util\SlaTrackedObjectStore
 */
class SlaTrackedObjectStoreTest extends TestCase {

	/**
	 * Build a host over a fake object service.
	 *
	 * @param object          $store  The object service.
	 * @param LoggerInterface $logger The logger.
	 * @param string          $register The configured register.
	 *
	 * @return SlaTrackedObjectStoreHost The host.
	 */
	private function host(object $store, LoggerInterface $logger, string $register = 'r'): SlaTrackedObjectStoreHost {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		return new SlaTrackedObjectStoreHost($appConfig, $container, $logger);
	}//end host()

	/**
	 * @return void
	 */
	public function testTypeReadAndWrite(): void {
		$quiet = $this->createMock(LoggerInterface::class);
		$quiet->expects($this->never())->method('warning');
		$store = ConfiguredSchemaObjectReadTest::store();
		$host = $this->host($store, $quiet);

		$this->assertSame('complaint', $host->call('resolveSlaType', 'ticket', ['ticketType' => 'klacht']));
		$this->assertNull($host->call('resolveSlaType', 'ticket', ['ticketType' => 'other']));
		$this->assertNull($host->call('resolveSlaType', 'lead', ['ticketType' => 'klacht']));

		$this->assertSame(['at' => ['r', 'schema-1']], $host->call('fetch', 'known', 'schema-1'));
		$host->call('persist', 'u1', 'schema-1', ['a' => 1]);
		$host->call('persist', 'u1', '', ['a' => 2]);
		$this->assertSame([[['a' => 1], [], 'r', 'schema-1', 'u1']], $store->calls);
		$this->assertNull($this->host($store, $quiet, '')->call('fetch', 'known', 'schema-1'));
	}//end testTypeReadAndWrite()

	/**
	 * @return void
	 */
	public function testFailuresAreLoggedWithTheHostName(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->assertContains(
					$message,
					['SlaTrackedObjectStoreHost: re-read failed (non-blocking)', 'SlaTrackedObjectStoreHost: persist failed (non-blocking)']
				);
				$this->assertSame(['error' => 'down', 'uuid' => 'u1'], $context);
			}
		);

		$host = $this->host(ConfiguredSchemaObjectReadTest::store(fail: true), $logger);
		$this->assertNull($host->call('fetch', 'u1', 's'));
		$host->call('persist', 'u1', 's', []);
	}//end testFailuresAreLoggedWithTheHostName()
}//end class

/**
 * A named host, so the log prefix is a predictable short class name.
 */
final class SlaTrackedObjectStoreHost {
	use SlaTrackedObjectStore;

	private const TICKET_TYPE_LABELS = ['klacht' => 'complaint'];

	/**
	 * @param IAppConfig         $appConfig The app config.
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $method  The private method.
	 * @param mixed  ...$args The arguments.
	 * @return mixed
	 */
	public function call(string $method, mixed ...$args): mixed {
		return $this->$method(...$args);
	}
}//end class
