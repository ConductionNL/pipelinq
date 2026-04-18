<?php

/**
 * Pipelinq ChannelAnalyticsService.
 *
 * Service for channel-based analytics including volume, handling time, FCR, and SLA
 * compliance metrics with configurable time granularity.
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
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for channel-based analytics.
 *
 * Aggregates contact moment metrics per channel with support for different time
 * granularities (daily, weekly, monthly) and month-over-month comparison.
 */
class ChannelAnalyticsService
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
     * @return array<string, string> Configuration.
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
     * Get channel analytics for a date range with specified granularity.
     *
     * @param string $startDate   ISO 8601 start date.
     * @param string $endDate     ISO 8601 end date.
     * @param string $granularity 'daily', 'weekly', or 'monthly'.
     *
     * @return array<string, mixed> Channel analytics data with trend comparisons.
     *
     * @throws \RuntimeException If data cannot be retrieved.
     */
    public function getChannelAnalytics(
        string $startDate,
        string $endDate,
        string $granularity='daily'
    ): array {
        try {
            $config        = $this->getContactmomentConfig();
            $objectService = $this->getObjectService();

            // Fetch moments for current period
            $moments = $this->getContactMoments($config, $objectService, $startDate, $endDate);

            // Fetch moments for previous period for comparison
            $prevStartDate = $this->getPreviousPeriodStart($startDate, $granularity);
            $prevEndDate   = $this->getPreviousPeriodEnd($endDate, $granularity);
            $prevMoments   = $this->getContactMoments($config, $objectService, $prevStartDate, $prevEndDate);

            // Aggregate by channel
            $channelData = $this->aggregateChannels(
                moments: $moments,
                prevMoments: $prevMoments,
                granularity: $granularity
            );

            return [
                'period'   => [
                    'start'       => $startDate,
                    'end'         => $endDate,
                    'granularity' => $granularity,
                ],
                'channels' => $channelData,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get channel analytics', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to load channel analytics: '.$e->getMessage());
        }//end try
    }//end getChannelAnalytics()

    /**
     * Get contact moments within a date range.
     *
     * @param array<string, string>                   $config        Register and schema config.
     * @param \OCA\OpenRegister\Service\ObjectService $objectService Object service.
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
            $this->logger->warning(
                'Error fetching contact moments',
                ['error' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end getContactMoments()

    /**
     * Get start date of previous period for comparison.
     *
     * @param string $startDate   Current period start.
     * @param string $granularity Period granularity.
     *
     * @return string ISO 8601 date.
     */
    private function getPreviousPeriodStart(string $startDate, string $granularity): string
    {
        $date = new \DateTime($startDate);

        switch ($granularity) {
            case 'daily':
                $date->modify('-1 day');
                break;
            case 'weekly':
                $date->modify('-1 week');
                break;
            case 'monthly':
                $date->modify('-1 month');
                break;
        }

        return $date->format('Y-m-d\TH:i:s\Z');
    }//end getPreviousPeriodStart()

    /**
     * Get end date of previous period for comparison.
     *
     * @param string $endDate     Current period end.
     * @param string $granularity Period granularity.
     *
     * @return string ISO 8601 date.
     */
    private function getPreviousPeriodEnd(string $endDate, string $granularity): string
    {
        $date = new \DateTime($endDate);

        switch ($granularity) {
            case 'daily':
                $date->modify('-1 day');
                break;
            case 'weekly':
                $date->modify('-1 week');
                break;
            case 'monthly':
                $date->modify('-1 month');
                break;
        }

        return $date->format('Y-m-d\TH:i:s\Z');
    }//end getPreviousPeriodEnd()

    /**
     * Aggregate contact moments by channel with trend calculation.
     *
     * @param array<array<string, mixed>> $moments     Current period moments.
     * @param array<array<string, mixed>> $prevMoments Previous period moments.
     * @param string                      $granularity Period granularity.
     *
     * @return array<string, array<string, mixed>> Channel aggregates with trends.
     */
    private function aggregateChannels(
        array $moments,
        array $prevMoments,
        string $granularity
    ): array {
        $channels   = ['telefoon', 'email', 'balie', 'chat'];
        $aggregated = [];

        foreach ($channels as $channel) {
            $currentMoments     = array_filter(
                $moments,
                static fn($m) => ($m['channel'] ?? '') === $channel
            );
            $prevChannelMoments = array_filter(
                $prevMoments,
                static fn($m) => ($m['channel'] ?? '') === $channel
            );

            $currentCount = count($currentMoments);
            $prevCount    = count($prevChannelMoments);

            $aggregated[$channel] = [
                'volume'                 => $currentCount,
                'volumeTrend'            => $currentCount - $prevCount,
                'volumePercentageChange' => $prevCount > 0 ? round((($currentCount - $prevCount) / $prevCount) * 100, 1) : 0.0,
                'avgHandlingTime'        => $this->calculateAvgHandlingTime($currentMoments),
                'avgHandlingTimeTrend'   => $this->calculateHandlingTimeTrend(
                    $currentMoments,
                    $prevChannelMoments
                ),
                'fcr'                    => $this->calculateFcr($currentMoments),
                'fcrTrend'               => $this->calculateFcrTrend($currentMoments, $prevChannelMoments),
                'slaCompliance'          => $this->calculateSlaCompliance($channel, $currentMoments),
                'slaTrend'               => $this->calculateSlaTrend(
                    $channel,
                    $currentMoments,
                    $prevChannelMoments
                ),
            ];
        }//end foreach

        return $aggregated;
    }//end aggregateChannels()

    /**
     * Calculate average handling time for moments.
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
     * Calculate handling time trend in seconds.
     *
     * @param array<array<string, mixed>> $current Current moments.
     * @param array<array<string, mixed>> $prev    Previous moments.
     *
     * @return int Difference in seconds.
     */
    private function calculateHandlingTimeTrend(array $current, array $prev): int
    {
        $currentAvg = $this->getAverageHandlingSeconds($current);
        $prevAvg    = $this->getAverageHandlingSeconds($prev);

        return $currentAvg - $prevAvg;
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
     * Calculate first-call resolution rate percentage.
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
                static fn($m) => ($m['resolved'] ?? false) === true
                    || ($m['status'] ?? '') === 'resolved'
            )
        );

        return round(($resolved / count($moments)) * 100, 1);
    }//end calculateFcr()

    /**
     * Calculate FCR trend percentage change.
     *
     * @param array<array<string, mixed>> $current Current moments.
     * @param array<array<string, mixed>> $prev    Previous moments.
     *
     * @return float Percentage change.
     */
    private function calculateFcrTrend(array $current, array $prev): float
    {
        $currentFcr = $this->calculateFcr($current);
        $prevFcr    = $this->calculateFcr($prev);

        return round($currentFcr - $prevFcr, 1);
    }//end calculateFcrTrend()

    /**
     * Calculate SLA compliance for a channel.
     *
     * @param string                      $channel Channel name.
     * @param array<array<string, mixed>> $moments Contact moments.
     *
     * @return float Compliance percentage.
     */
    private function calculateSlaCompliance(string $channel, array $moments): float
    {
        if (count($moments) === 0) {
            return 0.0;
        }

        $withinSla = count(
            array_filter(
                $moments,
                static fn($m) => ($m['withinSla'] ?? false) === true
            )
        );

        return round(($withinSla / count($moments)) * 100, 1);
    }//end calculateSlaCompliance()

    /**
     * Calculate SLA compliance trend.
     *
     * @param string                      $channel Channel name.
     * @param array<array<string, mixed>> $current Current moments.
     * @param array<array<string, mixed>> $prev    Previous moments.
     *
     * @return float Percentage point change.
     */
    private function calculateSlaTrend(
        string $channel,
        array $current,
        array $prev
    ): float {
        $currentCompliance = $this->calculateSlaCompliance($channel, $current);
        $prevCompliance    = $this->calculateSlaCompliance($channel, $prev);

        return round($currentCompliance - $prevCompliance, 1);
    }//end calculateSlaTrend()
}//end class
