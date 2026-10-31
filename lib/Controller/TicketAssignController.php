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
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\TicketService;
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
	 * Constructor.
	 *
	 * @param IRequest      $request     The request.
	 * @param TicketService $tickets     Register and schema of the ticket supertype, and OpenRegister.
	 * @param IUserSession  $userSession The signed-in user.
	 */
	public function __construct(
		IRequest $request,
		private readonly TicketService $tickets,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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
	 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function assignToMe(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->tickets->isConfigured() === false) {
			return new JSONResponse(['error' => 'Tickets are not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->tickets->getRegisterId();
		$schema = $this->tickets->getSchemaId();
		$objectService = $this->tickets->getObjectService();

		try {
			$ticket = $objectService->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable) {
			$ticket = null;
		}

		if ($id === '' || $ticket === null) {
			return new JSONResponse(['error' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
		}

		return $this->assign(ticket: $ticket->getObject(), id: $id, uid: $user->getUID());
	}//end assignToMe()

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
	 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/my-work/spec.md
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

		try {
			$rows = $this->tickets->getObjectService()->findAll(
				config: [
					'filters' => ['register' => $this->tickets->getRegisterId(), 'schema' => $this->tickets->getSchemaId()],
					'sort' => ['occurredAt' => 'asc'],
					'limit' => self::QUEUE_SCAN,
				]
			);
		} catch (Throwable) {
			$rows = [];
		}

		foreach ($rows as $row) {
			$data = $row;
			if (is_array($row) === false) {
				$data = $row->getObject();
				$data['id'] = $data['id'] ?? $row->getUuid();
			}

			$id = (string) ($data['id'] ?? '');
			if ($id === '' || empty($data['assignee']) === false || in_array($data['status'] ?? '', self::CLOSED_STATUSES, true) === true) {
				continue;
			}

			return $this->assign(ticket: $data, id: $id, uid: $user->getUID());
		}

		return new JSONResponse(['error' => 'Nothing is waiting in the queue'], Http::STATUS_NOT_FOUND);
	}//end assignOldest()

	/**
	 * Save a ticket with a new assignee.
	 *
	 * @param array<string, mixed> $ticket The ticket's data.
	 * @param string               $id     The ticket id.
	 * @param string               $uid    The new assignee.
	 *
	 * @return JSONResponse The ticket's id and new assignee, or 403 when the write is refused.
	 */
	private function assign(array $ticket, string $id, string $uid): JSONResponse {
		unset($ticket['@self']);
		$ticket['assignee'] = $uid;

		try {
			$this->tickets->getObjectService()->saveObject(
				object: $ticket,
				register: $this->tickets->getRegisterId(),
				schema: $this->tickets->getSchemaId(),
				uuid: $id
			);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'You cannot change this ticket'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['id' => $id, 'assignee' => $uid]);
	}//end assign()
}//end class
