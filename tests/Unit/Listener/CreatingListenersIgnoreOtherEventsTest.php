<?php

/**
 * The creating-time listeners that stamp a new task or ticket act on
 * ObjectCreatingEvent only.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Kernel\Contracts\TicketReferenceSequenceInterface;
use OCA\Pipelinq\Listener\TaskCreatedByCreatingListener;
use OCA\Pipelinq\Listener\TicketReferenceCreatingListener;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A created, updated or plain event reads no config, asks for no user and
 * draws no reference number.
 *
 * @covers \OCA\Pipelinq\Listener\TaskCreatedByCreatingListener
 * @covers \OCA\Pipelinq\Listener\TicketReferenceCreatingListener
 */
class CreatingListenersIgnoreOtherEventsTest extends TestCase {

	/**
	 * Events that are not ObjectCreatingEvent.
	 *
	 * @return array<int, Event> The events.
	 */
	private function otherEvents(): array {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setSchema('40');
		$entity->setObject(['title' => 'Bel terug']);

		return [new ObjectCreatedEvent($entity), new ObjectUpdatedEvent($entity, $entity), new Event()];
	}//end otherEvents()

	/**
	 * The task listener stamps no creator outside the creating hook.
	 *
	 * @return void
	 */
	public function testTheTaskListenerIgnoresOtherEvents(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('getValueString');
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->never())->method('getUser');
		$listener = new TaskCreatedByCreatingListener($config, $session);

		foreach ($this->otherEvents() as $event) {
			$listener->handle($event);
		}
	}//end testTheTaskListenerIgnoresOtherEvents()

	/**
	 * The ticket listener draws no reference outside the creating hook.
	 *
	 * @return void
	 */
	public function testTheTicketListenerIgnoresOtherEvents(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('getValueString');
		$sequence = $this->createMock(TicketReferenceSequenceInterface::class);
		$sequence->expects($this->never())->method($this->anything());
		$listener = new TicketReferenceCreatingListener($config, $sequence, $this->createMock(LoggerInterface::class));

		foreach ($this->otherEvents() as $event) {
			$listener->handle($event);
		}
	}//end testTheTicketListenerIgnoresOtherEvents()
}//end class
