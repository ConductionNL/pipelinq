<?php

/**
 * Unit tests for TicketAssignController ("Assign to me").
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\TicketAssignController;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A ticket is assigned to the signed-in user, under their RBAC.
 */
class TicketAssignControllerTest extends TestCase {

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * Build the controller for a signed-in user (or none).
	 *
	 * @param string|null $uid The user id, or null for no session.
	 *
	 * @return TicketAssignController The controller.
	 */
	private function controller(?string $uid = 'alice'): TicketAssignController {
		$this->objects = $this->createMock(ObjectServiceInterface::class);

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(true);
		$tickets->method('getRegisterId')->willReturn('20');
		$tickets->method('getSchemaId')->willReturn('40');
		$tickets->method('getObjectService')->willReturn($this->objects);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new TicketAssignController($this->createMock(IRequest::class), $tickets, $session);
	}//end controller()

	/**
	 * A stored ticket.
	 *
	 * @return ObjectEntity The ticket.
	 */
	private function ticket(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('t-1');
		$entity->setObject(['title' => 'Late delivery', 'ticketType' => 'complaint', 'status' => 'new']);
		return $entity;
	}//end ticket()

	/**
	 * The ticket is saved with the user as assignee, keeping its other fields.
	 *
	 * @return void
	 */
	public function testAssignsTheSignedInUser(): void {
		$controller = $this->controller();
		$this->objects->method('find')->willReturn($this->ticket());
		$this->objects->expects($this->once())->method('saveObject')->with(
			$this->callback(static fn (array $object): bool => $object['assignee'] === 'alice'
				&& $object['ticketType'] === 'complaint'
				&& $object['title'] === 'Late delivery'),
			$this->anything(),
			'20',
			'40',
			't-1',
		)->willReturn($this->ticket());

		$response = $controller->assignToMe(id: 't-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 't-1', 'assignee' => 'alice'], $response->getData());
	}//end testAssignsTheSignedInUser()

	/**
	 * A ticket the user cannot read answers 404 and nothing is written.
	 *
	 * @return void
	 */
	public function testUnreadableTicketIsNotFound(): void {
		$controller = $this->controller();
		$this->objects->method('find')->willReturn(null);
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->assignToMe(id: 't-x')->getStatus());
	}//end testUnreadableTicketIsNotFound()

	/**
	 * A ticket the user may read but not change answers 403.
	 *
	 * @return void
	 */
	public function testRefusedWriteIsForbidden(): void {
		$controller = $this->controller();
		$this->objects->method('find')->willReturn($this->ticket());
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('no update permission'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->assignToMe(id: 't-1')->getStatus());
	}//end testRefusedWriteIsForbidden()

	/**
	 * Without a session nothing is read.
	 *
	 * @return void
	 */
	public function testNoSessionIsUnauthorized(): void {
		$controller = $this->controller(uid: null);
		$this->objects->expects($this->never())->method('find');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->assignToMe(id: 't-1')->getStatus());
	}//end testNoSessionIsUnauthorized()
}//end class
