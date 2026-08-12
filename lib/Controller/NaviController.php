<?php

/**
 * Pipelinq NaviController.
 *
 * Thin REST controller fronting the Navi AI analytics agent (see
 * `NaviService`). Receives a single JSON body of the shape
 * `{ query, conversationId? }`, delegates to the service, and returns a
 * structured response envelope (`resultType` + optional chart/table data).
 *
 * The controller owns the conversation identifier: it validates the
 * client-supplied value against the minted shape and mints a fresh one
 * whenever the client sends nothing or sends something else, so the response
 * always carries a usable identifier and an attacker-chosen string never
 * reaches the conversation store as a key.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\NaviService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the Navi AI conversational analytics endpoint.
 *
 * Authenticated users only (per the attribute on `query()`). All business
 * logic lives in `NaviService`; this controller stays under 10 lines per
 * method per ADR-003.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
 */
class NaviController extends Controller {
	/**
	 * The only accepted shape for a conversation identifier: the 32 lower-case
	 * hex characters produced by `bin2hex(random_bytes(16))`. A value that does
	 * not match is treated as absent, so a caller can never choose the string
	 * that becomes part of a conversation-store key.
	 *
	 * @var string
	 */
	public const CONVERSATION_ID_PATTERN = '/^[0-9a-f]{32}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param NaviService $naviService Navi orchestrator service.
	 * @param IUserSession $userSession Active user session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private NaviService $naviService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/navi/query.
	 *
	 * Body: `{ "query": "...", "conversationId": "..." }`.
	 * Missing/empty `query` -> 400. Unauthenticated -> 401. Underlying
	 * failure -> 500 with a static message (no `getMessage()` leak).
	 *
	 * The returned payload always carries a non-null `conversationId`: the
	 * client's value when it matches the minted shape, a freshly minted one
	 * otherwise. That identifier is handed to the service so follow-up turns
	 * can be answered against the accumulated conversation.
	 *
	 * @return JSONResponse Response envelope or error.
	 *
	 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
	 */
	#[NoAdminRequired]
	public function query(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$query = trim((string)$this->request->getParam('query', ''));
		if ($query === '') {
			return new JSONResponse(['message' => 'Missing query'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$conversationId = $this->resolveConversationId(raw: $this->request->getParam('conversationId', ''));
			$payload = $this->naviService->processQuery(
				query: $query,
				userId: $user->getUID(),
				conversationId: $conversationId
			);
			$payload['conversationId'] = $conversationId;

			return new JSONResponse($payload);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[NaviController] query failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Navi unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end query()

	/**
	 * Accept a client-supplied conversation identifier, or mint a fresh one.
	 *
	 * Only the shape this controller itself mints is accepted. Anything else —
	 * absent, empty, wrong length, non-hex, or not a string at all — yields a
	 * new identifier, so an unvalidated caller string is never used as part of
	 * a cache key.
	 *
	 * @param mixed $raw The raw request parameter.
	 *
	 * @return string A 32-character lower-case hex identifier.
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 *
	 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
	 */
	private function resolveConversationId(mixed $raw): string {
		if (is_string($raw) === true && preg_match(self::CONVERSATION_ID_PATTERN, $raw) === 1) {
			return $raw;
		}

		return bin2hex(random_bytes(16));
	}//end resolveConversationId()
}//end class
