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

		$data = $ticket->getObject();
		unset($data['@self']);
		$data['assignee'] = $user->getUID();

		try {
			$objectService->saveObject(object: $data, register: $register, schema: $schema, uuid: $id);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'You cannot change this ticket'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['id' => $id, 'assignee' => $user->getUID()]);
	}//end assignToMe()
}//end class
