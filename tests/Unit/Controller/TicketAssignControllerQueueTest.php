<?php

/**
 * TicketAssignController: taking the oldest waiting ticket, and unconfigured tickets.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\TicketAssignController;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The oldest open, unassigned ticket is taken; closed, assigned and id-less
 * rows are passed over; an unreadable queue or ticket is a 404, and an
 * unconfigured ticket store a 503.
 *
 * @covers \OCA\Pipelinq\Controller\TicketAssignController
 */
class TicketAssignControllerQueueTest extends TestCase {

	/**
	 * Object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Ticket service mock.
	 *
	 * @var TicketServiceInterface&MockObject
	 */
	private TicketServiceInterface&MockObject $tickets;

	/**
	 * The controller under test.
	 *
	 * @var TicketAssignController
	 */
	private TicketAssignController $controller;

	/**
	 * Build the controller for a signed-in `bob`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->tickets = $this->createMock(TicketServiceInterface::class);
		$this->tickets->method('getRegisterId')->willReturn('20');
		$this->tickets->method('getSchemaId')->willReturn('40');
		$this->tickets->method('getObjectService')->willReturn($this->objects);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->controller = new TicketAssignController($this->createMock(IRequest::class), $this->tickets, $session);
	}//end setUp()

	/**
	 * A ticket entity as OpenRegister returns it.
	 *
	 * @param string               $uuid   The UUID.
	 * @param array<string, mixed> $object The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($object);
		return $entity;
	}//end entity()

	/**
	 * Without ticket configuration nothing is looked up.
	 *
	 * @return void
	 */
	public function testAssignToMeNeedsConfiguredTickets(): void {
		$this->tickets->method('isConfigured')->willReturn(false);
		$this->objects->expects($this->never())->method('find');

		$response = $this->controller->assignToMe('t-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'Tickets are not configured'], $response->getData());
	}//end testAssignToMeNeedsConfiguredTickets()

	/**
	 * A lookup that throws is the same 404 as a missing ticket.
	 *
	 * @return void
	 */
	public function testAThrowingLookupIsNotFound(): void {
		$this->tickets->method('isConfigured')->willReturn(true);
		$this->objects->method('find')->willThrowException(new RuntimeException('no read permission'));
		$this->objects->expects($this->never())->method('saveObject');

		$response = $this->controller->assignToMe('t-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Ticket not found'], $response->getData());
	}//end testAThrowingLookupIsNotFound()

	/**
	 * Without ticket configuration the queue is not scanned.
	 *
	 * @return void
	 */
	public function testAssignOldestNeedsConfiguredTickets(): void {
		$this->tickets->method('isConfigured')->willReturn(false);
		$this->objects->expects($this->never())->method('findAll');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller->assignOldest()->getStatus());
	}//end testAssignOldestNeedsConfiguredTickets()

	/**
	 * A queue that cannot be read counts as empty.
	 *
	 * @return void
	 */
	public function testAnUnreadableQueueIsEmpty(): void {
		$this->tickets->method('isConfigured')->willReturn(true);
		$this->objects->method('findAll')->willThrowException(new RuntimeException('index offline'));
		$this->objects->expects($this->never())->method('saveObject');

		$response = $this->controller->assignOldest();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Nothing is waiting in the queue'], $response->getData());
	}//end testAnUnreadableQueueIsEmpty()

	/**
	 * The queue is scanned oldest first and the first open, unassigned entity is taken.
	 *
	 * @return void
	 */
	public function testTheOldestOpenUnassignedTicketIsTaken(): void {
		$this->tickets->method('isConfigured')->willReturn(true);
		$this->objects->expects($this->once())->method('findAll')
			->with(
				[
					'filters' => ['register' => '20', 'schema' => '40'],
					'sort' => ['occurredAt' => 'asc'],
					'limit' => 200,
				]
			)
			->willReturn(
				[
					$this->entity('t-closed', ['status' => 'resolved']),
					$this->entity('t-taken', ['status' => 'new', 'assignee' => 'carol']),
					['status' => 'new'],
					$this->entity('t-open', ['status' => 'new', 'title' => 'Broken link', '@self' => ['id' => 't-open']]),
				]
			);
		$saved = [];
		$this->objects->expects($this->once())->method('saveObject')
			->willReturnCallback(
				function (array $object) use (&$saved): ObjectEntity {
					$saved = $object;
					return $this->entity('t-open', $object);
				}
			);

		$response = $this->controller->assignOldest();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 't-open', 'assignee' => 'bob'], $response->getData());
		$this->assertSame(['id' => 't-open', 'status' => 'new', 'title' => 'Broken link', 'assignee' => 'bob'], $saved);
	}//end testTheOldestOpenUnassignedTicketIsTaken()
}//end class
