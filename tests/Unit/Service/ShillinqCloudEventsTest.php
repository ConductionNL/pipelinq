<?php

/**
 * Unit tests for ShillinqCloudEvents.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ShillinqCloudEvents;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ShillinqCloudEvents hands a CloudEvent to OpenRegister's webhook service.
 */
class ShillinqCloudEventsTest extends TestCase {

	/**
	 * The event is dispatched under its type with the whole CloudEvent as payload.
	 *
	 * @return void
	 */
	public function testTheEventIsDispatched(): void {
		$cloudEvent = ['type' => 'nl.conduction.pipelinq.order.won', 'data' => ['orderId' => 'o-1']];
		$webhooks = new class {
			/**
			 * The calls.
			 *
			 * @var list<array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Record a dispatch.
			 *
			 * @param Event                $_event    The event.
			 * @param string               $eventName The name.
			 * @param array<string, mixed> $payload   The payload.
			 *
			 * @return void
			 */
			public function dispatchEvent(Event $_event, string $eventName, array $payload): void {
				$this->calls[] = ['name' => $eventName, 'payload' => $payload];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\WebhookService')->willReturn($webhooks);

		self::assertTrue((new ShillinqCloudEvents(container: $container, logger: $this->createMock(LoggerInterface::class)))->dispatch(cloudEvent: $cloudEvent, handOff: 'order'));
		self::assertSame([['name' => 'nl.conduction.pipelinq.order.won', 'payload' => $cloudEvent]], $webhooks->calls);
	}//end testTheEventIsDispatched()

	/**
	 * Without OpenRegister the hand-off is logged and answered false.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheHandOffFails(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such service'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Pipelinq: Shillinq invoice CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
			['exception' => 'no such service', 'eventName' => 'x.invoice']
		);

		self::assertFalse((new ShillinqCloudEvents(container: $container, logger: $logger))->dispatch(cloudEvent: ['type' => 'x.invoice'], handOff: 'invoice'));
	}//end testWithoutOpenRegisterTheHandOffFails()

	/**
	 * now() is an ISO-8601 UTC moment.
	 *
	 * @return void
	 */
	public function testNow(): void {
		$events = new ShillinqCloudEvents(container: $this->createMock(ContainerInterface::class), logger: $this->createMock(LoggerInterface::class));

		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $events->now());
	}//end testNow()
}//end class
