<?php

/**
 * Behavioural tests for the ContainerObjectService trait.
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

use OCA\Pipelinq\Util\ContainerObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The container's ObjectService is returned; a failure logs and yields null.
 *
 * @covers \OCA\Pipelinq\Util\ContainerObjectService
 * @uses \OCA\Pipelinq\Util\ContainerObjectServiceLookup
 */
class ContainerObjectServiceTest extends TestCase {

	/**
	 * Build a host over the given container and logger.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return ContainerObjectServiceHost The host.
	 */
	private function host(ContainerInterface $container, LoggerInterface $logger): ContainerObjectServiceHost {
		return new ContainerObjectServiceHost($container, $logger);
	}//end host()

	/**
	 * @return void
	 */
	public function testReturnsTheContainerService(): void {
		$service = new \stdClass();
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($service);

		$this->assertSame($service, $this->host($container, $this->createMock(LoggerInterface::class))->read());
	}//end testReturnsTheContainerService()

	/**
	 * @return void
	 */
	public function testFailureIsLoggedWithTheHostClassName(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'ContainerObjectServiceHost.getObjectService: OpenRegister unavailable',
			['exception' => 'gone']
		);

		$this->assertNull($this->host($container, $logger)->read());
	}//end testFailureIsLoggedWithTheHostClassName()
}//end class

/**
 * A named host, so the log prefix is a predictable short class name.
 */
final class ContainerObjectServiceHost {
	use ContainerObjectService;

	/**
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(private ContainerInterface $container, private LoggerInterface $logger) {
	}

	/**
	 * @return object|null
	 */
	public function read(): ?object {
		return $this->getObjectService();
	}
}//end class
