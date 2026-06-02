<?php

/**
 * Pipelinq KccWerkplekController.
 *
 * Controller exposing the aggregated KCC (Klant Contact Centrum) agent
 * workspace state and the agent's own availability toggle. Read-aggregation
 * + self-scoped availability only — object CRUD is handled by OpenRegister's
 * generic object API.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\KccWerkplekService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the KCC werkplek API.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
 */
#[NoAdminRequired]
class KccWerkplekController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request            The request.
     * @param KccWerkplekService $kccWerkplekService The werkplek aggregation service.
     * @param IUserSession       $userSession        The user session.
     * @param LoggerInterface    $logger             The logger.
     */
    public function __construct(
        IRequest $request,
        private KccWerkplekService $kccWerkplekService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the aggregated workspace state for the authenticated agent.
     *
     * @return JSONResponse Shape on success: { agentProfile, assignedRequests,
     *                      openTasks, queueCounts, workload }.
     *                      401 when unauthenticated; 500 on unexpected failure.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2.2
     */
    #[NoAdminRequired]
    public function state(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $result = $this->kccWerkplekService->getWorkspaceState(userId: $user->getUID());
        } catch (\Throwable $e) {
            // NEVER expose $e->getMessage() to the client — log full context here.
            $this->logger->error(
                'KccWerkplekController: state failed',
                ['exception' => $e]
            );
            return new JSONResponse(
                ['message' => 'Operation failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse($result, Http::STATUS_OK);
    }//end state()

    /**
     * Set the authenticated agent's availability.
     *
     * The agent identity is derived server-side from the session — a caller
     * can only toggle their own availability (ADR-005).
     *
     * @return JSONResponse The updated agentProfile payload on success;
     *                      400 on a missing/invalid body; 401 when
     *                      unauthenticated; 500 on unexpected failure.
     *
     * @spec openspec/changes/kcc-werkplek/tasks.md#task-2.2
     */
    #[NoAdminRequired]
    public function setAvailability(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $raw = $this->request->getParam('isAvailable', null);
        if (is_bool($raw) === false) {
            return new JSONResponse(
                ['message' => 'isAvailable must be a boolean'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->kccWerkplekService->setAvailability(
                userId: $user->getUID(),
                available: $raw
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'KccWerkplekController: setAvailability failed',
                ['exception' => $e]
            );
            return new JSONResponse(
                ['message' => 'Operation failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse($result, Http::STATUS_OK);
    }//end setAvailability()
}//end class
