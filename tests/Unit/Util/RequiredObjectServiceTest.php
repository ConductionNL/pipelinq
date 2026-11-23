<?php

/**
 * Behavioural tests for the RequiredObjectService trait.
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

use OCA\Pipelinq\Util\RequiredObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The container's ObjectService is returned; a failed lookup is a RuntimeException.
 *
 * @covers \OCA\Pipelinq\Util\RequiredObjectService
 */
class RequiredObjectServiceTest extends TestCase {

	/**
	 * Build a host over the given container.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return object The host.
	 */
	private function host(ContainerInterface $container): object {
		return new class($container) {
			use RequiredObjectService;

			/**
			 * @param ContainerInterface $container The container.
			 */
			public function __construct(private ContainerInterface $container) {
			}

			/**
			 * @return object
			 */
			public function read(): object {
				return $this->getObjectService();
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testLookup(): void {
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('The OpenRegister app is not installed');
			$this->host($this->createStub(ContainerInterface::class))->read();
			return;
		}

		$service = new \stdClass();
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($service);
		$this->assertSame($service, $this->host($container)->read());

		$failing = $this->createStub(ContainerInterface::class);
		$failing->method('get')->willThrowException(new \LogicException('gone'));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister ObjectService is unavailable.');
		$this->host($failing)->read();
	}//end testLookup()
}//end class
