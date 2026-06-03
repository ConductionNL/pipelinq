<?php

/**
 * Pipelinq NaviController.
 *
 * REST controller for the "Navi" conversational analytics agent. The single
 * endpoint validates input and delegates to NaviService — the controller holds
 * no aggregation logic.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/dashboard/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\NaviService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller exposing POST /api/navi/query.
 *
 * Authentication is mandated by @NoAdminRequired (no @PublicPage). Error
 * responses use static messages — internal exception details are logged but
 * never returned to the caller. Data scoping is enforced by OpenRegister inside
 * NaviService, so a user only ever queries objects they may read.
 *
 * @spec openspec/changes/dashboard/tasks.md#task-1.1
 */
class NaviController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request     The request.
     * @param NaviService     $service     The Navi analytics service.
     * @param IUserSession    $userSession The user session.
     * @param LoggerInterface $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private NaviService $service,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Answer a natural-language analytics query.
     *
     * @return JSONResponse The structured Navi response, or an error response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dashboard/tasks.md#task-1.1
     */
    public function query(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $query = $this->request->getParam('query');
        if (is_string($query) === false || trim($query) === '') {
            return new JSONResponse(['message' => 'Query is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            return new JSONResponse($this->service->processQuery(query: $query, userId: $user->getUID()));
        } catch (\Throwable $e) {
            $this->logger->error('[NaviController] query failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not process query'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end query()
}//end class
