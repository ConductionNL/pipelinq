<?php

/**
 * Pipelinq RapportageService.
 *
 * Read-only aggregation service for the lead-management analytics dashboard.
 * Fetches lead objects from OpenRegister and computes pipeline value per stage,
 * lead-source performance, aging distribution and win/loss analysis.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/lead-management/tasks.md#6.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregates lead data into pipeline analytics summaries.
 *
 * The pure aggregation methods (computeStageValues / computeSourcePerformance /
 * computeAgingBuckets / computeWinLoss) operate on plain lead arrays so they can
 * be unit-tested without a running OpenRegister. getPipelineStats() wires the
 * OR fetch to those calculators.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is one cohesive
 * set of pipeline-analytics calculators (stage values, source performance,
 * aging buckets, win/loss) decomposed into small single-purpose helpers; the
 * residual class complexity reflects the breadth of aggregations, not any
 * single tangled method.
 *
 * @spec openspec/changes/lead-management/tasks.md#6.1
 */
class RapportageService
{
    /**
     * Status value for a won lead.
     */
    private const STATUS_WON = 'won';

    /**
     * Status value for a lost lead.
     */
    private const STATUS_LOST = 'lost';

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig  The app config.
     * @param IAppManager        $appManager The app manager.
     * @param ContainerInterface $container  The DI container.
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the full analytics payload for the rapportage dashboard.
     *
     * @param string|null $pipelineId Optional pipeline filter for stage values.
     *
     * @return array<string, mixed> The aggregated analytics.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    public function getPipelineStats(?string $pipelineId=null): array
    {
        $leads = $this->fetchLeads();

        return [
            'stageValues'       => $this->computeStageValues(leads: $leads, pipelineId: $pipelineId),
            'sourcePerformance' => $this->computeSourcePerformance(leads: $leads),
            'agingBuckets'      => $this->computeAgingBuckets(leads: $leads),
            'winLoss'           => $this->computeWinLoss(leads: $leads),
        ];
    }//end getPipelineStats()

    /**
     * Compute pipeline value per stage for all open leads.
     *
     * @param array<int, array<string, mixed>> $leads      The lead objects.
     * @param string|null                      $pipelineId Optional pipeline filter.
     *
     * @return array<int, array<string, mixed>> Per-stage count, totalValue and weightedValue.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    public function computeStageValues(array $leads, ?string $pipelineId=null): array
    {
        $stages = [];

        foreach ($leads as $lead) {
            if ($this->isClosed(lead: $lead) === true) {
                continue;
            }

            if ($pipelineId !== null && $pipelineId !== '' && (string) ($lead['pipeline'] ?? '') !== $pipelineId) {
                continue;
            }

            $stage = (string) ($lead['stage'] ?? '');
            if ($stage === '') {
                $stage = 'Onbekend';
            }

            $value       = $this->toFloat(value: ($lead['value'] ?? 0));
            $probability = $this->toFloat(value: ($lead['probability'] ?? 0));

            if (isset($stages[$stage]) === false) {
                $stages[$stage] = [
                    'stage'         => $stage,
                    'count'         => 0,
                    'totalValue'    => 0.0,
                    'weightedValue' => 0.0,
                ];
            }

            $stages[$stage]['count']++;
            $stages[$stage]['totalValue']    += $value;
            $stages[$stage]['weightedValue'] += ($value * $probability / 100);
        }//end foreach

        foreach ($stages as &$row) {
            $row['totalValue']    = round($row['totalValue'], 2);
            $row['weightedValue'] = round($row['weightedValue'], 2);
        }

        unset($row);

        return array_values($stages);
    }//end computeStageValues()

    /**
     * Compute conversion performance grouped by lead source.
     *
     * @param array<int, array<string, mixed>> $leads The lead objects.
     *
     * @return array<int, array<string, mixed>> Per-source total, won, conversionRate and avgWonValue.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    public function computeSourcePerformance(array $leads): array
    {
        $sources = [];

        foreach ($leads as $lead) {
            $source = (string) ($lead['source'] ?? '');
            if ($source === '') {
                $source = 'unknown';
            }

            if (isset($sources[$source]) === false) {
                $sources[$source] = [
                    'source'         => $source,
                    'total'          => 0,
                    'won'            => 0,
                    'wonValueSum'    => 0.0,
                    'conversionRate' => 0.0,
                    'avgWonValue'    => null,
                ];
            }

            $sources[$source]['total']++;

            if ((string) ($lead['status'] ?? '') === self::STATUS_WON) {
                $sources[$source]['won']++;
                $sources[$source]['wonValueSum'] += $this->toFloat(value: ($lead['value'] ?? 0));
            }
        }//end foreach

        foreach ($sources as &$row) {
            // Every grouped source has at least one lead (total is incremented on creation).
            $row['conversionRate'] = round(($row['won'] / $row['total']) * 100, 1);

            if ($row['won'] > 0) {
                $row['avgWonValue'] = round($row['wonValueSum'] / $row['won'], 2);
            }

            unset($row['wonValueSum']);
        }

        unset($row);

        return array_values($sources);
    }//end computeSourcePerformance()

    /**
     * Distribute open leads into aging buckets based on days since last activity.
     *
     * Uses `stageEnteredAt` when present (the precise stage-change timestamp on
     * the lead schema), otherwise falls back to the OpenRegister `_dateModified`
     * metadata as a proxy.
     *
     * @param array<int, array<string, mixed>> $leads The lead objects.
     *
     * @return array<int, array<string, mixed>> Bucket count and totalValue for 0-7d / 8-14d / 15-30d / 30d+.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    public function computeAgingBuckets(array $leads): array
    {
        $buckets = [
            '0-7d'   => ['bucket' => '0-7d', 'count' => 0, 'totalValue' => 0.0],
            '8-14d'  => ['bucket' => '8-14d', 'count' => 0, 'totalValue' => 0.0],
            '15-30d' => ['bucket' => '15-30d', 'count' => 0, 'totalValue' => 0.0],
            '30d+'   => ['bucket' => '30d+', 'count' => 0, 'totalValue' => 0.0],
        ];

        foreach ($leads as $lead) {
            if ($this->isClosed(lead: $lead) === true) {
                continue;
            }

            $days  = $this->daysSince(timestamp: $this->lastActivity(lead: $lead));
            $value = $this->toFloat(value: ($lead['value'] ?? 0));
            $key   = $this->bucketKey(days: $days);

            $buckets[$key]['count']++;
            $buckets[$key]['totalValue'] += $value;
        }//end foreach

        foreach ($buckets as &$row) {
            $row['totalValue'] = round($row['totalValue'], 2);
        }

        unset($row);

        return array_values($buckets);
    }//end computeAgingBuckets()

    /**
     * Compute win/loss analysis over closed leads.
     *
     * @param array<int, array<string, mixed>> $leads The lead objects.
     *
     * @return array<string, mixed> wonCount, lostCount, winRate, avgWonValue, avgLostValue and avgDaysToClose.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    public function computeWinLoss(array $leads): array
    {
        $won  = $this->leadsWithStatus(leads: $leads, status: self::STATUS_WON);
        $lost = $this->leadsWithStatus(leads: $leads, status: self::STATUS_LOST);

        $wonCount  = count($won);
        $lostCount = count($lost);
        $closed    = ($wonCount + $lostCount);

        $winRate = 0.0;
        if ($closed > 0) {
            $winRate = round(($wonCount / $closed) * 100, 1);
        }

        $daysToClose = [];
        foreach (array_merge($won, $lost) as $lead) {
            $span = $this->closeSpanDays(lead: $lead);
            if ($span !== null) {
                $daysToClose[] = $span;
            }
        }

        $avgDays        = $this->safeAverage(values: $daysToClose);
        $avgDaysToClose = null;
        if ($avgDays !== null) {
            $avgDaysToClose = (int) round($avgDays);
        }

        return [
            'wonCount'       => $wonCount,
            'lostCount'      => $lostCount,
            'winRate'        => $winRate,
            'avgWonValue'    => $this->safeAverage(values: $this->leadValues(leads: $won)),
            'avgLostValue'   => $this->safeAverage(values: $this->leadValues(leads: $lost)),
            'avgDaysToClose' => $avgDaysToClose,
        ];
    }//end computeWinLoss()

    /**
     * Filter the leads down to those with a given status.
     *
     * @param array<int, array<string, mixed>> $leads  The lead objects.
     * @param string                           $status The status to match.
     *
     * @return array<int, array<string, mixed>> The matching leads.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function leadsWithStatus(array $leads, string $status): array
    {
        $matched = [];
        foreach ($leads as $lead) {
            if ((string) ($lead['status'] ?? '') === $status) {
                $matched[] = $lead;
            }
        }

        return $matched;
    }//end leadsWithStatus()

    /**
     * Extract numeric values from a set of leads.
     *
     * @param array<int, array<string, mixed>> $leads The lead objects.
     *
     * @return array<int, float> The lead values.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function leadValues(array $leads): array
    {
        $values = [];
        foreach ($leads as $lead) {
            $values[] = $this->toFloat(value: ($lead['value'] ?? 0));
        }

        return $values;
    }//end leadValues()

    /**
     * Compute a rounded average over a list, or null when empty.
     *
     * @param array<int, float|int> $values The values to average.
     *
     * @return float|null The average rounded to two decimals, or null.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function safeAverage(array $values): ?float
    {
        if (count($values) === 0) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }//end safeAverage()

    /**
     * Fetch all lead objects from OpenRegister.
     *
     * Returns an empty array (never throws) when OpenRegister is unavailable or
     * the lead register/schema is not configured, so the dashboard degrades to
     * an empty state rather than an error.
     *
     * @return array<int, array<string, mixed>> The lead objects as arrays.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function fetchLeads(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');

        if ($register === '' || $schema === '') {
            return [];
        }

        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            return [];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $results       = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                    'limit'   => 10000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Pipelinq: failed to fetch leads for rapportage', ['exception' => $e->getMessage()]);
            return [];
        }

        $leads = [];
        foreach (($results ?? []) as $result) {
            $leads[] = $this->toArray(object: $result);
        }

        return $leads;
    }//end fetchLeads()

    /**
     * Normalise an OpenRegister object (entity or array) into a flat array,
     * surfacing the `_dateModified` metadata used for aging.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The lead as an array.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function toArray(mixed $object): array
    {
        $data = [];

        if (is_array($object) === true) {
            $data = $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $data = (array) $object->jsonSerialize();
        } else if (is_object($object) === true) {
            $data = (array) $object;
        }

        if ($data === []) {
            return [];
        }

        $self = $data['@self'] ?? null;
        if (is_array($self) === true && isset($data['_dateModified']) === false) {
            $data['_dateModified'] = ($self['updated'] ?? $self['dateModified'] ?? null);
        }

        return $data;
    }//end toArray()

    /**
     * Select the aging bucket key for a given number of days.
     *
     * @param int $days Whole days since last activity.
     *
     * @return string One of 0-7d / 8-14d / 15-30d / 30d+.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function bucketKey(int $days): string
    {
        if ($days <= 7) {
            return '0-7d';
        }

        if ($days <= 14) {
            return '8-14d';
        }

        if ($days <= 30) {
            return '15-30d';
        }

        return '30d+';
    }//end bucketKey()

    /**
     * Determine the last-activity timestamp for aging.
     *
     * @param array<string, mixed> $lead The lead.
     *
     * @return string|null An ISO timestamp, or null when unknown.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function lastActivity(array $lead): ?string
    {
        $value = ($lead['stageEnteredAt'] ?? $lead['_dateModified'] ?? null);
        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        return null;
    }//end lastActivity()

    /**
     * Compute whole days elapsed since the given timestamp.
     *
     * @param string|null $timestamp An ISO timestamp.
     *
     * @return int The number of whole days (0 when unknown).
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function daysSince(?string $timestamp): int
    {
        if ($timestamp === null) {
            return 0;
        }

        $time = strtotime($timestamp);
        if ($time === false) {
            return 0;
        }

        $diff = (time() - $time);
        if ($diff < 0) {
            return 0;
        }

        return (int) floor($diff / 86400);
    }//end daysSince()

    /**
     * Compute days between lead creation and its close timestamp.
     *
     * @param array<string, mixed> $lead The closed lead.
     *
     * @return int|null The span in days, or null when timestamps are missing.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function closeSpanDays(array $lead): ?int
    {
        $created = ($lead['_dateCreated'] ?? null);
        $closed  = ($lead['_dateModified'] ?? $lead['stageEnteredAt'] ?? null);

        if (is_string($created) === false || is_string($closed) === false) {
            return null;
        }

        $createdTime = strtotime($created);
        $closedTime  = strtotime($closed);

        if ($createdTime === false || $closedTime === false || $closedTime < $createdTime) {
            return null;
        }

        return (int) floor(($closedTime - $createdTime) / 86400);
    }//end closeSpanDays()

    /**
     * Whether a lead is in a closed (won/lost) state.
     *
     * @param array<string, mixed> $lead The lead.
     *
     * @return bool True when the lead status is won or lost.
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function isClosed(array $lead): bool
    {
        $status = (string) ($lead['status'] ?? '');
        return ($status === self::STATUS_WON || $status === self::STATUS_LOST);
    }//end isClosed()

    /**
     * Cast a mixed numeric value to float.
     *
     * @param mixed $value The value.
     *
     * @return float The float value (0.0 when non-numeric).
     *
     * @spec openspec/changes/lead-management/tasks.md#6.1
     */
    private function toFloat(mixed $value): float
    {
        if (is_numeric($value) === true) {
            return (float) $value;
        }

        return 0.0;
    }//end toFloat()
}//end class
