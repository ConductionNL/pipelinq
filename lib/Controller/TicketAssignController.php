<?php

/**
 * Pipelinq TicketAssignController.
 *
 * "Assign to me" on a ticket. A ticket lands in someone's My Work when its
 * `assignee` is their user id, and it leaves the Queue at the same moment.
 * Until now the only ways to set it were the inline edit of the field or a
 * routing suggestion, so a person picking up work from the Queue had no
 * direct action for it (pipelinq review G1).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Assigns a ticket to the signed-in user.
 *
 * @spec openspec/specs/my-work/spec.md
 */
class TicketAssignController extends Controller {

	/**
	 * The statuses that close a ticket; a closed ticket is not in the queue.
	 *
	 * @var string[]
	 */
	private const CLOSED_STATUSES = ['resolved', 'completed', 'rejected', 'converted', 'closed'];

	/**
	 * How many of the oldest tickets Take the oldest reads to find one nobody has.
	 *
	 * @var integer
	 */
	private const QUEUE_SCAN = 200;

	/**
	 * The longest user id a handover accepts.
	 *
	 * @var integer
	 */
	private const MAX_ASSIGNEE = 64;

	/**
	 * The longest handover note, as the schema's maxLength.
	 *
	 * @var integer
	 */
	private const MAX_NOTE = 1000;

	/**
	 * Constructor.
	 *
	 * @param IRequest      $request     The request.
	 * @param TicketServiceInterface $tickets     Register and schema of the ticket supertype, and OpenRegister.
	 * @param IUserSession  $userSession The signed-in user.
	 */
	public function __construct(
		IRequest $request,
		private readonly TicketServiceInterface $tickets,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Make the signed-in user the ticket's assignee.
	 *
	 * The ticket is read and written under the user's own OpenRegister RBAC:
	 * a ticket they may not read answers 404, one they may not change
	 * answers 403. Nothing here widens what a user may do; it only spares
	 * them typing their own user id.
	 *
	 * @param string $id The ticket id.
	 *
	 * @return JSONResponse The ticket's id and new assignee, or an error.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function assignToMe(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->reassign(id: $id, changes: ['assignee' => $user->getUID()]);
	}//end assignToMe()

	/**
	 * Pass a ticket to a colleague, with a note for them.
	 *
	 * The new assignee and the note are written in ONE save, so OpenRegister's
	 * audit trail records them in the same entry and the History tab shows the
	 * note under "Passed to <assignee> by <actor>". An empty note clears the
	 * previous one, so an old note never reads as this handover's. Read and
	 * written under the user's own OpenRegister RBAC, as {@see assignToMe()}.
	 *
	 * @param string $id           The ticket id.
	 * @param string $assignee     The colleague's user id.
	 * @param string $handoverNote What the colleague should know; may be empty.
	 *
	 * @return JSONResponse The ticket's id and new assignee, or an error.
	 *
	 * @spec openspec/changes/ticket-handover-history/specs/ticket-handover-history/spec.md#REQ-THH-001
	 */
	#[NoAdminRequired]
	public function passOn(string $id='', string $assignee='', string $handoverNote=''): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$assignee = trim($assignee);
		$note = trim($handoverNote);
		if ($assignee === '' || mb_strlen($assignee) > self::MAX_ASSIGNEE || mb_strlen($note) > self::MAX_NOTE) {
			return new JSONResponse(['error' => 'Choose a colleague, and keep the note under 1000 characters'], Http::STATUS_BAD_REQUEST);
		}

		return $this->reassign(id: $id, changes: ['assignee' => $assignee, 'handoverNote' => $note]);
	}//end passOn()

	/**
	 * Read a ticket and save it with the given fields changed.
	 *
	 * @param string               $id      The ticket id.
	 * @param array<string, mixed> $changes The fields to set, carrying the new `assignee`.
	 *
	 * @return JSONResponse The ticket's id and new assignee, or an error.
	 *
	 * @spec openspec/changes/ticket-handover-history/specs/ticket-handover-history/spec.md#REQ-THH-001
	 */
	private function reassign(string $id, array $changes): JSONResponse {
		if ($this->tickets->isConfigured() === false) {
			return new JSONResponse(['error' => 'Tickets are not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$ticket = $this->findTicket(id: $id);
		if ($ticket === null) {
			return new JSONResponse(['error' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
		}

		return $this->assign(ticket: array_replace($ticket, $changes), id: $id);
	}//end reassign()

	/**
	 * A ticket's data, read under the user's RBAC; null when absent or unreadable.
	 *
	 * @param string $id The ticket id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/ticket-handover-history/specs/ticket-handover-history/spec.md#REQ-THH-001
	 */
	private function findTicket(string $id): array|null {
		if ($id === '') {
			return null;
		}

		try {
			$ticket = $this->tickets->getObjectService()->find(
				id: $id,
				options: new ObjectFindOptions(register: $this->tickets->getRegisterId(), schema: $this->tickets->getSchemaId())
			);
		} catch (Throwable) {
			return null;
		}

		if ($ticket === null) {
			return null;
		}

		return $ticket->getObject();
	}//end findTicket()

	/**
	 * Make the signed-in user the assignee of the oldest ticket in the queue.
	 *
	 * The queue is every open ticket nobody has picked up. The oldest is the
	 * one that came in first (`occurredAt`). Read and written under the user's
	 * own OpenRegister RBAC, as {@see assignToMe()}: a queue they cannot read
	 * is empty to them.
	 *
	 * @return JSONResponse The ticket's id and new assignee, 404 when nothing waits, or an error.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function assignOldest(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->tickets->isConfigured() === false) {
			return new JSONResponse(['error' => 'Tickets are not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$oldest = $this->oldestUnassigned(rows: $this->queueRows());
		if ($oldest === null) {
			return new JSONResponse(['error' => 'Nothing is waiting in the queue'], Http::STATUS_NOT_FOUND);
		}

		return $this->assign(ticket: array_replace($oldest[0], ['assignee' => $user->getUID()]), id: $oldest[1]);
	}//end assignOldest()

	/**
	 * The queue's tickets, oldest first; none when the read fails.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	private function queueRows(): array {
		try {
			return $this->tickets->getObjectService()->findAll(
				config: [
					'filters' => ['register' => $this->tickets->getRegisterId(), 'schema' => $this->tickets->getSchemaId()],
					'sort' => ['occurredAt' => 'asc'],
					'limit' => self::QUEUE_SCAN,
				]
			);
		} catch (Throwable) {
			return [];
		}
	}//end queueRows()

	/**
	 * The first open, unassigned ticket and its id.
	 *
	 * @param array<mixed> $rows The queue's tickets, oldest first.
	 *
	 * @return array{0: array<string, mixed>, 1: string}|null
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	private function oldestUnassigned(array $rows): array|null {
		foreach ($rows as $row) {
			$data = $this->ticketData(row: $row);
			$id = (string) ($data['id'] ?? '');
			if ($id === '' || empty($data['assignee']) === false || in_array($data['status'] ?? '', self::CLOSED_STATUSES, true) === true) {
				continue;
			}

			return [$data, $id];
		}

		return null;
	}//end oldestUnassigned()

	/**
	 * A queue row's ticket data, with its id.
	 *
	 * @param mixed $row The queue row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	private function ticketData($row): array {
		$data = $row;
		if (is_array($row) === false) {
			$data = $row->getObject();
			$data['id'] = $data['id'] ?? $row->getUuid();
		}

		return $data;
	}//end ticketData()

	/**
	 * Save a ticket with a new assignee.
	 *
	 * @param array<string, mixed> $ticket The ticket's data, carrying its new `assignee`.
	 * @param string               $id     The ticket id.
	 *
	 * @return JSONResponse The ticket's id and new assignee, or 403 when the write is refused.
	 */
	private function assign(array $ticket, string $id): JSONResponse {
		unset($ticket['@self']);

		try {
			$this->tickets->getObjectService()->saveObject(
				object: $ticket,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(
							register: $this->tickets->getRegisterId(),
							schema: $this->tickets->getSchemaId()
						),
					uuid: $id
				)
			);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'You cannot change this ticket'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['id' => $id, 'assignee' => $ticket['assignee']]);
	}//end assign()
}//end class
