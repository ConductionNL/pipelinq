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
use Psr\Log\LoggerInterface;

/**
 * Service for reporting and KPI calculations.
 *
 * Provides methods for calculating KPIs, SLA compliance, channel distribution,
 * and agent performance metrics from contactmoment data.
 *
 * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.1
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
     * @param IAppConfig      $appConfig The app config.
     * @param LoggerInterface $logger    The logger.
     *
     * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.1
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Calculate first-call resolution rate.
     *
     * @param int $totalContacts    Total contact moments.
     * @param int $resolvedContacts Contacts resolved without backoffice routing.
     *
     * @return float FCR as a percentage (0-100).
     *
     * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.1
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
     *
     * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.1
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
     *
     * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.1
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
     *
     * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.1
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
     *
     * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.1
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
     *
     * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.1
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
     *
     * @spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md#task-1.1
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
                $totalSeconds += ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
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
