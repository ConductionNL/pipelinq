<?php

/**
 * Which handler ObjectEventListener routes each object event to.
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
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Listener\ObjectEventListener;
use OCA\Pipelinq\Service\ObjectEventHandlerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A created event goes to handleCreated with its object, an updated event to
 * handleUpdated with the new and old objects, and anything else to neither.
 *
 * @covers \OCA\Pipelinq\Listener\ObjectEventListener
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 */
class ObjectEventListenerDispatchTest extends TestCase {
	/**
	 * The handler service.
	 *
	 * @var ObjectEventHandlerService&MockObject
	 */
	private ObjectEventHandlerService&MockObject $handler;

	/**
	 * The listener under test.
	 *
	 * @var ObjectEventListener
	 */
	private ObjectEventListener $listener;

	/**
	 * Build the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->handler = $this->createMock(originalClassName: ObjectEventHandlerService::class);
		$this->listener = new ObjectEventListener(handlerService: $this->handler);
	}//end setUp()

	/**
	 * A created event hands its object to handleCreated only.
	 *
	 * @return void
	 */
	public function testACreatedEventGoesToHandleCreated(): void {
		$object = new ObjectEntity();
		$this->handler->expects($this->once())->method('handleCreated')->with($this->identicalTo($object));
		$this->handler->expects($this->never())->method('handleUpdated');

		$this->listener->handle(event: new ObjectCreatedEvent(object: $object));
	}//end testACreatedEventGoesToHandleCreated()

	/**
	 * An updated event hands the new and old objects to handleUpdated only.
	 *
	 * @return void
	 */
	public function testAnUpdatedEventGoesToHandleUpdated(): void {
		$new = new ObjectEntity();
		$old = new ObjectEntity();
		$this->handler->expects($this->never())->method('handleCreated');
		$this->handler->expects($this->once())->method('handleUpdated')
			->with($this->identicalTo($new), $this->identicalTo($old));

		$this->listener->handle(event: new ObjectUpdatedEvent(newObject: $new, oldObject: $old));
	}//end testAnUpdatedEventGoesToHandleUpdated()

	/**
	 * An updated event without an old object passes null as the old object.
	 *
	 * @return void
	 */
	public function testAnUpdatedEventWithoutAnOldObjectPassesNull(): void {
		$new = new ObjectEntity();
		$this->handler->expects($this->once())->method('handleUpdated')
			->with($this->identicalTo($new), null);

		$this->listener->handle(event: new ObjectUpdatedEvent(newObject: $new, oldObject: null));
	}//end testAnUpdatedEventWithoutAnOldObjectPassesNull()

	/**
	 * A deleted event reaches neither handler.
	 *
	 * @return void
	 */
	public function testADeletedEventIsIgnored(): void {
		$this->handler->expects($this->never())->method('handleCreated');
		$this->handler->expects($this->never())->method('handleUpdated');

		$this->listener->handle(event: new ObjectDeletedEvent(object: new ObjectEntity()));
	}//end testADeletedEventIsIgnored()
}//end class
