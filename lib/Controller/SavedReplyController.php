<?php

/**
 * Pipelinq SavedReplyController.
 *
 * GET /api/saved-replies: the saved replies for a channel, filled for the
 * record the agent answers on, as template options.
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
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SavedReplyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the saved replies on offer.
 *
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-the-server-offers-saved-replies-filled-for-the-record
 */
class SavedReplyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request The request.
	 * @param SavedReplyService $replies The saved replies.
	 */
	public function __construct(
		IRequest $request,
		private readonly SavedReplyService $replies,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The saved replies of one channel. Records are read as the signed-in
	 * user, so a placeholder of a record they may not read stays unfilled.
	 *
	 * @param string $channel The channel.
	 * @param string $ticket  A ticket uuid, or ''.
	 * @param string $contact A contact uuid, or ''.
	 * @param string $client  A client uuid, or ''.
	 *
	 * @return JSONResponse `{ results: [{ uuid, name, values: { body } }] }`.
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-the-server-offers-saved-replies-filled-for-the-record
	 */
	#[NoAdminRequired]
	public function index(string $channel = '', string $ticket = '', string $contact = '', string $client = ''): JSONResponse {
		if ($channel === '') {
			return new JSONResponse(['results' => []]);
		}

		return new JSONResponse(['results' => $this->replies->forChannel(channel: $channel, ticketId: $ticket, contactId: $contact, clientId: $client)]);
	}//end index()
}//end class
