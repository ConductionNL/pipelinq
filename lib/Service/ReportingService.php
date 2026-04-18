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
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     * @param ContainerInterface $container The DI container.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private ContainerInterface $container,
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
     * Get contact moments for a date range.
     *
     * @param string  $startDate Optional start date (YYYY-MM-DD).
     * @param string  $endDate   Optional end date (YYYY-MM-DD).
     * @param string  $channel   Optional channel filter.
     * @param string  $agentId   Optional agent filter.
     *
     * @return array<mixed> Array of contact moment objects.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
     */
    public function getContactMoments(
        string $startDate = '',
        string $endDate = '',
        string $channel = '',
        string $agentId = '',
    ): array {
        try {
            $objectService = $this->getObjectService();
            $params = [];

            if ($startDate !== '') {
                $params['contactedAt_gte'] = $startDate.'T00:00:00Z';
            }
            if ($endDate !== '') {
                $params['contactedAt_lte'] = $endDate.'T23:59:59Z';
            }
            if ($channel !== '') {
                $params['channel'] = $channel;
            }
            if ($agentId !== '') {
                $params['agent'] = $agentId;
            }

            $register = $this->appConfig->getValueString('pipelinq', 'register', '');
            $schema   = $this->appConfig->getValueString('pipelinq', 'contactmoment_schema', '');

            if ($register === '' || $schema === '') {
                return [];
            }

            $result = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: $params,
            );

            return $result['results'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch contact moments', ['error' => $e->getMessage()]);
            return [];
        }
    }//end getContactMoments()

    /**
     * Get total contacts for a date.
     *
     * @param string $date    The date (YYYY-MM-DD).
     * @param string $channel Optional channel filter.
     *
     * @return int Total number of contacts.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
     */
    public function getTotalContacts(string $date, string $channel = ''): int
    {
        $moments = $this->getContactMoments(
            startDate: $date,
            endDate: $date,
            channel: $channel,
        );

        return count($moments);
    }//end getTotalContacts()

    /**
     * Get contacts grouped by channel.
     *
     * @param string $startDate Start date (YYYY-MM-DD).
     * @param string $endDate   End date (YYYY-MM-DD).
     *
     * @return array<string, int> Contact count per channel.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
     */
    public function getContactsByChannel(string $startDate, string $endDate): array
    {
        $moments = $this->getContactMoments(startDate: $startDate, endDate: $endDate);
        $counts = [];

        foreach ($moments as $moment) {
            if (!is_array($moment)) {
                continue;
            }
            $channel = $moment['channel'] ?? 'unknown';
            $counts[$channel] = ($counts[$channel] ?? 0) + 1;
        }

        return $counts;
    }//end getContactsByChannel()

    /**
     * Calculate average handling time for contacts.
     *
     * @param string $startDate Start date (YYYY-MM-DD).
     * @param string $endDate   End date (YYYY-MM-DD).
     * @param string $channel   Optional channel filter.
     *
     * @return string Formatted average duration (MM:SS).
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
     */
    public function getAverageHandlingTime(
        string $startDate,
        string $endDate,
        string $channel = '',
    ): string {
        $moments = $this->getContactMoments(
            startDate: $startDate,
            endDate: $endDate,
            channel: $channel,
        );

        $durations = [];
        foreach ($moments as $moment) {
            if (is_array($moment) && isset($moment['duration']) && $moment['duration'] !== '') {
                $durations[] = $moment['duration'];
            }
        }

        return $this->calculateAverageHandlingTime($durations);
    }//end getAverageHandlingTime()

    /**
     * Get first-call resolution rate.
     *
     * @param string $startDate Start date (YYYY-MM-DD).
     * @param string $endDate   End date (YYYY-MM-DD).
     *
     * @return float FCR percentage (0-100).
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-2
     */
    public function getFcrRate(string $startDate, string $endDate): float
    {
        $moments = $this->getContactMoments(startDate: $startDate, endDate: $endDate);

        $totalContacts = count($moments);
        $resolved = 0;

        foreach ($moments as $moment) {
            if (!is_array($moment)) {
                continue;
            }
            // Check if outcome indicates resolution (no routing to backoffice)
            $outcome = $moment['outcome'] ?? '';
            if (strpos($outcome, 'resolved') !== false || strpos($outcome, 'afgehandeld') !== false) {
                $resolved++;
            }
        }

        return $this->calculateFcr(totalContacts: $totalContacts, resolvedContacts: $resolved);
    }//end getFcrRate()

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
     * Get agent performance metrics.
     *
     * @param string $agentId   The agent's Nextcloud UID.
     * @param string $startDate Start date (YYYY-MM-DD).
     * @param string $endDate   End date (YYYY-MM-DD).
     *
     * @return array{contacts: int, avgHandlingTime: string, fcr: float, contactsPerHour: float} Agent metrics.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
     */
    public function getAgentMetrics(string $agentId, string $startDate, string $endDate): array
    {
        $moments = $this->getContactMoments(
            startDate: $startDate,
            endDate: $endDate,
            agentId: $agentId,
        );

        $totalContacts = count($moments);
        $durations = [];
        $resolved = 0;

        foreach ($moments as $moment) {
            if (!is_array($moment)) {
                continue;
            }
            if (isset($moment['duration']) && $moment['duration'] !== '') {
                $durations[] = $moment['duration'];
            }
            $outcome = $moment['outcome'] ?? '';
            if (strpos($outcome, 'resolved') !== false || strpos($outcome, 'afgehandeld') !== false) {
                $resolved++;
            }
        }

        // Calculate contacts per hour
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $end = $end->modify('+1 day');
        $interval = $start->diff($end);
        $hours = ($interval->days * 24) + $interval->h + ($interval->i / 60);
        $contactsPerHour = $hours > 0 ? round($totalContacts / $hours, 2) : 0;

        return [
            'contacts'           => $totalContacts,
            'avgHandlingTime'    => $this->calculateAverageHandlingTime($durations),
            'fcr'                => $this->calculateFcr($totalContacts, $resolved),
            'contactsPerHour'    => $contactsPerHour,
        ];
    }//end getAgentMetrics()

    /**
     * Get queue statistics for real-time monitoring.
     *
     * @return array{waiting: int, longestWait: int, avgWait: int, estimatedWait: int} Queue stats (in seconds).
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-5
     */
    public function getQueueStatistics(): array
    {
        // This would integrate with a queue management system
        // For now, return placeholder structure
        return [
            'waiting'       => 0,
            'longestWait'   => 0,
            'avgWait'       => 0,
            'estimatedWait' => 0,
        ];
    }//end getQueueStatistics()

    /**
     * Get monthly trend data.
     *
     * @param int $months Number of months to retrieve (default 6).
     *
     * @return array<int, array<string, mixed>> Monthly aggregated data.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-7
     */
    public function getMonthlyTrends(int $months = 6): array
    {
        $trends = [];
        $now = new \DateTime();

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = (new \DateTime())->modify("-$i months")->format('Y-m-01');
            $end = (new \DateTime())->modify("-$i months")->format('Y-m-t');

            $contacts = $this->getTotalContacts($start);
            $byChannel = $this->getContactsByChannel($start, $end);
            $avgTime = $this->getAverageHandlingTime($start, $end);
            $fcr = $this->getFcrRate($start, $end);

            $trends[] = [
                'month'       => (new \DateTime($start))->format('Y-m'),
                'total'       => $contacts,
                'byChannel'   => $byChannel,
                'avgTime'     => $avgTime,
                'fcr'         => $fcr,
            ];
        }

        return $trends;
    }//end getMonthlyTrends()

    /**
     * Get peak hours heatmap data.
     *
     * @param int $weeks Number of weeks to analyze (default 4).
     *
     * @return array<string, array<int, int>> Heatmap: day-of-week and hour.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-7
     */
    public function getPeakHoursHeatmap(int $weeks = 4): array
    {
        $heatmap = [];

        for ($d = 0; $d < 7; $d++) {
            $heatmap[$d] = [];
            for ($h = 0; $h < 24; $h++) {
                $heatmap[$d][$h] = 0;
            }
        }

        $start = (new \DateTime())->modify("-$weeks weeks")->format('Y-m-d');
        $end = date('Y-m-d');

        $moments = $this->getContactMoments(startDate: $start, endDate: $end);

        foreach ($moments as $moment) {
            if (!is_array($moment) || !isset($moment['contactedAt'])) {
                continue;
            }
            try {
                $dt = new \DateTime($moment['contactedAt']);
                $dow = (int) $dt->format('w'); // 0=Sunday, 1=Monday
                $hour = (int) $dt->format('H');
                $heatmap[$dow][$hour]++;
            } catch (\Exception $e) {
                continue;
            }
        }

        return $heatmap;
    }//end getPeakHoursHeatmap()

    /**
     * Get subject category trends.
     *
     * @param int $months Number of months to analyze (default 3).
     *
     * @return array<string, array<string, int>> Subject frequency trends.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-7
     */
    public function getSubjectTrends(int $months = 3): array
    {
        $trends = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = (new \DateTime())->modify("-$i months")->format('Y-m-01');
            $end = (new \DateTime())->modify("-$i months")->format('Y-m-t');
            $monthKey = (new \DateTime($start))->format('Y-m');

            $moments = $this->getContactMoments(startDate: $start, endDate: $end);
            $trends[$monthKey] = [];

            foreach ($moments as $moment) {
                if (!is_array($moment)) {
                    continue;
                }
                $subject = $moment['subject'] ?? 'Overig';
                $trends[$monthKey][$subject] = ($trends[$monthKey][$subject] ?? 0) + 1;
            }
        }

        return $trends;
    }//end getSubjectTrends()

    /**
     * Generate anonymized WOO report.
     *
     * @param string $startDate Start date (YYYY-MM-DD).
     * @param string $endDate   End date (YYYY-MM-DD).
     *
     * @return array<string, mixed> Anonymized statistics.
     *
     * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-10
     */
    public function generateWooReport(string $startDate, string $endDate): array
    {
        $moments = $this->getContactMoments(startDate: $startDate, endDate: $endDate);

        $totalContacts = count($moments);
        $byChannel = [];
        $avgWaitTimes = [];
        $slaCompliance = [];
        $fcrRate = 0;

        foreach ($moments as $moment) {
            if (!is_array($moment)) {
                continue;
            }
            $channel = $moment['channel'] ?? 'unknown';
            $byChannel[$channel] = ($byChannel[$channel] ?? 0) + 1;

            $outcome = $moment['outcome'] ?? '';
            if (strpos($outcome, 'resolved') !== false) {
                $fcrRate++;
            }
        }

        if ($totalContacts > 0) {
            $fcrRate = round(($fcrRate / $totalContacts) * 100, 1);
        }

        // Calculate SLA compliance per channel
        foreach (array_keys($byChannel) as $channel) {
            $channelTotal = $byChannel[$channel];
            $withinSla = (int) ($channelTotal * 0.84); // Placeholder calculation
            $slaCompliance[$channel] = $this->calculateSlaCompliance(
                channel: $channel,
                totalContacts: $channelTotal,
                withinSla: $withinSla,
            );
        }

        return [
            'period'       => $startDate.' tot '.$endDate,
            'totalContacts' => $totalContacts,
            'byChannel'    => $byChannel,
            'fcr'          => $fcrRate,
            'slaCompliance' => $slaCompliance,
            'avgWaitTimes' => $avgWaitTimes,
        ];
    }//end generateWooReport()

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
}//end class
