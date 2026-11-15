<?php

/**
 * Behavioural tests for the ContainerWebhookService trait.
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

use OCA\Pipelinq\Util\ContainerWebhookService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The container's WebhookService is returned; a failed lookup is null.
 *
 * @covers \OCA\Pipelinq\Util\ContainerWebhookService
 */
class ContainerWebhookServiceTest extends TestCase {

	/**
	 * @return void
	 */
	public function testLookup(): void {
		$service = new \stdClass();
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly(2))->method('get')->with('OCA\OpenRegister\Service\WebhookService')
			->willReturnOnConsecutiveCalls($service, $this->throwException(new \RuntimeException('gone')));
		$host = new class($container) {
			use ContainerWebhookService;

			/**
			 * @param ContainerInterface $container The container.
			 */
			public function __construct(private ContainerInterface $container) {
			}

			/**
			 * @return object|null
			 */
			public function read(): ?object {
				return $this->resolveWebhookService();
			}
		};

		$this->assertSame($service, $host->read());
		$this->assertNull($host->read());
	}//end testLookup()
}//end class
