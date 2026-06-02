<?php

/**
 * Pipelinq RapportageController.
 *
 * Thin controller exposing the lead-management analytics endpoint. CRUD on lead
 * objects is handled by OpenRegister's generic object API; this controller only
 * serves the server-side aggregated pipeline statistics for the dashboard.
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
 * @spec openspec/changes/lead-management/tasks.md#6.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\RapportageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the /api/rapportage/pipeline-stats analytics endpoint.
 *
 * Accessible to any authenticated user (#[NoAdminRequired]); pipeline analytics
 * is a day-to-day business feature, not an admin configuration operation
 * (ADR-005). Internal exception details are logged but never returned.
 *
 * @spec openspec/changes/lead-management/tasks.md#6.2
 */
class RapportageController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request.
     * @param RapportageService $rapportageService The analytics aggregation service.
     * @param IUserSession      $userSession       The user session.
     * @param LoggerInterface   $logger            The logger.
     */
    public function __construct(
        IRequest $request,
        private RapportageService $rapportageService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return aggregated pipeline analytics for the rapportage dashboard.
     *
     * Reads the optional `pipeline` query parameter to scope the stage-value
     * funnel to a single pipeline.
     *
     * @return JSONResponse The aggregated analytics or an error response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/lead-management/tasks.md#6.2
     */
    #[NoAdminRequired]
    public function getPipelineStats(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $pipeline = $this->request->getParam('pipeline');
        if ($pipeline !== null) {
            $pipeline = (string) $pipeline;
        }

        try {
            $stats = $this->rapportageService->getPipelineStats(pipelineId: $pipeline);
            return new JSONResponse($stats);
        } catch (\Throwable $e) {
            $this->logger->error(
                'RapportageController: failed to load pipeline statistics',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['message' => 'Failed to load pipeline statistics'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end getPipelineStats()
}//end class
