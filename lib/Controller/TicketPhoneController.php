<?php

/**
 * Pipelinq TicketPhoneController.
 *
 * Finds tickets by the phone number they are about.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PhoneHistoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * `GET /api/tickets/by-phone?number=`. Every result is read through
 * OpenRegister's object service as the signed-in user, so its RBAC decides
 * which tickets come back; there is no object id to guard here.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */
class TicketPhoneController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest            $request The request.
	 * @param PhoneHistoryService $history The phone search.
	 */
	public function __construct(
		IRequest $request,
		private readonly PhoneHistoryService $history,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The tickets about a phone number, newest first.
	 *
	 * @param string $number The number as typed.
	 *
	 * @return JSONResponse `{ number, results }`; `number` is null when the text is no phone number.
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
	 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-an-unknown-callers-earlier-contacts-are-shown-req-chp-001
	 */
	#[NoAdminRequired]
	public function byPhone(string $number = ''): JSONResponse {
		return new JSONResponse($this->history->findTickets(raw: $number));
	}//end byPhone()
}//end class
