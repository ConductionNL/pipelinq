<?php

/**
 * Pipelinq NaviController.
 *
 * Thin REST controller fronting the Navi AI analytics agent (see
 * `NaviService`). Receives a single JSON body of the shape
 * `{ query, conversationId? }`, delegates to the service, and returns a
 * structured response envelope (`resultType` + optional chart/table data).
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
 * @spec openspec/changes/dashboard/tasks.md#task-1.1
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
 * @spec openspec/changes/dashboard/tasks.md#task-1.1
 */
class NaviController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request     HTTP request.
     * @param NaviService     $naviService Navi orchestrator service.
     * @param IUserSession    $userSession Active user session.
     * @param LoggerInterface $logger      Logger.
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
     * @return JSONResponse Response envelope or error.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-1.1
     */
    #[NoAdminRequired]
    public function query(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $query = trim((string) $this->request->getParam('query', ''));
        if ($query === '') {
            return new JSONResponse(['message' => 'Missing query'], Http::STATUS_BAD_REQUEST);
        }

        $conversationId = (string) $this->request->getParam('conversationId', '');

        try {
            $payload = $this->naviService->processQuery(query: $query, userId: $user->getUID());
            $payload['conversationId'] = null;
            if ($conversationId !== '') {
                $payload['conversationId'] = $conversationId;
            }

            return new JSONResponse($payload);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[NaviController] query failed',
                context: ['error' => $e->getMessage()]
            );
            return new JSONResponse(['message' => 'Navi unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end query()
}//end class
