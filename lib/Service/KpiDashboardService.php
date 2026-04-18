<?php

/**
 * Pipelinq KpiDashboardService.
 *
 * Service for real-time KPI dashboard calculations including metrics aggregation
 * and trend comparison.
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
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for KPI Dashboard calculations.
 *
 * Aggregates contactmoment data to compute key performance indicators including
 * total contacts, per-channel distribution, average handling time, queue metrics,
 * and trend comparisons.
 */
class KpiDashboardService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The object service.
     *
     * @throws \RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            throw new \RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Get contactmoment register and schema configuration.
     *
     * @return array{register: string, schema: string} Configuration.
     *
     * @throws \RuntimeException If configuration is missing.
     */
    private function getContactmomentConfig(): array
    {
        $register = $this->appConfig->getValueString(
            'pipelinq',
            'register',
            ''
        );
        $schema   = $this->appConfig->getValueString(
            'pipelinq',
            'contactmoment_schema',
            'contactmoment'
        );

        if ($register === '') {
            throw new \RuntimeException('Contactmoment register not configured.');
        }

        return [
            'register' => $register,
            'schema'   => $schema,
        ];
    }//end getContactmomentConfig()

    /**
     * Get today's KPI dashboard data.
     *
     * Aggregates contact moments for the current day and compares against the same
     * day last week for trend indicators.
     *
     * @return array{
     *   timestamp: string,
     *   totals: array{today: int, trend: int},
     *   byChannel: array<string, array{count: int, trend: int, percentage: float}>,
     *   avgHandlingTime: string,
     *   avgHandlingTimeTrend: int,
     *   queue: array{current: int, longestWait: int, avgWait: int, estimatedWait: int},
     *   activeAgents: int,
     *   fcr: float,
     *   slaCompliance: array<string, array{compliance: float, target: float, status: string}>
     * } Dashboard metrics.
     *
     * @throws \RuntimeException If data cannot be retrieved.
     */
    public function getDashboard(): array
    {
        try {
            $config        = $this->getContactmomentConfig();
            $objectService = $this->getObjectService();

            // Get today's contact moments
            $today = new \DateTime();
            $today->setTime(0, 0, 0);
            $startOfToday = $today->format('Y-m-d\TH:i:s\Z');

            $tomorrow = clone $today;
            $tomorrow->modify('+1 day');
            $endOfToday = $tomorrow->format('Y-m-d\TH:i:s\Z');

            $todayMoments = $this->getContactMoments(
                $config,
                $objectService,
                $startOfToday,
                $endOfToday
            );

            // Get last week's same day contact moments
            $lastWeekStart = clone $today;
            $lastWeekStart->modify('-7 days');
            $lastWeekStart->setTime(0, 0, 0);
            $lastWeekStartStr = $lastWeekStart->format('Y-m-d\TH:i:s\Z');

            $lastWeekEnd = clone $lastWeekStart;
            $lastWeekEnd->modify('+1 day');
            $lastWeekEndStr = $lastWeekEnd->format('Y-m-d\TH:i:s\Z');

            $lastWeekMoments = $this->getContactMoments(
                $config,
                $objectService,
                $lastWeekStartStr,
                $lastWeekEndStr
            );

            $dashboard = [
                'timestamp'            => (new \DateTime())->format('c'),
                'totals'               => [
                    'today' => count($todayMoments),
                    'trend' => count($todayMoments) - count($lastWeekMoments),
                ],
                'byChannel'            => $this->aggregateByChannel($todayMoments, $lastWeekMoments),
                'avgHandlingTime'      => $this->calculateAvgHandlingTime($todayMoments),
                'avgHandlingTimeTrend' => $this->calculateHandlingTimeTrend($todayMoments, $lastWeekMoments),
                'queue'                => $this->calculateQueueMetrics($todayMoments),
                'activeAgents'         => $this->countActiveAgents($todayMoments),
                'fcr'                  => $this->calculateFcr($todayMoments),
                'slaCompliance'        => $this->calculateSlaCompliance($todayMoments),
            ];

            return $dashboard;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get KPI dashboard', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to load KPI dashboard data: '.$e->getMessage());
        }//end try
    }//end getDashboard()

    /**
     * Get contact moments within a date range.
     *
     * @param array<string, string>                   $config        Register and schema config.
     * @param \OCA\OpenRegister\Service\ObjectService $objectService The object service.
     * @param string                                  $startDate     ISO 8601 start date.
     * @param string                                  $endDate       ISO 8601 end date.
     *
     * @return array<array<string, mixed>> Contact moments.
     */
    private function getContactMoments(
        array $config,
        \OCA\OpenRegister\Service\ObjectService $objectService,
        string $startDate,
        string $endDate,
    ): array {
        try {
            $objectService->setRegister($config['register']);
            $objectService->setSchema($config['schema']);

            // Query with date range filter
            $params = [
                'createdAt' => [
                    'from' => $startDate,
                    'to'   => $endDate,
                ],
                '_limit'    => 500,
                '_offset'   => 0,
            ];

            $results = $objectService->findObjects($params);

            return is_array($results) ? $results : [];
        } catch (\Exception $e) {
            $this->logger->warning('Error fetching contact moments', ['error' => $e->getMessage()]);
            return [];
        }//end try
    }//end getContactMoments()

    /**
     * Aggregate contact moments by channel.
     *
     * @param array<array<string, mixed>> $todayMoments    Today's moments.
     * @param array<array<string, mixed>> $lastWeekMoments Last week's moments.
     *
     * @return array<string, array{count: int, trend: int, percentage: float}> Aggregation.
     */
    private function aggregateByChannel(array $todayMoments, array $lastWeekMoments): array
    {
        $channels   = ['telefoon', 'email', 'balie', 'chat', 'other'];
        $aggregated = [];
        $total      = count($todayMoments);

        foreach ($channels as $channel) {
            $todayCount    = count(
                array_filter(
                    $todayMoments,
                    static fn($m) => ($m['channel'] ?? '') === $channel
                )
            );
            $lastWeekCount = count(
                array_filter(
                    $lastWeekMoments,
                    static fn($m) => ($m['channel'] ?? '') === $channel
                )
            );

            $aggregated[$channel] = [
                'count'      => $todayCount,
                'trend'      => $todayCount - $lastWeekCount,
                'percentage' => $total > 0 ? round(($todayCount / $total) * 100, 1) : 0.0,
            ];
        }

        return $aggregated;
    }//end aggregateByChannel()

    /**
     * Calculate average handling time for today's contacts.
     *
     * @param array<array<string, mixed>> $moments Contact moments.
     *
     * @return string Formatted duration (MM:SS).
     */
    private function calculateAvgHandlingTime(array $moments): string
    {
        $totalSeconds = 0;
        $count        = 0;

        foreach ($moments as $moment) {
            if (isset($moment['duration']) && is_string($moment['duration'])) {
                try {
                    $interval      = new \DateInterval($moment['duration']);
                    $totalSeconds += ($interval->i * 60) + $interval->s;
                    $count++;
                } catch (\Exception $e) {
                    // Skip invalid durations
                    continue;
                }
            }
        }

        if ($count === 0) {
            return '0:00';
        }

        $avgSeconds = (int) ($totalSeconds / $count);
        $minutes    = (int) ($avgSeconds / 60);
        $seconds    = $avgSeconds % 60;

        return $minutes.':'.str_pad((string) $seconds, 2, '0', STR_PAD_LEFT);
    }//end calculateAvgHandlingTime()

    /**
     * Calculate handling time trend vs last week.
     *
     * @param array<array<string, mixed>> $todayMoments    Today's moments.
     * @param array<array<string, mixed>> $lastWeekMoments Last week's moments.
     *
     * @return int Difference in seconds (positive = slower).
     */
    private function calculateHandlingTimeTrend(array $todayMoments, array $lastWeekMoments): int
    {
        $today    = $this->getAverageHandlingSeconds($todayMoments);
        $lastWeek = $this->getAverageHandlingSeconds($lastWeekMoments);

        return $today - $lastWeek;
    }//end calculateHandlingTimeTrend()

    /**
     * Get average handling time in seconds.
     *
     * @param array<array<string, mixed>> $moments Contact moments.
     *
     * @return int Average seconds.
     */
    private function getAverageHandlingSeconds(array $moments): int
    {
        $totalSeconds = 0;
        $count        = 0;

        foreach ($moments as $moment) {
            if (isset($moment['duration']) && is_string($moment['duration'])) {
                try {
                    $interval      = new \DateInterval($moment['duration']);
                    $totalSeconds += ($interval->i * 60) + $interval->s;
                    $count++;
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return $count > 0 ? (int) ($totalSeconds / $count) : 0;
    }//end getAverageHandlingSeconds()

    /**
     * Calculate queue metrics (simulated as placeholder).
     *
     * @param array<array<string, mixed>> $moments Contact moments.
     *
     * @return array{current: int, longestWait: int, avgWait: int, estimatedWait: int} Queue metrics.
     */
    private function calculateQueueMetrics(array $moments): array
    {
        // Count moments currently in queue (not yet resolved)
        $queuedMoments = array_filter(
            $moments,
            static fn($m) => in_array($m['status'] ?? '', ['open', 'pending'], true)
        );

        $waitTimes = [];
        foreach ($queuedMoments as $moment) {
            if (isset($moment['createdAt'])) {
                try {
                    $createdAt   = new \DateTime($moment['createdAt']);
                    $now         = new \DateTime();
                    $wait        = $now->getTimestamp() - $createdAt->getTimestamp();
                    $waitTimes[] = $wait;
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return [
            'current'       => count($queuedMoments),
            'longestWait'   => count($waitTimes) > 0 ? max($waitTimes) : 0,
            'avgWait'       => count($waitTimes) > 0 ? (int) (array_sum($waitTimes) / count($waitTimes)) : 0,
            'estimatedWait' => count($queuedMoments) > 0 ? (int) (count($queuedMoments) * 300) : 0,
        ];
    }//end calculateQueueMetrics()

    /**
     * Count unique active agents for today.
     *
     * @param array<array<string, mixed>> $moments Contact moments.
     *
     * @return int Number of active agents.
     */
    private function countActiveAgents(array $moments): int
    {
        $agents = array_unique(array_column($moments, 'agent'));
        return count(array_filter($agents));
    }//end countActiveAgents()

    /**
     * Calculate first-call resolution rate.
     *
     * @param array<array<string, mixed>> $moments Contact moments.
     *
     * @return float FCR percentage.
     */
    private function calculateFcr(array $moments): float
    {
        if (count($moments) === 0) {
            return 0.0;
        }

        $resolved = count(
            array_filter(
                $moments,
                static fn($m) => ($m['resolved'] ?? false) === true || ($m['status'] ?? '') === 'resolved'
            )
        );

        return round(($resolved / count($moments)) * 100, 1);
    }//end calculateFcr()

    /**
     * Calculate SLA compliance per channel.
     *
     * @param array<array<string, mixed>> $moments Contact moments.
     *
     * @return array<string, array{compliance: float, target: float, status: string}> SLA status per channel.
     */
    private function calculateSlaCompliance(array $moments): array
    {
        $reportingService = new ReportingService($this->appConfig, $this->logger);
        $channels         = ['telefoon', 'email', 'balie', 'chat'];
        $slaStatus        = [];

        foreach ($channels as $channel) {
            $channelMoments = array_filter(
                $moments,
                static fn($m) => ($m['channel'] ?? '') === $channel
            );
            $total          = count($channelMoments);

            if ($total === 0) {
                $slaStatus[$channel] = [
                    'compliance' => 0.0,
                    'target'     => $reportingService->getSlaTarget($channel),
                    'status'     => 'gray',
                ];
                continue;
            }

            // Count moments within SLA (simplified: check if within expected handling time)
            $withinSla = count(
                array_filter(
                    $channelMoments,
                    static fn($m) => ($m['withinSla'] ?? false) === true
                )
            );

            $slaStatus[$channel] = $reportingService->calculateSlaCompliance(
                $channel,
                $total,
                $withinSla
            );
        }//end foreach

        return $slaStatus;
    }//end calculateSlaCompliance()
}//end class
