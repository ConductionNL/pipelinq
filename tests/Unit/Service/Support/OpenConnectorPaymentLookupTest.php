<?php

/**
 * Behavioural tests for the OpenConnectorPaymentLookup trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Support
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

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\Support\OpenConnectorPaymentLookup;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The bound service, a non-object binding and a missing binding.
 *
 * @covers \OCA\Pipelinq\Service\Support\OpenConnectorPaymentLookup
 */
class OpenConnectorPaymentLookupTest extends TestCase {

	/**
	 * Build a host over a container.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return object The host.
	 */
	private function host(ContainerInterface $container): object {
		return new class($container) {
			use OpenConnectorPaymentLookup;

			/**
			 * @param ContainerInterface $container The container.
			 */
			public function __construct(private ContainerInterface $container) {
			}

			/**
			 * @return object|null
			 */
			public function lookup(): ?object {
				return $this->connectorPaymentService();
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testLookup(): void {
		$service = new \stdClass();
		$bound = $this->createMock(ContainerInterface::class);
		$bound->expects($this->once())->method('get')
			->with('OCA\\OpenConnector\\Service\\PaymentService')->willReturn($service);
		$this->assertSame($service, $this->host($bound)->lookup());

		$scalar = $this->createMock(ContainerInterface::class);
		$scalar->method('get')->willReturn('not an object');
		$this->assertNull($this->host($scalar)->lookup());

		$missing = $this->createMock(ContainerInterface::class);
		$missing->method('get')->willThrowException(new RuntimeException('absent'));
		$this->assertNull($this->host($missing)->lookup());
	}//end testLookup()
}//end class
