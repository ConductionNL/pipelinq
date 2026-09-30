<?php

/**
 * Pipelinq Ticket Woo Request Controller
 *
 * "Omzetten naar Woo-verzoek" on TicketDetail (hydra woo-citizen-journey J4.6,
 * C5 caller). The availability route tells the page whether to show the
 * action at all; the convert route calls dossiq's intake through
 * WooRequestConversionService and records the case on the ticket.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\WooRequestConversionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Offers and performs the conversion of a question into a Woo request.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
 */
class TicketWooRequestController extends Controller {
	/**
	 * The pipelinq schema key of the ticket supertype.
	 *
	 * @var string
	 */
	private const TICKET = 'ticket';

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request      The request.
	 * @param WooRequestConversionService $conversion   Calls dossiq's intake.
	 * @param MainRegisterReader          $tickets      Reads the ticket under the user's RBAC.
	 * @param IUserSession                $userSession  The signed-in employee.
	 * @param ObjectOwnerAccessPolicy     $accessPolicy Who may act on tickets.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function __construct(
		IRequest $request,
		private readonly WooRequestConversionService $conversion,
		private readonly MainRegisterReader $tickets,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $accessPolicy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether the ticket page may offer the conversion.
	 *
	 * @param string $id The ticket id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	#[NoAdminRequired]
	public function availability(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$ticket = $this->tickets->find(schemaKey: self::TICKET, id: $id);
		if ($ticket === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->conversion->availability(ticket: $ticket));
	}//end availability()

	/**
	 * Convert the ticket into a Woo request.
	 *
	 * @param string $id The ticket id.
	 *
	 * @return JSONResponse 200 converted; 409 not available or not convertible; 502 intake failed.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	#[NoAdminRequired]
	public function convert(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		// The same privileged-group check the case handoff uses; the ticket
		// itself is read under the employee's OpenRegister RBAC, so a ticket
		// they may not read answers 404.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$ticket = $this->tickets->find(schemaKey: self::TICKET, id: $id);
		if ($ticket === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->conversion->convert(ticketId: $id, ticket: $ticket);
		$status = match ($result['status']) {
			'not-available', 'not-convertible' => Http::STATUS_CONFLICT,
			'intake-failed' => Http::STATUS_BAD_GATEWAY,
			default => Http::STATUS_OK,
		};

		return new JSONResponse($result, $status);
	}//end convert()
}//end class
