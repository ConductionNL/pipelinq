<?php

/**
 * Pipelinq AnalyticsController.
 *
 * REST endpoint exposing the cross-module analytics KPI summary.
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
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the analytics summary endpoint.
 *
 * Thin: validates the period parameter and delegates aggregation to
 * AnalyticsService. All authenticated users may read the org-wide KPIs.
 *
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.2
 */
class AnalyticsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          The request.
     * @param AnalyticsService $analyticsService The analytics service.
     * @param IUserSession     $userSession      The user session.
     * @param IL10N            $l10n             The localization service.
     * @param LoggerInterface  $logger           The logger.
     */
    public function __construct(
        IRequest $request,
        private readonly AnalyticsService $analyticsService,
        private readonly IUserSession $userSession,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the cross-module KPI summary for a reporting period.
     *
     * @return JSONResponse The summary JSON, or an error envelope.
     *
     * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    public function summary(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(
                ['message' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $period = (string) $this->request->getParam('period', 'month');
        if ($this->analyticsService->isValidPeriod($period) === false) {
            return new JSONResponse(
                ['message' => $this->l10n->t('Invalid period')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $summary = $this->analyticsService->getSummary($period);
            return new JSONResponse($summary);
        } catch (Throwable $e) {
            $this->logger->error(
                '[AnalyticsController] summary failed',
                ['exception' => $e]
            );
            return new JSONResponse(
                ['message' => $this->l10n->t('Analytics unavailable')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end summary()
}//end class
