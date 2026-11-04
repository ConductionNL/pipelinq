<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Listener\TicketPhoneListener;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Listener\TicketPhoneListener
 */
class TicketPhoneListenerTest extends TestCase {
	/**
	 * The listener over ticket schema "40", with the real normaliser.
	 *
	 * @return TicketPhoneListener The listener.
	 */
	private function listener(): TicketPhoneListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['ticket_schema' => '40'][$key] ?? $default)
		);

		return new TicketPhoneListener($config, new PhoneNormaliser($config, $this->createMock(LoggerInterface::class)));
	}//end listener()

	/**
	 * An entity of the given schema and data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('t-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Each writer's number is stored in E.164: a CTI call, an outbound call, a web form.
	 *
	 * @return void
	 */
	public function testEveryWritersNumberIsStoredInE164(): void {
		$cases = [
			'inbound CTI call' => [['from_number' => '+31612345678', 'to_number' => '+31201234567', 'direction' => 'inbound'], '+31612345678'],
			'outbound CTI call' => [['from_number' => '+31201234567', 'to_number' => '06 1234 5678', 'direction' => 'outbound'], '+31612345678'],
			'web form' => [['reporterPhone' => '06-12345678'], '+31612345678'],
			'logged contact moment' => [['from_number' => '0031612345678'], '+31612345678'],
		];
		foreach ($cases as $name => [$data, $expected]) {
			$event = new ObjectCreatingEvent($this->entity(schema: '40', data: $data));
			$event->setModifiedData(['reference' => 'PQ-2026-0001']);

			$this->listener()->handle($event);

			$this->assertSame(['reference' => 'PQ-2026-0001', 'phoneE164' => $expected], $event->getModifiedData(), $name);
		}
	}//end testEveryWritersNumberIsStoredInE164()

	/**
	 * A number typed later is stored on the update.
	 *
	 * @return void
	 */
	public function testAnUpdateStoresANewNumber(): void {
		$event = new ObjectUpdatingEvent($this->entity(schema: '40', data: ['reporterPhone' => '+31 6 1234 5678']));

		$this->listener()->handle($event);

		$this->assertSame(['phoneE164' => '+31612345678'], $event->getModifiedData());
	}//end testAnUpdateStoresANewNumber()

	/**
	 * Another schema, a ticket without a number, and one already stored are left alone.
	 *
	 * @return void
	 */
	public function testOthersAreLeftAlone(): void {
		foreach ([
			$this->entity(schema: '41', data: ['from_number' => '+31612345678']),
			$this->entity(schema: '40', data: ['title' => 'Vraag over afval']),
			$this->entity(schema: '40', data: ['from_number' => '+31612345678', 'phoneE164' => '+31612345678']),
		] as $entity) {
			$event = new ObjectCreatingEvent($entity);
			$this->listener()->handle($event);
			$this->assertSame([], $event->getModifiedData());
		}
	}//end testOthersAreLeftAlone()
}//end class
