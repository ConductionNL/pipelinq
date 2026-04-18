<?php

/**
 * Pipelinq ReportingController.
 *
 * Controller for contact moment reporting and SLA configuration.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ChannelAnalyticsService;
use OCA\Pipelinq\Service\KpiDashboardService;
use OCA\Pipelinq\Service\ReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for reporting endpoints and SLA configuration.
 */
class ReportingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request                 The request.
     * @param ReportingService        $reportingService        The reporting service.
     * @param KpiDashboardService     $kpiDashboardService     The KPI dashboard service.
     * @param ChannelAnalyticsService $channelAnalyticsService The channel analytics service.
     * @param IL10N                   $l10n                    The localization service.
     */
    public function __construct(
        IRequest $request,
        private ReportingService $reportingService,
        private KpiDashboardService $kpiDashboardService,
        private ChannelAnalyticsService $channelAnalyticsService,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get KPI dashboard metrics.
     *
     * Returns real-time KPI dashboard data including total contacts, channel
     * distribution, average handling time, queue metrics, active agents count,
     * FCR rate, and SLA compliance status.
     *
     * @return JSONResponse The KPI dashboard data.
     *
     * @NoAdminRequired
     */
    public function getDashboard(): JSONResponse
    {
        try {
            $dashboard = $this->kpiDashboardService->getDashboard();
            return new JSONResponse($dashboard);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load KPI dashboard')],
                500,
            );
        }
    }//end getDashboard()

    /**
     * Get channel analytics with configurable granularity.
     *
     * Returns detailed metrics per contact channel including volume, handling time,
     * FCR rate, and SLA compliance with comparison against previous period.
     *
     * @return JSONResponse The channel analytics data.
     *
     * @NoAdminRequired
     */
    public function getChannelAnalytics(): JSONResponse
    {
        try {
            $startDate   = $this->request->getParam('startDate', '');
            $endDate     = $this->request->getParam('endDate', '');
            $granularity = $this->request->getParam('granularity', 'daily');

            // Use sensible defaults if dates not provided
            if ($startDate === '' || $endDate === '') {
                $today     = new \DateTime();
                $endDate   = $today->format('Y-m-d\TH:i:s\Z');
                $startDate = $today->modify('-30 days')->format('Y-m-d\TH:i:s\Z');
            }

            $analytics = $this->channelAnalyticsService->getChannelAnalytics(
                startDate: $startDate,
                endDate: $endDate,
                granularity: $granularity,
            );

            return new JSONResponse($analytics);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load channel analytics')],
                500,
            );
        }//end try
    }//end getChannelAnalytics()

    /**
     * Get SLA configuration.
     *
     * @return JSONResponse The SLA targets.
     *
     * @NoAdminRequired
     */
    public function getSla(): JSONResponse
    {
        try {
            $targets = $this->reportingService->getAllSlaTargets();
            return new JSONResponse(['targets' => $targets]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load SLA configuration')],
                500,
            );
        }
    }//end getSla()

    /**
     * Update SLA configuration.
     *
     * @return JSONResponse The updated SLA targets.
     *
     * @NoAdminRequired
     */
    public function updateSla(): JSONResponse
    {
        try {
            $targets = $this->request->getParam('targets', []);

            if (is_array($targets) === false) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Invalid SLA configuration')],
                    400,
                );
            }

            foreach ($targets as $channel => $metrics) {
                if (is_array($metrics) === false) {
                    continue;
                }

                foreach ($metrics as $metric => $value) {
                    $this->reportingService->setSlaTarget(
                        channel: $channel,
                        metric: $metric,
                        value: (string) $value,
                    );
                }
            }

            return new JSONResponse(
                    [
                        'success' => true,
                        'targets' => $this->reportingService->getAllSlaTargets(),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to update SLA configuration')],
                500,
            );
        }//end try
    }//end updateSla()

    /**
     * Export reporting data as CSV.
     *
     * @return DataDownloadResponse|JSONResponse The CSV download or error.
     *
     * @NoAdminRequired
     */
    public function exportCsv(): DataDownloadResponse|JSONResponse
    {
        try {
            $headers = [
                $this->l10n->t('Date'),
                $this->l10n->t('Channel'),
                $this->l10n->t('Agent'),
                $this->l10n->t('Client'),
                $this->l10n->t('Subject'),
                $this->l10n->t('Result'),
                $this->l10n->t('Duration'),
            ];

            // In production, data would be fetched from OpenRegister based on filters.
            $rows = [];

            $csv = $this->reportingService->generateCsv(
                headers: $headers,
                rows: $rows,
            );

            $filename = 'contactmomenten-rapport-'.date('Y-m-d').'.csv';

            return new DataDownloadResponse(
                $csv,
                $filename,
                'text/csv; charset=utf-8',
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to generate export')],
                500,
            );
        }//end try
    }//end exportCsv()
}//end class
