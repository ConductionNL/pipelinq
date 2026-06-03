<?php

/**
 * Pipelinq AnalyticsController.
 *
 * REST controller exposing pre-aggregated cross-module analytics for the
 * unified dashboard panel. All endpoints are thin pass-throughs that delegate
 * to AnalyticsService — the controller validates input and shapes the HTTP
 * response only.
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
 * @spec openspec/changes/dashboard/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller exposing /api/analytics/* aggregate endpoints.
 *
 * Authentication is mandated by @NoAdminRequired (no @PublicPage). Error
 * responses use static messages — internal exception details are logged but
 * never returned to the caller. Data scoping (multitenancy / RBAC) is enforced
 * by OpenRegister inside AnalyticsService, so a user only ever sees aggregates
 * of objects they are authorised to read.
 *
 * @spec openspec/changes/dashboard/tasks.md#task-2.1
 */
class AnalyticsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request     The request.
     * @param AnalyticsService $service     The analytics aggregation service.
     * @param IUserSession     $userSession The user session.
     * @param LoggerInterface  $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private AnalyticsService $service,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the cross-module KPI overview for a period.
     *
     * @return JSONResponse The overview KPIs, or an error response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.1
     */
    public function overview(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $period = (string) $this->request->getParam('period', 'month');
            return new JSONResponse($this->service->getOverview(period: $period));
        } catch (\Throwable $e) {
            $this->logger->error('[AnalyticsController] overview failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not load analytics overview'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end overview()

    /**
     * Return a time-series for a chartable metric.
     *
     * @return JSONResponse The trend series, or an error response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.1
     */
    public function trends(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $metric = (string) $this->request->getParam('metric', '');
        $period = (string) $this->request->getParam('period', 'month');

        try {
            return new JSONResponse($this->service->getTrends(metric: $metric, period: $period));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => 'Unsupported metric'], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('[AnalyticsController] trends failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not load analytics trends'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end trends()

    /**
     * Return the lead-to-close and request-to-resolved funnels.
     *
     * @return JSONResponse The funnel data, or an error response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.1
     */
    public function funnels(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse($this->service->getFunnels());
        } catch (\Throwable $e) {
            $this->logger->error('[AnalyticsController] funnels failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not load analytics funnels'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end funnels()
}//end class
