<?php

/**
 * Pipelinq FirstTicketController.
 *
 * GET /api/worklist/first: the ticket the current user should take first
 * today, for the dashboard's "First, today" card.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://pipelinq.nl
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\FirstTicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The current user's first ticket of the day.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class FirstTicketController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param FirstTicketService $firstTicket Picks the ticket.
	 * @param IUserSession $userSession The current user.
	 */
	public function __construct(
		IRequest $request,
		private readonly FirstTicketService $firstTicket,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The first open ticket assigned to the current user, `{found: false}`
	 * when there is none. Only the caller's own tickets are read.
	 *
	 * @return JSONResponse The ticket row with `found: true`, or `{found: false}`.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$row = $this->firstTicket->firstFor(userId: $user->getUID());
		if ($row === null) {
			return new JSONResponse(data: ['found' => false]);
		}

		return new JSONResponse(data: array_merge(['found' => true], $row));
	}//end show()
}//end class
