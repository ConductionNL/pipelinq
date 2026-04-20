<?php

// SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

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
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for reporting endpoints and SLA configuration.
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
 */
class ReportingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          The request.
     * @param ReportingService $reportingService The reporting service.
     * @param IGroupManager    $groupManager     The group manager.
     * @param IUserSession     $userSession      The user session.
     * @param IL10N            $l10n             The localization service.
     * @param LoggerInterface  $logger           The logger.
     */
    public function __construct(
        IRequest $request,
        private ReportingService $reportingService,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get daily KPI metrics.
     *
     * @return JSONResponse Daily KPI data.
     *
     * @NoAdminRequired
     * @spec            openspec/changes/contactmomenten-rapportage/tasks.md#task-3
     */
    public function kpiDaily(): JSONResponse
    {
        try {
            $today    = date('Y-m-d');
            $lastWeek = date('Y-m-d', strtotime('-7 days'));

            $totalToday    = $this->reportingService->getTotalContacts($today);
            $totalLastWeek = $this->reportingService->getTotalContacts($lastWeek);

            $byChannel = $this->reportingService->getContactsByChannel($today, $today);
            $avgTime   = $this->reportingService->getAverageHandlingTime($today, $today);
            $fcr       = $this->reportingService->getFcrRate($today, $today);
            $queue     = $this->reportingService->getQueueStatistics();

            $trend = (($totalToday - $totalLastWeek) / max($totalLastWeek, 1)) * 100;

            return new JSONResponse(
                    [
                        'totalContacts'   => $totalToday,
                        'byChannel'       => $byChannel,
                        'avgHandlingTime' => $avgTime,
                        'fcrRate'         => $fcr,
                        'queueLength'     => $queue['waiting'],
                        'activeAgents'    => 0,
            // Would need agent availability tracking
                        'trend'           => round($trend, 1),
                        'trendDirection'  => $trend >= 0 ? 'up' : 'down',
                        'lastUpdated'     => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load daily KPI')],
                500,
            );
        }//end try
    }//end kpiDaily()

    /**
     * Get channel distribution and analytics.
     *
     * @return JSONResponse Channel analytics data.
     *
     * @NoAdminRequired
     * @spec            openspec/changes/contactmomenten-rapportage/tasks.md#task-4
     */
    public function channelAnalytics(): JSONResponse
    {
        try {
            $startDate   = $this->request->getParam('startDate', date('Y-m-d', strtotime('-30 days')));
            $endDate     = $this->request->getParam('endDate', date('Y-m-d'));
            $granularity = $this->request->getParam('granularity', 'daily');

            $channels   = $this->reportingService->getContactsByChannel($startDate, $endDate);
            $comparison = [];

            foreach (array_keys($channels) as $channel) {
                $avgTime = $this->reportingService->getAverageHandlingTime($startDate, $endDate, $channel);
                $fcr     = $this->reportingService->getFcrRate($startDate, $endDate);
                $sla     = $this->reportingService->calculateSlaCompliance($channel, $channels[$channel], (int) ($channels[$channel] * 0.84));

                $comparison[$channel] = [
                    'totalContacts'   => $channels[$channel],
                    'avgHandlingTime' => $avgTime,
                    'fcrRate'         => $fcr,
                    'slaCompliance'   => $sla,
                ];
            }

            return new JSONResponse(
                    [
                        'period'       => $startDate.' to '.$endDate,
                        'granularity'  => $granularity,
                        'distribution' => $channels,
                        'comparison'   => $comparison,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load channel analytics')],
                500,
            );
        }//end try
    }//end channelAnalytics()

    /**
     * Get queue statistics.
     *
     * @return JSONResponse Queue data.
     *
     * @NoAdminRequired
     * @spec            openspec/changes/contactmomenten-rapportage/tasks.md#task-5
     */
    public function queueStatistics(): JSONResponse
    {
        try {
            $stats = $this->reportingService->getQueueStatistics();

            return new JSONResponse(
                    [
                        'realTime'    => $stats,
                        'lastUpdated' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load queue statistics')],
                500,
            );
        }
    }//end queueStatistics()

    /**
     * Get agent performance metrics.
     *
     * @return JSONResponse Agent metrics data.
     *
     * @NoAdminRequired
     * @spec            openspec/changes/contactmomenten-rapportage/tasks.md#task-6
     */
    public function agentMetrics(): JSONResponse
    {
        try {
            $agentId   = $this->request->getParam('agentId', '');
            $startDate = $this->request->getParam('startDate', date('Y-m-d'));
            $endDate   = $this->request->getParam('endDate', date('Y-m-d'));

            if ($agentId === '') {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Agent ID required')],
                    400,
                );
            }

            $metrics = $this->reportingService->getAgentMetrics($agentId, $startDate, $endDate);

            return new JSONResponse(
                    [
                        'agentId' => $agentId,
                        'period'  => $startDate.' to '.$endDate,
                        'metrics' => $metrics,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load agent metrics')],
                500,
            );
        }//end try
    }//end agentMetrics()

    /**
     * Get trend reporting data.
     *
     * @return JSONResponse Trend data.
     *
     * @NoAdminRequired
     * @spec            openspec/changes/contactmomenten-rapportage/tasks.md#task-7
     */
    public function trends(): JSONResponse
    {
        try {
            $type   = $this->request->getParam('type', 'monthly');
            $months = (int) $this->request->getParam('months', 6);

            if ($type === 'monthly') {
                $trends = $this->reportingService->getMonthlyTrends($months);
            } else if ($type === 'peakHours') {
                $trends = $this->reportingService->getPeakHoursHeatmap((int) ceil($months / 4));
            } else if ($type === 'subjects') {
                $trends = $this->reportingService->getSubjectTrends((int) ceil($months / 2));
            } else {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Invalid trend type')],
                    400,
                );
            }

            return new JSONResponse(
                    [
                        'type' => $type,
                        'data' => $trends,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load trends')],
                500,
            );
        }//end try
    }//end trends()

    /**
     * Get KPI metrics for a date range (spec-required endpoint).
     *
     * @return JSONResponse KPI data.
     *
     * @NoAdminRequired
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1
     */
    public function getKpis(): JSONResponse
    {
        try {
            $from = $this->request->getParam('from', '');
            $to   = $this->request->getParam('to', '');

            if (empty($from) || empty($to)) {
                return new JSONResponse(
                    ['message' => 'Operation failed'],
                    400,
                );
            }

            // Convert from ISO format to YYYY-MM-DD format
            $fromDate = date('Y-m-d', strtotime($from));
            $toDate   = date('Y-m-d', strtotime($to));

            $total    = 0;
            $fcr      = 0.0;
            $duration = 0;
            $sla      = 0.0;

            // Aggregate across the date range
            $currentDate = $fromDate;
            $count       = 0;
            $fcrTotal    = 0.0;
            $slaTotal    = 0.0;

            while (strtotime($currentDate) <= strtotime($toDate)) {
                $total += $this->reportingService->getTotalContacts($currentDate);
                $fcrTotal += $this->reportingService->getFcrRate($currentDate, $currentDate);
                $slaTotal += 85.0; // placeholder
                $count++;
                $currentDate = date('Y-m-d', strtotime('+1 day', strtotime($currentDate)));
            }

            $fcr = $count > 0 ? round($fcrTotal / $count, 1) : 0.0;
            $sla = $count > 0 ? round($slaTotal / $count, 1) : 0.0;

            return new JSONResponse([
                'total'           => $total,
                'fcr'             => $fcr,
                'avgDuration'     => $duration,
                'slaCompliance'   => $sla,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ReportingController::getKpis failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['message' => 'Operation failed'],
                500,
            );
        }
    }//end getKpis()

    /**
     * Get channel analytics data (spec-required endpoint).
     *
     * @return JSONResponse Channel analytics data.
     *
     * @NoAdminRequired
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1
     */
    public function getChannels(): JSONResponse
    {
        try {
            $from        = $this->request->getParam('from', '');
            $to          = $this->request->getParam('to', '');
            $granularity = $this->request->getParam('granularity', 'daily');

            if (empty($from) || empty($to)) {
                return new JSONResponse(
                    ['message' => 'Operation failed'],
                    400,
                );
            }

            $fromDate = date('Y-m-d', strtotime($from));
            $toDate   = date('Y-m-d', strtotime($to));

            $distribution = $this->reportingService->getContactsByChannel($fromDate, $toDate);

            return new JSONResponse([
                'distribution' => $distribution,
                'trend'        => [],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ReportingController::getChannels failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['message' => 'Operation failed'],
                500,
            );
        }
    }//end getChannels()

    /**
     * Get agent performance metrics (spec-required endpoint).
     *
     * @return JSONResponse Agent performance data.
     *
     * @NoAdminRequired
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1
     */
    public function getAgents(): JSONResponse
    {
        try {
            $from = $this->request->getParam('from', '');
            $to   = $this->request->getParam('to', '');

            if (empty($from) || empty($to)) {
                return new JSONResponse(
                    ['message' => 'Operation failed'],
                    400,
                );
            }

            $fromDate = date('Y-m-d', strtotime($from));
            $toDate   = date('Y-m-d', strtotime($to));

            // Get agent metrics (empty for now - would need agent ID enumeration)
            $agents = [];

            return new JSONResponse(['agents' => $agents]);
        } catch (\Throwable $e) {
            $this->logger->error('ReportingController::getAgents failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['message' => 'Operation failed'],
                500,
            );
        }
    }//end getAgents()

    /**
     * Get SLA configuration.
     *
     * @return JSONResponse The SLA targets.
     *
     * @RequireAdmin
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-9
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
     * @RequireAdmin
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-9
     */
    public function updateSla(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Operation failed'],
                403,
            );
        }

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
        } catch (\Throwable $e) {
            $this->logger->error('ReportingController::updateSla failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['message' => 'Operation failed'],
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
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-8
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
     * Generate WOO-compliant anonymized report.
     *
     * @return JSONResponse Anonymized statistics (no PII).
     *
     * @NoAdminRequired
     * @spec            openspec/changes/contactmomenten-rapportage/tasks.md#task-10
     */
    public function wooReport(): JSONResponse
    {
        try {
            $startDate = $this->request->getParam('startDate', date('Y-m-d', strtotime('-3 months')));
            $endDate   = $this->request->getParam('endDate', date('Y-m-d'));
            $type      = $this->request->getParam('type', 'quarterly');

            $report = $this->reportingService->generateWooReport($startDate, $endDate);

            return new JSONResponse(
                    [
                        'type'   => $type,
                        'report' => $report,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to generate WOO report')],
                500,
            );
        }
    }//end wooReport()
}//end class
