<?php

/**
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
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Kernel\Contracts\QuestionAnsweredNoticeInterface;
use OCA\Pipelinq\Kernel\Contracts\SchemaMapServiceInterface;
use OCA\Pipelinq\Listener\QuestionAnsweredListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A ticket update that answers the question tells the asker; nothing else does.
 *
 * @covers \OCA\Pipelinq\Listener\QuestionAnsweredListener
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 */
class QuestionAnsweredListenerTest extends TestCase {

	/**
	 * The notice.
	 *
	 * @var QuestionAnsweredNoticeInterface&MockObject
	 */
	private QuestionAnsweredNoticeInterface&MockObject $notice;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the listener; schema `sch-ticket` maps to tickets, anything else to leads.
	 *
	 * @return QuestionAnsweredListener
	 */
	private function listener(): QuestionAnsweredListener {
		$map = $this->createMock(SchemaMapServiceInterface::class);
		$map->method('resolveEntityType')->willReturnCallback(
			static fn (?string $schemaId): ?string => ($schemaId === 'sch-ticket' ? 'ticket' : 'lead')
		);

		$this->notice = $this->createMock(QuestionAnsweredNoticeInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new QuestionAnsweredListener($map, $this->notice, $this->logger);
	}//end listener()

	/**
	 * An object entity.
	 *
	 * @param array<string, mixed> $data   The object data.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $schema = 'sch-ticket'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setUuid('t-1');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An answered ticket is told about, with its new data and uuid.
	 *
	 * @return void
	 */
	public function testAnAnsweredTicketIsTold(): void {
		$listener = $this->listener();
		$this->notice->expects($this->once())->method('isAnswered')
			->with(['status' => 'open', 'id' => 't-1'], ['status' => 'answered', 'id' => 't-1'])->willReturn(true);
		$this->notice->expects($this->once())->method('tell')->with(['status' => 'answered', 'id' => 't-1'], 't-1')->willReturn(true);

		$listener->handle(new ObjectUpdatedEvent(newObject: $this->entity(['status' => 'answered']), oldObject: $this->entity(['status' => 'open'])));
	}//end testAnAnsweredTicketIsTold()

	/**
	 * An update that does not answer the question tells nobody.
	 *
	 * @return void
	 */
	public function testAnUnansweredUpdateTellsNobody(): void {
		$listener = $this->listener();
		$this->notice->method('isAnswered')->willReturn(false);
		$this->notice->expects($this->never())->method('tell');

		$listener->handle(new ObjectUpdatedEvent(newObject: $this->entity(['status' => 'open']), oldObject: $this->entity(['status' => 'new'])));
	}//end testAnUnansweredUpdateTellsNobody()

	/**
	 * Other schemas, a missing old object and other events are ignored.
	 *
	 * @return void
	 */
	public function testOtherUpdatesAreIgnored(): void {
		$listener = $this->listener();
		$this->notice->expects($this->never())->method('isAnswered');
		$this->notice->expects($this->never())->method('tell');

		$listener->handle(new ObjectUpdatedEvent(newObject: $this->entity(['status' => 'won'], 'sch-lead'), oldObject: $this->entity([], 'sch-lead')));
		$listener->handle(new ObjectUpdatedEvent(newObject: $this->entity(['status' => 'answered']), oldObject: null));
		$listener->handle(new Event());
	}//end testOtherUpdatesAreIgnored()

	/**
	 * A failing notice is logged and never escapes the listener.
	 *
	 * @return void
	 */
	public function testAFailingNoticeIsLogged(): void {
		$listener = $this->listener();
		$this->notice->method('isAnswered')->willReturn(true);
		$this->notice->method('tell')->willThrowException(new RuntimeException('mail down'));
		$this->logger->expects($this->once())->method('warning')
			->with('QuestionAnsweredListener: the answer notice was skipped', ['error' => 'mail down']);

		$listener->handle(new ObjectUpdatedEvent(newObject: $this->entity(['status' => 'answered']), oldObject: $this->entity(['status' => 'open'])));
	}//end testAFailingNoticeIsLogged()
}//end class
