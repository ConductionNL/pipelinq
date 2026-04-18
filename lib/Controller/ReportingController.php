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
use OCA\Pipelinq\Service\ReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for reporting endpoints and SLA configuration.
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
 */
class ReportingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          The request.
     * @param ReportingService $reportingService The reporting service.
     * @param IL10N            $l10n             The localization service.
     */
    public function __construct(
        IRequest $request,
        private ReportingService $reportingService,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

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

    /**
     * Get daily KPI summary.
     *
     * @return JSONResponse Daily KPI data.
     *
     * @NoAdminRequired
     */
    public function getKpiSummary(): JSONResponse
    {
        try {
            $summary = $this->reportingService->getDailyKpiSummary();
            return new JSONResponse(['kpis' => $summary]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load KPI summary')],
                500,
            );
        }
    }//end getKpiSummary()

    /**
     * Get KPI trend for a specific channel and metric.
     *
     * @return JSONResponse Trend data.
     *
     * @NoAdminRequired
     */
    public function getKpiTrend(): JSONResponse
    {
        try {
            $channel = $this->request->getParam('channel', 'telefoon');
            $metric  = $this->request->getParam('metric', 'fcr');
            $days    = (int) $this->request->getParam('days', 7);

            $trend = $this->reportingService->getKpiTrend($channel, $metric, $days);
            return new JSONResponse(['trend' => $trend]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load KPI trend')],
                500,
            );
        }
    }//end getKpiTrend()

    /**
     * Get channel distribution analytics.
     *
     * @return JSONResponse Channel distribution data.
     *
     * @NoAdminRequired
     */
    public function getChannelDistribution(): JSONResponse
    {
        try {
            $dateFrom    = $this->request->getParam('dateFrom', '');
            $dateTo      = $this->request->getParam('dateTo', '');
            $granularity = $this->request->getParam('granularity', 'day');

            if ($dateFrom === '' || $dateTo === '') {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Date range is required')],
                    400,
                );
            }

            $distribution = $this->reportingService->getChannelDistribution(
                $dateFrom,
                $dateTo,
                $granularity,
            );
            return new JSONResponse(['distribution' => $distribution]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load channel distribution')],
                500,
            );
        }
    }//end getChannelDistribution()

    /**
     * Get channel comparison vs previous month.
     *
     * @return JSONResponse Channel comparison data.
     *
     * @NoAdminRequired
     */
    public function getChannelComparison(): JSONResponse
    {
        try {
            $monthYear = $this->request->getParam('monthYear', '');

            if ($monthYear === '') {
                $monthYear = date('Y-m');
            }

            $comparison = $this->reportingService->getChannelComparison($monthYear);
            return new JSONResponse(['comparison' => $comparison]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load channel comparison')],
                500,
            );
        }
    }//end getChannelComparison()

    /**
     * Get real-time queue statistics.
     *
     * @return JSONResponse Queue statistics.
     *
     * @NoAdminRequired
     */
    public function getQueueStats(): JSONResponse
    {
        try {
            $stats = $this->reportingService->getQueueStatistics();
            return new JSONResponse(['queueStats' => $stats]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load queue statistics')],
                500,
            );
        }
    }//end getQueueStats()

    /**
     * Get agent statistics.
     *
     * @param string $agentId Agent ID.
     *
     * @return JSONResponse Agent statistics.
     *
     * @NoAdminRequired
     */
    public function getAgentStats(string $agentId): JSONResponse
    {
        try {
            $stats = $this->reportingService->getAgentStatistics($agentId);
            return new JSONResponse(['agentStats' => $stats]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load agent statistics')],
                500,
            );
        }
    }//end getAgentStats()

    /**
     * Get team overview.
     *
     * @return JSONResponse Team overview.
     *
     * @NoAdminRequired
     */
    public function getTeamOverview(): JSONResponse
    {
        try {
            $overview = $this->reportingService->getTeamOverview();
            return new JSONResponse(['teamOverview' => $overview]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load team overview')],
                500,
            );
        }
    }//end getTeamOverview()

    /**
     * Get monthly trend report.
     *
     * @return JSONResponse Trend report.
     *
     * @NoAdminRequired
     */
    public function getMonthlyTrend(): JSONResponse
    {
        try {
            $months = (int) $this->request->getParam('months', 6);
            $report = $this->reportingService->getMonthlyTrendReport($months);
            return new JSONResponse(['trendReport' => $report]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load trend report')],
                500,
            );
        }
    }//end getMonthlyTrend()

    /**
     * Get peak hours heatmap.
     *
     * @return JSONResponse Heatmap data.
     *
     * @NoAdminRequired
     */
    public function getPeakHours(): JSONResponse
    {
        try {
            $weeks   = (int) $this->request->getParam('weeks', 4);
            $heatmap = $this->reportingService->getPeakHoursHeatmap($weeks);
            return new JSONResponse(['heatmap' => $heatmap]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load peak hours heatmap')],
                500,
            );
        }
    }//end getPeakHours()

    /**
     * Get historical wait time data.
     *
     * @return JSONResponse Historical wait time data.
     *
     * @NoAdminRequired
     */
    public function getHistoricalWaitTimes(): JSONResponse
    {
        try {
            $dateFrom = $this->request->getParam('dateFrom', '');
            $dateTo   = $this->request->getParam('dateTo', '');

            if ($dateFrom === '' || $dateTo === '') {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Date range is required')],
                    400,
                );
            }

            $data = $this->reportingService->getHistoricalWaitTimes($dateFrom, $dateTo);
            return new JSONResponse(['waitTimes' => $data]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load wait time data')],
                500,
            );
        }
    }//end getHistoricalWaitTimes()

    /**
     * Get wait time SLA alert status.
     *
     * @return JSONResponse Alert status.
     *
     * @NoAdminRequired
     */
    public function getWaitTimeSlaAlert(): JSONResponse
    {
        try {
            $status = $this->reportingService->getWaitTimeSlaAlertStatus();
            return new JSONResponse(['alert' => $status]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to check SLA alert status')],
                500,
            );
        }
    }//end getWaitTimeSlaAlert()

    /**
     * Get agent workload distribution.
     *
     * @return JSONResponse Workload distribution.
     *
     * @NoAdminRequired
     */
    public function getAgentWorkload(): JSONResponse
    {
        try {
            $data = $this->reportingService->getAgentWorkloadDistribution();
            return new JSONResponse(['workload' => $data]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load workload distribution')],
                500,
            );
        }
    }//end getAgentWorkload()

    /**
     * Get agent performance trend.
     *
     * @param string $agentId Agent ID.
     *
     * @return JSONResponse Agent trend data.
     *
     * @NoAdminRequired
     */
    public function getAgentTrend(string $agentId): JSONResponse
    {
        try {
            $days = (int) $this->request->getParam('days', 30);
            $data = $this->reportingService->getAgentPerformanceTrend($agentId, $days);
            return new JSONResponse(['trend' => $data]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load agent trend data')],
                500,
            );
        }
    }//end getAgentTrend()

    /**
     * Get subject trend analysis.
     *
     * @return JSONResponse Subject trend data.
     *
     * @NoAdminRequired
     */
    public function getSubjectTrends(): JSONResponse
    {
        try {
            $months = (int) $this->request->getParam('months', 3);
            $data   = $this->reportingService->getSubjectTrendAnalysis($months);
            return new JSONResponse(['trends' => $data]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load subject trends')],
                500,
            );
        }
    }//end getSubjectTrends()

    /**
     * Generate WOO-compliant anonymized report.
     *
     * @return JSONResponse WOO report data.
     *
     * @NoAdminRequired
     */
    public function getWooReport(): JSONResponse
    {
        try {
            $dateFrom = $this->request->getParam('dateFrom', '');
            $dateTo   = $this->request->getParam('dateTo', '');

            if ($dateFrom === '' || $dateTo === '') {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Date range is required')],
                    400,
                );
            }

            $report = $this->reportingService->generateWooReport($dateFrom, $dateTo);
            return new JSONResponse(['wooReport' => $report]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to generate WOO report')],
                500,
            );
        }
    }//end getWooReport()

    /**
     * Get annual service statistics.
     *
     * @return JSONResponse Annual statistics.
     *
     * @NoAdminRequired
     */
    public function getAnnualStatistics(): JSONResponse
    {
        try {
            $year  = (int) $this->request->getParam('year', date('Y'));
            $stats = $this->reportingService->generateAnnualServiceStatistics($year);
            return new JSONResponse(['annualStats' => $stats]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to generate annual statistics')],
                500,
            );
        }
    }//end getAnnualStatistics()

    /**
     * Get benchmark comparison.
     *
     * @return JSONResponse Benchmark comparison.
     *
     * @NoAdminRequired
     */
    public function getBenchmarkComparison(): JSONResponse
    {
        try {
            $size  = $this->request->getParam('municipalitySize', 'middel');
            $bench = $this->reportingService->getBenchmarkComparison($size);
            return new JSONResponse(['benchmark' => $bench]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load benchmark comparison')],
                500,
            );
        }
    }//end getBenchmarkComparison()

    /**
     * Get contactmoment data for BI tools.
     *
     * @return JSONResponse Contactmoment records.
     *
     * @NoAdminRequired
     */
    public function getContactmomentsData(): JSONResponse
    {
        try {
            $filters = [
                'dateFrom' => $this->request->getParam('dateFrom', ''),
                'dateTo'   => $this->request->getParam('dateTo', ''),
                'channel'  => $this->request->getParam('channel', ''),
                'limit'    => (int) $this->request->getParam('limit', 100),
                'offset'   => (int) $this->request->getParam('offset', 0),
            ];

            $data = $this->reportingService->getContactmomentsData($filters);
            return new JSONResponse(['data' => $data]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to extract contactmoment data')],
                500,
            );
        }
    }//end getContactmomentsData()

    /**
     * Get KPI aggregates for BI.
     *
     * @return JSONResponse KPI aggregate data.
     *
     * @NoAdminRequired
     */
    public function getKpiAggregates(): JSONResponse
    {
        try {
            $filters = [
                'dateFrom' => $this->request->getParam('dateFrom', ''),
                'dateTo'   => $this->request->getParam('dateTo', ''),
                'channel'  => $this->request->getParam('channel', ''),
            ];

            $data = $this->reportingService->getKpiAggregates($filters);
            return new JSONResponse(['aggregates' => $data]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to aggregate KPI data')],
                500,
            );
        }
    }//end getKpiAggregates()

    /**
     * Get subject analytics.
     *
     * @return JSONResponse Subject analytics.
     *
     * @NoAdminRequired
     */
    public function getSubjectAnalytics(): JSONResponse
    {
        try {
            $data = $this->reportingService->getSubjectAnalytics();
            return new JSONResponse(['analytics' => $data]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load subject analytics')],
                500,
            );
        }
    }//end getSubjectAnalytics()
}//end class
