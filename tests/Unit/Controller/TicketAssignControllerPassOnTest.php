<?php

/**
 * Unit tests for TicketAssignController::passOn (a handover with a note).
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
 * @spec openspec/changes/ticket-handover-history/specs/ticket-handover-history/spec.md#REQ-THH-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\TicketAssignController;
use OCA\Pipelinq\Service\TicketService;
use OCA\Pipelinq\Tests\Support\LegacyObjectArgs;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A ticket is passed to a colleague with a note, in one save.
 */
class TicketAssignControllerPassOnTest extends TestCase {

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * Build the controller for a signed-in user (or none).
	 *
	 * @param boolean $signedIn Whether a user is signed in.
	 *
	 * @return TicketAssignController The controller.
	 */
	private function controller(bool $signedIn=true): TicketAssignController {
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);

		$tickets = $this->createMock(originalClassName: TicketService::class);
		$tickets->method('isConfigured')->willReturn(true);
		$tickets->method('getRegisterId')->willReturn('20');
		$tickets->method('getSchemaId')->willReturn('40');
		$tickets->method('getObjectService')->willReturn($this->objects);

		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('sanne');
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new TicketAssignController(request: $this->createMock(originalClassName: IRequest::class), tickets: $tickets, userSession: $session);
	}//end controller()

	/**
	 * A stored ticket, assigned to Sanne with an earlier note.
	 *
	 * @return ObjectEntity The ticket.
	 */
	private function ticket(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('t-1');
		$entity->setObject(['title' => 'Permit question', 'status' => 'in_progress', 'assignee' => 'sanne', 'handoverNote' => 'Old note']);
		return $entity;
	}//end ticket()

	/**
	 * Expect one save whose object passes the check.
	 *
	 * @param callable $check The check on the saved object.
	 *
	 * @return void
	 */
	private function expectSave(callable $check): void {
		$this->objects->expects($this->once())->method('saveObject')->with(...LegacyObjectArgs::with(method: 'saveObject', expected: [
				'object' => $this->callback(callback: $check),
				'extend' => $this->anything(),
				'register' => '20',
				'schema' => '40',
				'uuid' => 't-1',
			]))->willReturn($this->ticket());
	}//end expectSave()

	/**
	 * The new assignee and the note are saved together, the other fields kept.
	 *
	 * @return void
	 */
	public function testPassesTheTicketWithItsNoteInOneSave(): void {
		$controller = $this->controller();
		$this->objects->method('find')->willReturn($this->ticket());
		$this->expectSave(check: static fn (array $object): bool => $object['assignee'] === 'joris'
			&& $object['handoverNote'] === 'Needs the permit number'
			&& $object['title'] === 'Permit question'
			&& isset($object['@self']) === false);

		$response = $controller->passOn(id: 't-1', assignee: ' joris ', handoverNote: "  Needs the permit number\n");

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['id' => 't-1', 'assignee' => 'joris'], actual: $response->getData());
	}//end testPassesTheTicketWithItsNoteInOneSave()

	/**
	 * A handover without a note clears the earlier note, so it never reads as this one's.
	 *
	 * @return void
	 */
	public function testAHandoverWithoutANoteClearsTheOldOne(): void {
		$controller = $this->controller();
		$this->objects->method('find')->willReturn($this->ticket());
		$this->expectSave(check: static fn (array $object): bool => $object['assignee'] === 'joris' && $object['handoverNote'] === '');

		$this->assertSame(expected: Http::STATUS_OK, actual: $controller->passOn(id: 't-1', assignee: 'joris')->getStatus());
	}//end testAHandoverWithoutANoteClearsTheOldOne()

	/**
	 * The requests refused before anything is read or written.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: boolean, 4: integer}>
	 */
	public static function refusedRequests(): array {
		return [
			'no colleague' => ['t-1', '  ', 'note', true, Http::STATUS_BAD_REQUEST],
			'note too long' => ['t-1', 'joris', str_repeat('a', 1001), true, Http::STATUS_BAD_REQUEST],
			'user id too long' => ['t-1', str_repeat('u', 65), '', true, Http::STATUS_BAD_REQUEST],
			'not signed in' => ['t-1', 'joris', '', false, Http::STATUS_UNAUTHORIZED],
			'no ticket id' => ['', 'joris', '', true, Http::STATUS_NOT_FOUND],
		];
	}//end refusedRequests()

	/**
	 * A refused request writes nothing.
	 *
	 * @param string  $id       The ticket id.
	 * @param string  $assignee The colleague.
	 * @param string  $note     The note.
	 * @param boolean $signedIn Whether a user is signed in.
	 * @param integer $status   The expected status.
	 *
	 * @dataProvider refusedRequests
	 *
	 * @return void
	 */
	public function testRefusedRequestsWriteNothing(string $id, string $assignee, string $note, bool $signedIn, int $status): void {
		$controller = $this->controller(signedIn: $signedIn);
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertSame(expected: $status, actual: $controller->passOn(id: $id, assignee: $assignee, handoverNote: $note)->getStatus());
	}//end testRefusedRequestsWriteNothing()

	/**
	 * A ticket the user may not read answers 404, one they may not change 403.
	 *
	 * @return void
	 */
	public function testUnreadableIsNotFoundAndRefusedWriteIsForbidden(): void {
		$controller = $this->controller();
		$this->objects->method('find')->willThrowException(new RuntimeException('no read permission'));
		$this->objects->expects($this->never())->method('saveObject');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->passOn(id: 't-x', assignee: 'joris')->getStatus());

		$controller = $this->controller();
		$this->objects->method('find')->willReturn($this->ticket());
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('no update permission'));
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->passOn(id: 't-1', assignee: 'joris')->getStatus());
	}//end testUnreadableIsNotFoundAndRefusedWriteIsForbidden()
}//end class
