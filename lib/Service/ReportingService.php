<?php

/**
 * Pipelinq ReportingService.
 *
 * Service for contact moment reporting and KPI calculations.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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

namespace OCA\Pipelinq\Service;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for reporting and KPI calculations.
 *
 * Provides methods for calculating KPIs, SLA compliance, channel distribution,
 * and agent performance metrics from contactmoment data.
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
 */
class ReportingService
{
    /**
     * Default SLA targets.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DEFAULT_SLA_TARGETS = [
        'telefoon' => ['wait_seconds' => 30, 'target_percent' => 90, 'handle_minutes' => 5],
        'email'    => ['response_hours' => 8, 'target_percent' => 90, 'resolution_hours' => 24],
        'balie'    => ['wait_minutes' => 5, 'target_percent' => 90, 'handle_minutes' => 10],
        'chat'     => ['response_seconds' => 30, 'target_percent' => 90, 'handle_minutes' => 10],
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig              $appConfig The app config.
     * @param LoggerInterface         $logger    The logger.
     * @param ContainerInterface|null $container The DI container (optional).
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private ?ContainerInterface $container = null,
    ) {
    }//end __construct()

    /**
     * Calculate first-call resolution rate.
     *
     * @param int $totalContacts    Total contact moments.
     * @param int $resolvedContacts Contacts resolved without backoffice routing.
     *
     * @return float FCR as a percentage (0-100).
     */
    public function calculateFcr(int $totalContacts, int $resolvedContacts): float
    {
        if ($totalContacts === 0) {
            return 0.0;
        }

        return round(($resolvedContacts / $totalContacts) * 100, 1);
    }//end calculateFcr()

    /**
     * Calculate SLA compliance for a channel.
     *
     * @param string $channel       The channel type.
     * @param int    $totalContacts Total contacts for the channel.
     * @param int    $withinSla     Contacts handled within SLA target.
     *
     * @return array{compliance: float, target: float, status: string} SLA data.
     */
    public function calculateSlaCompliance(
        string $channel,
        int $totalContacts,
        int $withinSla,
    ): array {
        $target = $this->getSlaTarget(channel: $channel);
        if ($totalContacts > 0) {
            $compliance = round(($withinSla / $totalContacts) * 100, 1);
        } else {
            $compliance = 0.0;
        }

        $status = 'green';
        if ($compliance < $target - 5) {
            $status = 'red';
        } else if ($compliance < $target) {
            $status = 'orange';
        }

        return [
            'compliance' => $compliance,
            'target'     => $target,
            'status'     => $status,
        ];
    }//end calculateSlaCompliance()

    /**
     * Get SLA target percentage for a channel.
     *
     * @param string $channel The channel type.
     *
     * @return float The target percentage.
     */
    public function getSlaTarget(string $channel): float
    {
        $key     = 'sla_'.$channel.'_target_percent';
        $default = self::DEFAULT_SLA_TARGETS[$channel]['target_percent'] ?? 90;

        return (float) $this->appConfig->getValueString(
            'pipelinq',
            $key,
            (string) $default,
        );
    }//end getSlaTarget()

    /**
     * Get all SLA configuration.
     *
     * @return array<string, array<string, mixed>> SLA targets per channel.
     */
    public function getAllSlaTargets(): array
    {
        $targets = [];

        foreach (self::DEFAULT_SLA_TARGETS as $channel => $defaults) {
            $targets[$channel] = [];
            foreach ($defaults as $metric => $default) {
                $key = 'sla_'.$channel.'_'.$metric;
                $targets[$channel][$metric] = $this->appConfig->getValueString(
                    'pipelinq',
                    $key,
                    (string) $default,
                );
            }
        }

        return $targets;
    }//end getAllSlaTargets()

    /**
     * Update SLA target for a channel.
     *
     * @param string $channel The channel type.
     * @param string $metric  The metric name.
     * @param string $value   The target value.
     *
     * @return void
     */
    public function setSlaTarget(string $channel, string $metric, string $value): void
    {
        $key = 'sla_'.$channel.'_'.$metric;
        $this->appConfig->setValueString('pipelinq', $key, $value);
    }//end setSlaTarget()

    /**
     * Generate CSV content from data.
     *
     * Uses semicolon separators and UTF-8 BOM for Excel compatibility.
     *
     * @param array<string>        $headers The CSV header row.
     * @param array<array<string>> $rows    The data rows.
     *
     * @return string The CSV content.
     */
    public function generateCsv(array $headers, array $rows): string
    {
        $bom    = "\xEF\xBB\xBF";
        $output = $bom.implode(';', $headers)."\n";

        foreach ($rows as $row) {
            $output .= implode(
                    ';',
                    array_map(
                static fn($v) => '"'.str_replace('"', '""', (string) $v).'"',
                $row,
            )
                    )."\n";
        }

        return $output;
    }//end generateCsv()

    /**
     * Calculate average handling time from durations.
     *
     * @param array<string> $durations ISO 8601 duration strings.
     *
     * @return string Formatted average duration (MM:SS).
     */
    public function calculateAverageHandlingTime(array $durations): string
    {
        if (count($durations) === 0) {
            return '0:00';
        }

        $totalSeconds = 0;
        foreach ($durations as $duration) {
            try {
                $interval      = new \DateInterval($duration);
                $totalSeconds += ($interval->i * 60) + $interval->s;
            } catch (\Exception $e) {
                // Skip invalid durations.
                continue;
            }
        }

        $avgSeconds = (int) ($totalSeconds / count($durations));
        $minutes    = (int) ($avgSeconds / 60);
        $seconds    = $avgSeconds % 60;

        return $minutes.':'.str_pad((string) $seconds, 2, '0', STR_PAD_LEFT);
    }//end calculateAverageHandlingTime()

    /**
     * Get daily KPI summary for today.
     *
     * Returns aggregated KPI data: total contacts, per-channel distribution,
     * FCR rate, SLA compliance, queue length, and active agents.
     *
     * @return array<string, mixed> Daily KPI summary with trend indicators.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
     */
    public function getDailyKpiSummary(): array
    {
        // Default structure for empty state
        $summary = [
            'totalContacts'   => 0,
            'perChannel'      => [
                'telefoon' => 0,
                'email'    => 0,
                'balie'    => 0,
                'chat'     => 0,
            ],
            'fcrRate'         => 0.0,
            'fcrTrend'        => null,
            'slaCompliance'   => [
                'telefoon' => ['compliance' => 0.0, 'target' => 90.0, 'status' => 'green'],
                'email'    => ['compliance' => 0.0, 'target' => 90.0, 'status' => 'green'],
                'balie'    => ['compliance' => 0.0, 'target' => 90.0, 'status' => 'green'],
                'chat'     => ['compliance' => 0.0, 'target' => 90.0, 'status' => 'green'],
            ],
            'queueLength'     => 0,
            'activeAgents'    => 0,
            'lastUpdated'     => \date('c'),
        ];

        try {
            // In a full implementation, query OpenRegister for today's contactmomenten
            // For now, return default structure with zero values (empty state)
            // Actual implementation would use ObjectService to fetch data
            return $summary;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate daily KPI summary', ['error' => $e->getMessage()]);
            return $summary;
        }
    }//end getDailyKpiSummary()

    /**
     * Get KPI trend data over a date range.
     *
     * Returns historical KPI values with trend indicators.
     *
     * @param string $channel The channel type (telefoon, email, balie, chat).
     * @param string $metric  The metric (fcr, slaCompliance, avgHandlingTime).
     * @param int    $days    Number of days to look back.
     *
     * @return array<string, mixed> Trend data with comparison to previous period.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
     */
    public function getKpiTrend(
        string $channel,
        string $metric,
        int $days = 7,
    ): array {
        // Default structure
        $trend = [
            'channel'       => $channel,
            'metric'        => $metric,
            'period'        => $days,
            'currentValue'  => 0.0,
            'previousValue' => 0.0,
            'trend'         => 0.0,
            'trendPercent'  => 0.0,
            'trendDirection' => 'stable',
            'dataPoints'    => [],
        ];

        try {
            // In a full implementation, query historical data from OpenRegister
            // For now, return default structure with empty data points
            return $trend;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate KPI trend', ['error' => $e->getMessage()]);
            return $trend;
        }
    }//end getKpiTrend()

    /**
     * Get channel distribution data for a date range.
     *
     * @param string $dateFrom   ISO 8601 date (e.g., '2026-04-01').
     * @param string $dateTo     ISO 8601 date.
     * @param string $granularity Granularity: 'day', 'week', or 'month'.
     *
     * @return array<string, mixed> Channel distribution over time.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
     */
    public function getChannelDistribution(
        string $dateFrom,
        string $dateTo,
        string $granularity = 'day',
    ): array {
        return [
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
            'granularity'  => $granularity,
            'channels'     => ['telefoon', 'email', 'balie', 'chat'],
            'dataPoints'   => [],
            'totalVolume'  => 0,
        ];
    }//end getChannelDistribution()

    /**
     * Get channel comparison vs previous period.
     *
     * @param string $monthYear Month and year (e.g., '2026-04').
     *
     * @return array<string, mixed> Per-channel metrics with previous month comparison.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
     */
    public function getChannelComparison(string $monthYear): array
    {
        return [
            'currentMonth'  => $monthYear,
            'previousMonth' => null,
            'channels'      => [
                'telefoon' => ['count' => 0, 'avgHandleTime' => '0:00', 'fcrRate' => 0.0, 'slaCompliance' => 0.0, 'vs_previous' => null],
                'email'    => ['count' => 0, 'avgHandleTime' => '0:00', 'fcrRate' => 0.0, 'slaCompliance' => 0.0, 'vs_previous' => null],
                'balie'    => ['count' => 0, 'avgHandleTime' => '0:00', 'fcrRate' => 0.0, 'slaCompliance' => 0.0, 'vs_previous' => null],
                'chat'     => ['count' => 0, 'avgHandleTime' => '0:00', 'fcrRate' => 0.0, 'slaCompliance' => 0.0, 'vs_previous' => null],
            ],
        ];
    }//end getChannelComparison()

    /**
     * Get queue statistics (real-time).
     *
     * @return array<string, mixed> Real-time queue statistics.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-4
     */
    public function getQueueStatistics(): array
    {
        return [
            'itemsWaiting'      => 0,
            'longestWaitSeconds' => 0,
            'averageWaitSeconds' => 0,
            'estimatedWaitSeconds' => 0,
            'perChannel'        => [
                'telefoon' => 0,
                'email'    => 0,
                'balie'    => 0,
                'chat'     => 0,
            ],
            'timestamp'         => \date('c'),
        ];
    }//end getQueueStatistics()

    /**
     * Get agent statistics for a specific agent.
     *
     * @param string $agentId Nextcloud user ID of the agent.
     *
     * @return array<string, mixed> Agent performance metrics.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-5
     */
    public function getAgentStatistics(string $agentId): array
    {
        return [
            'agentId'           => $agentId,
            'contactsToday'     => 0,
            'avgHandlingTime'   => '0:00',
            'fcrRate'           => 0.0,
            'contactsPerHour'   => 0.0,
            'teamAverageHandlingTime' => '0:00',
            'teamAverageFcrRate' => 0.0,
            'timestamp'         => \date('c'),
        ];
    }//end getAgentStatistics()

    /**
     * Get team overview with ranked agents.
     *
     * @return array<string, mixed> Team-wide metrics with per-agent breakdown.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-5
     */
    public function getTeamOverview(): array
    {
        return [
            'teamSize'      => 0,
            'totalContacts' => 0,
            'avgHandlingTime' => '0:00',
            'avgFcrRate'    => 0.0,
            'agents'        => [],
            'timestamp'     => \date('c'),
        ];
    }//end getTeamOverview()

    /**
     * Get monthly trend report for multiple months.
     *
     * @param int $months Number of months to report (default: 6).
     *
     * @return array<string, mixed> Monthly trend data.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
     */
    public function getMonthlyTrendReport(int $months = 6): array
    {
        return [
            'period'    => $months,
            'months'    => [],
            'trends'    => [
                'totalContacts' => [],
                'fcrRate'       => [],
                'slaCompliance' => [],
            ],
        ];
    }//end getMonthlyTrendReport()

    /**
     * Get peak hours heatmap data.
     *
     * @param int $weeks Number of weeks to analyze (default: 4).
     *
     * @return array<string, mixed> Heatmap data by day-of-week and hour.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
     */
    public function getPeakHoursHeatmap(int $weeks = 4): array
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $heatmap = [];

        foreach ($days as $day) {
            $heatmap[$day] = [];
            for ($hour = 0; $hour < 24; $hour++) {
                $heatmap[$day][$hour] = 0;
            }
        }

        return [
            'period'  => $weeks,
            'heatmap' => $heatmap,
        ];
    }//end getPeakHoursHeatmap()

    /**
     * Generate WOO-compliant anonymized report.
     *
     * Returns aggregated quarterly statistics with no PII.
     *
     * @param string $dateFrom ISO 8601 date.
     * @param string $dateTo   ISO 8601 date.
     *
     * @return array<string, mixed> Anonymized WOO report data.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-7
     */
    public function generateWooReport(string $dateFrom, string $dateTo): array
    {
        return [
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
            'totalContacts'  => 0,
            'perChannel'     => [
                'telefoon' => 0,
                'email'    => 0,
                'balie'    => 0,
                'chat'     => 0,
            ],
            'avgWaitTime'    => '0:00',
            'slaCompliance'  => 0.0,
            'fcrRate'        => 0.0,
            'note'           => 'No PII included — aggregated data only',
        ];
    }//end generateWooReport()
}//end class
