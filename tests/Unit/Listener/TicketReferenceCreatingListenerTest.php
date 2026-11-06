<?php

/**
 * Unit tests for TicketReferenceCreatingListener.
 *
 * Drives the listener with OpenRegister's ObjectCreatingEvent and a sequence
 * double, and reads what it merges into the modified data.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\Listener\TicketReferenceCreatingListener;
use OCA\Pipelinq\Service\TicketReferenceSequence;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A new request or complaint is stored with its reference number.
 *
 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-ticket-carries-a-reference-number
 */
class TicketReferenceCreatingListenerTest extends TestCase {
	/**
	 * The listener over ticket schema "40" and a sequence that answers $next.
	 *
	 * @param string|null $next The reference to hand out, or null to fail.
	 *
	 * @return TicketReferenceCreatingListener The listener.
	 */
	private function listener(?string $next = 'PQ-2026-0001'): TicketReferenceCreatingListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['ticket_schema' => '40'][$key] ?? $default)
		);
		$sequence = $this->createMock(TicketReferenceSequence::class);
		if ($next === null) {
			$sequence->method('next')->willThrowException(new RuntimeException('busy'));
		} else {
			$sequence->method('next')->willReturn($next);
		}

		return new TicketReferenceCreatingListener($config, $sequence, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * An event for an entity of the given schema and data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectCreatingEvent The event.
	 */
	private function event(string $schema, array $data): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setUuid('t-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return new ObjectCreatingEvent($entity);
	}//end event()

	/**
	 * A new request gets the next reference, and an earlier hook's change stays.
	 *
	 * @return void
	 */
	public function testANewRequestGetsItsReference(): void {
		$event = $this->event('40', ['title' => 'Aanvraag collectevergunning', 'ticketType' => 'request']);
		$event->setModifiedData(['client' => 'c-1']);

		$this->listener()->handle($event);

		$this->assertSame(['client' => 'c-1', 'reference' => 'PQ-2026-0001'], $event->getModifiedData());
	}//end testANewRequestGetsItsReference()

	/**
	 * A contact moment, another schema, or a ticket that has a reference is left alone.
	 *
	 * @return void
	 */
	public function testOthersAreLeftAlone(): void {
		foreach ([
			$this->event('40', ['ticketType' => 'interaction']),
			$this->event('41', ['ticketType' => 'request']),
			$this->event('40', ['ticketType' => 'complaint', 'reference' => 'PQ-2025-0310']),
		] as $event) {
			$this->listener()->handle($event);
			$this->assertSame([], $event->getModifiedData());
		}
	}//end testOthersAreLeftAlone()

	/**
	 * A number that cannot be allocated never blocks the save.
	 *
	 * @return void
	 */
	public function testAFailedAllocationStoresTheTicketWithoutOne(): void {
		$event = $this->event('40', ['ticketType' => 'complaint']);

		$this->listener(next: null)->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testAFailedAllocationStoresTheTicketWithoutOne()
}//end class
