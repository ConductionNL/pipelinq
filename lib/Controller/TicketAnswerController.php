<?php

/**
 * Pipelinq TicketAnswerController.
 *
 * POST /api/tickets/{id}/answer: the employee's answer to the customer.
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
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DomainException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\TicketAnswerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Saves an answer to the customer on a ticket.
 *
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md#requirement-the-server-saves-the-answer-to-the-customer
 */
class TicketAnswerController extends Controller {

	/**
	 * HTTP status per refusal.
	 *
	 * @var array<string, int>
	 */
	private const STATUS = [
		'empty' => Http::STATUS_BAD_REQUEST,
		'not-found' => Http::STATUS_NOT_FOUND,
		'not-answerable' => Http::STATUS_CONFLICT,
		'refused' => Http::STATUS_FORBIDDEN,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     The request.
	 * @param TicketAnswerService $answers     Saves the answer.
	 * @param IUserSession        $userSession The signed-in user.
	 */
	public function __construct(
		IRequest $request,
		private readonly TicketAnswerService $answers,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Save the answer. OpenRegister decides whether the user may change the ticket.
	 *
	 * The body carries `waitForCustomer`: whether the ticket then waits for the customer.
	 *
	 * @param string $id      The ticket uuid.
	 * @param string $message The answer.
	 *
	 * @return JSONResponse `{ message, ticket }`, or `{ status }` naming the refusal.
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md#requirement-the-server-saves-the-answer-to-the-customer
	 */
	#[NoAdminRequired]
	public function answer(string $id, string $message = ''): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['status' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$wait = filter_var($this->request->getParam('waitForCustomer', false), FILTER_VALIDATE_BOOLEAN);
			$ticket = $this->answers->answer(id: $id, message: $message, waitForCustomer: $wait);
		} catch (DomainException $e) {
			return new JSONResponse(['status' => $e->getMessage()], (self::STATUS[$e->getMessage()] ?? Http::STATUS_BAD_REQUEST));
		}

		return new JSONResponse(['message' => trim($message), 'ticket' => $ticket]);
	}//end answer()
}//end class
