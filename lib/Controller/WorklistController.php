<?php

/**
 * Pipelinq WorklistController.
 *
 * Thin REST controller exposing the canonical "my work" union (current
 * user's leads + requests) for authenticated users. Replaces the union
 * logic that was duplicated client-side between the MyWorkWidget
 * dashboard widget and the MyWork page.
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
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\WorklistService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the "my work" worklist endpoint feeding the dashboard
 * MyWork widget (top-5) and the MyWork page (full list).
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 */
class WorklistController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request         The HTTP request.
     * @param WorklistService $worklistService Worklist union service.
     * @param IUserSession    $userSession     Active user session.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        IRequest $request,
        private WorklistService $worklistService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/worklist/mine.
     *
     * The current user's worklist: union of leads + requests assigned to
     * the session user (assignee scoping is applied server-side; callers
     * cannot request another user's worklist). Optional `?limit=` caps
     * the returned rows (the dashboard widget uses 5) while `total`,
     * `leadCount` and `requestCount` always reflect the full union.
     * Invalid limit -> 400. OpenRegister outage -> 500 with a static
     * message.
     *
     * @return JSONResponse The worklist payload, or an error envelope.
     *
     * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
     */
    #[NoAdminRequired]
    public function mine(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $limitParam = $this->request->getParam('limit', '');
        $limit      = null;
        if ($limitParam !== '' && $limitParam !== null) {
            if (is_string($limitParam) === false || ctype_digit($limitParam) === false || ((int) $limitParam) < 1) {
                return new JSONResponse(['message' => 'Invalid limit'], Http::STATUS_BAD_REQUEST);
            }

            $limit = (int) $limitParam;
        }

        try {
            return new JSONResponse($this->worklistService->getMine(userId: $user->getUID(), limit: $limit));
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[WorklistController] mine failed',
                context: ['error' => $e->getMessage()]
            );
            return new JSONResponse(['message' => 'Worklist unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end mine()
}//end class
