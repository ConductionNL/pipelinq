<?php

/**
 * Pipelinq TicketPhoneController.
 *
 * The phone search over tickets: `GET /api/tickets/by-phone?number=`.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PhoneHistoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Answers the tickets about a phone number, for the Tickets list search and
 * the earlier contacts of an unknown caller (design D3, D4).
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
 */
class TicketPhoneController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request      The request.
	 * @param PhoneHistoryService $phoneHistory The phone search over tickets.
	 * @param IUserSession        $userSession  The signed-in user.
	 */
	public function __construct(
		IRequest $request,
		private readonly PhoneHistoryService $phoneHistory,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The tickets about a number, newest first.
	 *
	 * No guard beyond a session: every ticket is read through OpenRegister as
	 * the caller, so the answer holds only tickets the caller may read.
	 *
	 * @param string $number The number as typed.
	 *
	 * @return JSONResponse `{number, results, total}`; 400 without a number,
	 *                      401 without a session.
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
	 */
	#[NoAdminRequired]
	public function byPhone(string $number = ''): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if (trim($number) === '') {
			return new JSONResponse(['error' => 'A phone number is required'], Http::STATUS_BAD_REQUEST);
		}

		$found = $this->phoneHistory->findTickets(raw: $number);

		return new JSONResponse(
			[
				'number' => $found['number'],
				'results' => $found['tickets'],
				'total' => count($found['tickets']),
			]
		);
	}//end byPhone()
}//end class
