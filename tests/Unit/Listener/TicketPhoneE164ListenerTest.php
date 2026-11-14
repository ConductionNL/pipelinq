<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
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
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Listener\TicketPhoneE164Listener;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\TicketPhoneNumber;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every writer's ticket is stored with its number in E.164.
 *
 * @covers \OCA\Pipelinq\Listener\TicketPhoneE164Listener
 * @uses   \OCA\Pipelinq\Service\TicketPhoneNumber
 * @uses   \OCA\Pipelinq\Service\PhoneNormaliser
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.2
 */
class TicketPhoneE164ListenerTest extends TestCase {

	/**
	 * The listener over ticket schema "40" and a Dutch default country.
	 *
	 * @param TicketPhoneNumber|null $phoneNumber The reader, or the real one.
	 *
	 * @return TicketPhoneE164Listener
	 */
	private function listener(?TicketPhoneNumber $phoneNumber = null): TicketPhoneE164Listener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['ticket_schema' => '40', 'default_country' => 'NL'][$key] ?? 'NL')
		);
		$logger = $this->createMock(LoggerInterface::class);

		return new TicketPhoneE164Listener($config, ($phoneNumber ?? new TicketPhoneNumber(new PhoneNormaliser($config, $logger))), $logger);
	}//end listener()

	/**
	 * An entity of the given schema and data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('t-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A CTI contact moment gets its caller in E.164, beside an earlier hook's change.
	 *
	 * @return void
	 */
	public function testACtiCallGetsItsCaller(): void {
		$event = new ObjectCreatingEvent($this->entity('40', ['direction' => 'inbound', 'from_number' => '+31612345678', 'to_number' => '+31201234567']));
		$event->setModifiedData(['reference' => 'PQ-2026-0001']);

		$this->listener()->handle($event);

		$this->assertSame(['reference' => 'PQ-2026-0001', 'phoneE164' => '+31612345678'], $event->getModifiedData());
	}//end testACtiCallGetsItsCaller()

	/**
	 * A ticket from a web enquiry gets the number the resident typed, normalised.
	 *
	 * @return void
	 */
	public function testAnEnquiryTicketGetsTheTypedNumber(): void {
		$event = new ObjectCreatingEvent($this->entity('40', ['ticketType' => 'request', 'reporterPhone' => '06-12345678']));

		$this->listener()->handle($event);

		$this->assertSame(['phoneE164' => '+31612345678'], $event->getModifiedData());
	}//end testAnEnquiryTicketGetsTheTypedNumber()

	/**
	 * An update reads the new state, including what an earlier hook changed.
	 *
	 * @return void
	 */
	public function testAnUpdateReadsTheNewState(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity('40', ['ticketType' => 'contactmoment', 'direction' => 'outbound', 'from_number' => '+31201234567']),
			$this->entity('40', [])
		);
		$event->setModifiedData(['to_number' => '+31 6 1234 5678']);

		$this->listener()->handle($event);

		$this->assertSame(['to_number' => '+31 6 1234 5678', 'phoneE164' => '+31612345678'], $event->getModifiedData());
	}//end testAnUpdateReadsTheNewState()

	/**
	 * Another schema, a ticket without a number, one already right and another event are left alone.
	 *
	 * @return void
	 */
	public function testOthersAreLeftAlone(): void {
		foreach ([
			new ObjectCreatingEvent($this->entity('41', ['from_number' => '+31612345678'])),
			new ObjectCreatingEvent($this->entity('40', ['title' => 'Vraag'])),
			new ObjectCreatingEvent($this->entity('40', ['from_number' => '0612345678', 'phoneE164' => '+31612345678'])),
		] as $event) {
			$this->listener()->handle($event);
			$this->assertSame([], $event->getModifiedData());
		}

		$reader = $this->createMock(TicketPhoneNumber::class);
		$reader->expects($this->never())->method('e164');
		$this->listener($reader)->handle(new Event());
	}//end testOthersAreLeftAlone()

	/**
	 * A reader that fails never blocks the save.
	 *
	 * @return void
	 */
	public function testAFailureNeverBlocksTheSave(): void {
		$reader = $this->createMock(TicketPhoneNumber::class);
		$reader->method('e164')->willThrowException(new RuntimeException('broken'));
		$event = new ObjectCreatingEvent($this->entity('40', ['from_number' => '+31612345678']));

		$this->listener($reader)->handle($event);

		$this->assertSame([], $event->getModifiedData());
		$this->assertFalse($event->isPropagationStopped());
	}//end testAFailureNeverBlocksTheSave()
}//end class
