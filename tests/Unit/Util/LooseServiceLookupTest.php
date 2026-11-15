<?php

/**
 * Unit tests for the LooseServiceLookup trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\LooseServiceLookup;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A resolvable object with the required method is answered; anything else is null.
 *
 * @covers \OCA\Pipelinq\Util\LooseServiceLookup
 */
class LooseServiceLookupTest extends TestCase {

	/**
	 * @return void
	 */
	public function testLooseService(): void {
		$service = new class {
			/**
			 * @return void
			 */
			public function ingest(): void {
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): mixed {
				if ($id === 'missing') {
					throw new RuntimeException('none');
				}

				return ($id === 'scalar' ? 'text' : $service);
			}
		);
		$host = new class($container) {
			use LooseServiceLookup;

			/**
			 * @param ContainerInterface $container The container.
			 */
			public function __construct(
				private ContainerInterface $container,
			) {
			}

			/**
			 * @param string $id     The id.
			 * @param string $method The method.
			 *
			 * @return object|null
			 */
			public function lookup(string $id, string $method): ?object {
				return $this->looseService(serviceId: $id, method: $method);
			}
		};

		$this->assertSame($service, $host->lookup('svc', 'ingest'));
		$this->assertSame($service, $host->lookup('svc', ''));
		$this->assertNull($host->lookup('svc', 'find'));
		$this->assertNull($host->lookup('scalar', ''));
		$this->assertNull($host->lookup('missing', ''));
	}//end testLooseService()
}//end class
