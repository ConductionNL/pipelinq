<?php

/**
 * Behavioural tests for the ContainerObjectFind trait.
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

use OCA\Pipelinq\Tests\Unit\Listener\Support\ConfiguredSchemaObjectReadTest;
use OCA\Pipelinq\Util\ContainerObjectFind;
use OCA\Pipelinq\Util\ContainerObjectSave;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads and saves by register, schema and uuid; failures log the host's line.
 *
 * @covers \OCA\Pipelinq\Util\ContainerObjectFind
 * @uses   \OCA\Pipelinq\Util\ConfiguredSchemaObjectWrite
 */
class ContainerObjectFindTest extends TestCase {

	/**
	 * Build a host over the given store.
	 *
	 * @param object          $store  The object service.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return ContainerObjectFindHost The host.
	 */
	private function host(object $store, LoggerInterface $logger): ContainerObjectFindHost {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		return new ContainerObjectFindHost($container, $logger);
	}//end host()

	/**
	 * @return void
	 */
	public function testReadAndSave(): void {
		$quiet = $this->createMock(LoggerInterface::class);
		$quiet->expects($this->never())->method('warning');
		$store = ConfiguredSchemaObjectReadTest::store();
		$host = $this->host($store, $quiet);

		$this->assertSame(['at' => ['r', 's']], $host->read('r', 's', 'known'));
		$this->assertNull($host->read('r', 's', 'missing'));
		$host->write('r', 's', 'u1', ['a' => 1]);
		$this->assertSame([[['a' => 1], [], 'r', 's', 'u1']], $store->calls);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->assertContains($message, ['find failed', 'save failed']);
				$this->assertSame(['exception' => 'down', 'uuid' => 'u1'], $context);
			}
		);
		$failing = $this->host(ConfiguredSchemaObjectReadTest::store(fail: true), $logger);
		$this->assertNull($failing->read('r', 's', 'u1'));
		$failing->write('r', 's', 'u1', []);
	}//end testReadAndSave()
}//end class

/**
 * A host of both traits with fixed log lines.
 */
final class ContainerObjectFindHost {
	use ContainerObjectFind;
	use ContainerObjectSave;

	private const FETCH_FAILURE_MESSAGE = 'find failed';

	private const PERSIST_FAILURE_MESSAGE = 'save failed';

	/**
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(private ContainerInterface $container, private LoggerInterface $logger) {
	}

	/**
	 * @param string $register Register.
	 * @param string $schema   Schema.
	 * @param string $uuid     Uuid.
	 * @return array<string, mixed>|null
	 */
	public function read(string $register, string $schema, string $uuid): ?array {
		return $this->findObjectData(register: $register, schema: $schema, uuid: $uuid);
	}

	/**
	 * @param string               $register Register.
	 * @param string               $schema   Schema.
	 * @param string               $uuid     Uuid.
	 * @param array<string, mixed> $data     Data.
	 * @return void
	 */
	public function write(string $register, string $schema, string $uuid, array $data): void {
		$this->saveObjectData(register: $register, schema: $schema, uuid: $uuid, data: $data);
	}
}//end class
