<?php

/**
 * Pipelinq AnalyticsService.
 *
 * Server-side aggregation of cross-module KPIs (open pipeline value,
 * open requests, contactmomenten count, active leads) for the
 * Klantbeeld 360 analytics dashboard. Avoids fetching full collections
 * client-side on large installations.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Cross-module analytics summary service.
 *
 * Stateless. Reads leads, requests and contactmomenten via the
 * OpenRegister ObjectService, aggregates counts/sums in PHP and
 * returns the four headline KPIs for the Analytics dashboard.
 *
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
 */
class AnalyticsService
{
    /**
     * Allowed period selectors (ADR-018, REQ-KB360-021).
     *
     * @var array<int, string>
     */
    public const ALLOWED_PERIODS = ['week', 'month', 'quarter'];

    /**
     * Default period when caller supplies none.
     *
     * @var string
     */
    public const DEFAULT_PERIOD = 'month';

    /**
     * Statuses we consider "closed" for service requests.
     *
     * Anything NOT in this set counts as an open request
     * (REQ-KB360-020 / Feature 3 KPI 2).
     *
     * @var array<int, string>
     */
    private const REQUEST_CLOSED_STATUSES = ['closed', 'rejected'];

    /**
     * Statuses considered "active" (open) for leads.
     *
     * The spec wording uses `active` as the shorthand for "still in
     * the pipeline"; the actual lead schema enum is
     * `open|won|lost` (lib/Settings/pipelinq_register.json -> lead.status).
     * `open` is the canonical match; we accept both for resilience.
     *
     * @var array<int, string>
     */
    private const LEAD_ACTIVE_STATUSES = ['open', 'active'];

    /**
     * Allowed trend metric identifiers.
     *
     * @var array<int, string>
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public const ALLOWED_TREND_METRICS = ['leads', 'requests-by-category', 'pipeline-value'];

    /**
     * Trailing-window days per period (used by both summary and overview).
     *
     * @var array<string, int>
     */
    private const PERIOD_DAYS = [
        'week'    => 7,
        'month'   => 30,
        'quarter' => 90,
        'year'    => 365,
    ];

    /**
     * Allowed overview period selectors. Adds `year` on top of `ALLOWED_PERIODS`.
     *
     * @var array<int, string>
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public const ALLOWED_OVERVIEW_PERIODS = ['week', 'month', 'quarter', 'year'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (OpenRegister lookup).
     * @param IAppConfig         $appConfig App configuration (register/schema IDs).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute the cross-module summary for the given period.
     *
     * Returns:
     *   - openPipelineValue:    sum of `value` over active leads (all-time)
     *   - openRequests:         count of requests with status NOT in {closed, rejected}
     *   - contactmomentenCount: count of contactmomenten within the period window
     *   - activeLeads:          count of leads with status = 'active'
     *
     * @param string $period One of `ALLOWED_PERIODS`.
     *
     * @return array{
     *   openPipelineValue: float,
     *   openRequests: int,
     *   contactmomentenCount: int,
     *   activeLeads: int,
     *   period: string
     * }
     *
     * @throws InvalidArgumentException When the period is not recognised.
     * @throws RuntimeException         When OpenRegister is unavailable.
     *
     * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
     */
    public function getSummary(string $period=self::DEFAULT_PERIOD): array
    {
        if (in_array($period, self::ALLOWED_PERIODS, true) === false) {
            throw new InvalidArgumentException(message: 'Invalid period');
        }

        $boundary = $this->getPeriodBoundary(period: $period);

        $leads           = $this->findObjects(schemaKey: 'lead_schema');
        $requests        = $this->findObjects(schemaKey: 'request_schema');
        $contactmomenten = $this->findObjects(schemaKey: 'contactmoment_schema');

        $openPipelineValue = 0.0;
        $activeLeads       = 0;
        foreach ($leads as $lead) {
            $status = (string) ($lead['status'] ?? '');
            if (in_array($status, self::LEAD_ACTIVE_STATUSES, true) === true) {
                $activeLeads++;
                $openPipelineValue += (float) ($lead['value'] ?? 0);
            }
        }

        $openRequests = 0;
        foreach ($requests as $request) {
            $status = (string) ($request['status'] ?? '');
            if (in_array($status, self::REQUEST_CLOSED_STATUSES, true) === false) {
                $openRequests++;
            }
        }

        $contactmomentenCount = 0;
        $boundaryTimestamp    = $boundary->getTimestamp();
        foreach ($contactmomenten as $cm) {
            $contactedAt = (string) ($cm['contactedAt'] ?? '');
            if ($contactedAt === '') {
                continue;
            }

            $ts = strtotime($contactedAt);
            if ($ts === false) {
                continue;
            }

            if ($ts >= $boundaryTimestamp) {
                $contactmomentenCount++;
            }
        }

        return [
            'openPipelineValue'    => round($openPipelineValue, 2),
            'openRequests'         => $openRequests,
            'contactmomentenCount' => $contactmomentenCount,
            'activeLeads'          => $activeLeads,
            'period'               => $period,
        ];
    }//end getSummary()

    /**
     * Compute the inclusive lower boundary for a period selector.
     *
     * - `week`    = now minus 7 days
     * - `month`   = now minus 30 days
     * - `quarter` = now minus 90 days
     *
     * Implementation note: trailing windows (rather than calendar week /
     * calendar month) are used so the KPI is meaningful regardless of
     * the day a user opens the dashboard.
     *
     * @param string $period One of `ALLOWED_PERIODS`.
     *
     * @return DateTimeInterface Inclusive lower bound.
     *
     * @throws InvalidArgumentException When the period is not recognised.
     *
     * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
     */
    public function getPeriodBoundary(string $period): DateTimeInterface
    {
        $now = new DateTimeImmutable();

        return match ($period) {
            'week'    => $now->modify('-7 days'),
            'month'   => $now->modify('-30 days'),
            'quarter' => $now->modify('-90 days'),
            default   => throw new InvalidArgumentException(message: 'Invalid period'),
        };
    }//end getPeriodBoundary()

    /**
     * Query objects of a configured schema via OpenRegister ObjectService.
     *
     * Returns a list of plain arrays. Empty register/schema config or
     * unavailable OpenRegister is treated as "no data" (logged); other
     * failures are surfaced via RuntimeException to the controller.
     *
     * @param string $schemaKey The app-config key holding the schema ID.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException When OpenRegister is unreachable mid-query.
     */
    private function findObjects(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($register === '' || $schema === '') {
            $this->logger->info(
                message: '[AnalyticsService] register or schema not configured',
                context: ['schemaKey' => $schemaKey]
            );
            return [];
        }

        try {
            $objectService = $this->getObjectService();
            $results       = $objectService->findAll(
                config: ['filters' => ['register' => $register, 'schema' => $schema]]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[AnalyticsService] findAll failed',
                context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
            );
            throw new RuntimeException(message: 'Analytics query failed', code: 0, previous: $e);
        }

        $objects = [];
        foreach (($results ?? []) as $result) {
            $objects[] = $this->toArray(object: $result);
        }

        return $objects;
    }//end findObjects()

    /**
     * Normalize an OpenRegister entity (or array) to a plain array.
     *
     * @param mixed $object Entity / array / unknown payload.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return [];
    }//end toArray()

    /**
     * Resolve the OpenRegister ObjectService via DI container.
     *
     * @return object The ObjectService instance.
     *
     * @throws RuntimeException When OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException(message: 'OpenRegister ObjectService is unavailable.', code: 0, previous: $e);
        }
    }//end getObjectService()

    /**
     * Cross-module KPI overview for the unified analytics widget.
     *
     * Computes:
     *   - leadConversionRate:        won-leads / total-leads within the period (0-100)
     *   - avgRequestResolutionTime:  mean hours between requestedAt and completedAt
     *                                for requests resolved within the period (null if none)
     *   - contactMomentVolume:       count of contactmoments within the period
     *   - customerSatisfactionScore: mean surveyResponse.score within the period (null if none)
     *   - period:                    echo of the period key
     *   - previousPeriod:            same metric block for the prior equal-length window
     *
     * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
     *
     * @return array{
     *   leadConversionRate: float|null,
     *   avgRequestResolutionTime: float|null,
     *   contactMomentVolume: int,
     *   customerSatisfactionScore: float|null,
     *   period: string,
     *   previousPeriod: array<string, mixed>
     * }
     *
     * @throws InvalidArgumentException When the period is not recognised.
     * @throws RuntimeException         When OpenRegister is unreachable.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function getOverview(string $period=self::DEFAULT_PERIOD): array
    {
        if (in_array($period, self::ALLOWED_OVERVIEW_PERIODS, true) === false) {
            throw new InvalidArgumentException(message: 'Invalid period');
        }

        $days = self::PERIOD_DAYS[$period];
        $now  = new DateTimeImmutable();

        $currentStart  = $now->modify(sprintf('-%d days', $days));
        $previousStart = $currentStart->modify(sprintf('-%d days', $days));

        $leads     = $this->findObjects(schemaKey: 'lead_schema');
        $requests  = $this->findObjects(schemaKey: 'request_schema');
        $cms       = $this->findObjects(schemaKey: 'contactmoment_schema');
        $responses = $this->findObjects(schemaKey: 'surveyresponse_schema');

        $current  = $this->aggregateOverviewWindow(
            from: $currentStart,
            to: $now,
            leads: $leads,
            requests: $requests,
            cms: $cms,
            responses: $responses
        );
        $previous = $this->aggregateOverviewWindow(
            from: $previousStart,
            to: $currentStart,
            leads: $leads,
            requests: $requests,
            cms: $cms,
            responses: $responses
        );

        return [
            'leadConversionRate'        => $current['leadConversionRate'],
            'avgRequestResolutionTime'  => $current['avgRequestResolutionTime'],
            'contactMomentVolume'       => $current['contactMomentVolume'],
            'customerSatisfactionScore' => $current['customerSatisfactionScore'],
            'period'                    => $period,
            'previousPeriod'            => $previous,
        ];
    }//end getOverview()

    /**
     * Aggregate the four overview KPIs for one time window.
     *
     * Stateless helper called twice from getOverview() (current + previous).
     *
     * @param DateTimeInterface             $from      Inclusive lower bound.
     * @param DateTimeInterface             $to        Exclusive upper bound.
     * @param array<int, array<string, mixed>> $leads     Lead rows.
     * @param array<int, array<string, mixed>> $requests  Request rows.
     * @param array<int, array<string, mixed>> $cms       Contactmoment rows.
     * @param array<int, array<string, mixed>> $responses Survey-response rows.
     *
     * @return array<string, float|int|null>
     */
    private function aggregateOverviewWindow(
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $leads,
        array $requests,
        array $cms,
        array $responses,
    ): array {
        $fromTs = $from->getTimestamp();
        $toTs   = $to->getTimestamp();

        // Lead conversion rate.
        $totalLeads = 0;
        $wonLeads   = 0;
        foreach ($leads as $lead) {
            $ts = $this->extractTimestamp($lead, ['createdAt', 'created', 'expectedCloseDate']);
            if ($ts === null || $ts < $fromTs || $ts >= $toTs) {
                continue;
            }
            $totalLeads++;
            if (((string) ($lead['status'] ?? '')) === 'won') {
                $wonLeads++;
            }
        }
        $leadConversionRate = $totalLeads === 0 ? null : round(($wonLeads * 100.0) / $totalLeads, 1);

        // Avg request resolution time.
        $resolutionHours = 0.0;
        $resolvedCount   = 0;
        foreach ($requests as $request) {
            $requestedTs = $this->extractTimestamp($request, ['requestedAt']);
            $completedTs = $this->extractTimestamp($request, ['completedAt']);
            if ($requestedTs === null || $completedTs === null) {
                continue;
            }
            if ($completedTs < $fromTs || $completedTs >= $toTs) {
                continue;
            }
            $resolutionHours += max(0, ($completedTs - $requestedTs) / 3600.0);
            $resolvedCount++;
        }
        $avgRequestResolutionTime = $resolvedCount === 0 ? null : round($resolutionHours / $resolvedCount, 2);

        // Contact moment volume.
        $cmCount = 0;
        foreach ($cms as $cm) {
            $ts = $this->extractTimestamp($cm, ['contactedAt', 'createdAt']);
            if ($ts === null || $ts < $fromTs || $ts >= $toTs) {
                continue;
            }
            $cmCount++;
        }

        // Customer satisfaction score (mean of surveyResponse.score).
        $scoreSum = 0.0;
        $scoreN   = 0;
        foreach ($responses as $r) {
            $ts = $this->extractTimestamp($r, ['submittedAt', 'createdAt']);
            if ($ts === null || $ts < $fromTs || $ts >= $toTs) {
                continue;
            }
            if (isset($r['score']) === false || is_numeric($r['score']) === false) {
                continue;
            }
            $scoreSum += (float) $r['score'];
            $scoreN++;
        }
        $customerSatisfactionScore = $scoreN === 0 ? null : round($scoreSum / $scoreN, 2);

        return [
            'leadConversionRate'        => $leadConversionRate,
            'avgRequestResolutionTime'  => $avgRequestResolutionTime,
            'contactMomentVolume'       => $cmCount,
            'customerSatisfactionScore' => $customerSatisfactionScore,
        ];
    }//end aggregateOverviewWindow()

    /**
     * Time-series trend data for the unified analytics charts.
     *
     * Supported metrics:
     *   - `leads`                — count of leads bucketed by createdAt
     *   - `pipeline-value`       — sum of value over leads bucketed by createdAt
     *   - `requests-by-category` — count of requests grouped by category
     *                              (returns one series entry per category)
     *
     * @param string $metric One of ALLOWED_TREND_METRICS.
     * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
     *
     * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
     *
     * @throws InvalidArgumentException When metric or period unsupported.
     * @throws RuntimeException         When OpenRegister is unreachable.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function getTrends(string $metric, string $period=self::DEFAULT_PERIOD): array
    {
        if (in_array($metric, self::ALLOWED_TREND_METRICS, true) === false) {
            throw new InvalidArgumentException(message: 'Unsupported metric');
        }
        if (in_array($period, self::ALLOWED_OVERVIEW_PERIODS, true) === false) {
            throw new InvalidArgumentException(message: 'Invalid period');
        }

        if ($metric === 'requests-by-category') {
            return $this->buildCategorySeries(period: $period);
        }

        return $this->buildTimeBucketSeries(metric: $metric, period: $period);
    }//end getTrends()

    /**
     * Build a time-bucketed series for a leads-based metric.
     *
     * @param string $metric Either `leads` or `pipeline-value`.
     * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
     *
     * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
     */
    private function buildTimeBucketSeries(string $metric, string $period): array
    {
        $leads  = $this->findObjects(schemaKey: 'lead_schema');
        $days   = self::PERIOD_DAYS[$period];
        $now    = new DateTimeImmutable();
        $start  = $now->modify(sprintf('-%d days', $days));
        $startTs = $start->getTimestamp();
        $nowTs   = $now->getTimestamp();

        // Bucket granularity: day for week/month, week for quarter, month for year.
        $bucketSize = match ($period) {
            'week', 'month' => 86400,            // 1 day.
            'quarter'       => 86400 * 7,        // 1 week.
            'year'          => 86400 * 30,       // ~1 month.
            default         => 86400,
        };

        $buckets = [];
        foreach ($leads as $lead) {
            $ts = $this->extractTimestamp($lead, ['createdAt', 'created', 'expectedCloseDate']);
            if ($ts === null || $ts < $startTs || $ts > $nowTs) {
                continue;
            }
            $bucketTs = $startTs + ((int) floor(($ts - $startTs) / $bucketSize)) * $bucketSize;
            $key      = date('Y-m-d', $bucketTs);
            if (isset($buckets[$key]) === false) {
                $buckets[$key] = 0;
            }
            if ($metric === 'leads') {
                $buckets[$key] += 1;
            } else {
                $buckets[$key] += (float) ($lead['value'] ?? 0);
            }
        }

        ksort($buckets);
        $series = [];
        foreach ($buckets as $date => $value) {
            $series[] = ['date' => $date, 'value' => $metric === 'leads' ? (int) $value : round((float) $value, 2)];
        }

        return ['metric' => $metric, 'period' => $period, 'series' => $series];
    }//end buildTimeBucketSeries()

    /**
     * Build the requests-by-category series. Categories with zero requests in
     * the period are excluded.
     *
     * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
     *
     * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
     */
    private function buildCategorySeries(string $period): array
    {
        $requests = $this->findObjects(schemaKey: 'request_schema');
        $days     = self::PERIOD_DAYS[$period];
        $now      = new DateTimeImmutable();
        $startTs  = $now->modify(sprintf('-%d days', $days))->getTimestamp();
        $nowTs    = $now->getTimestamp();

        $counts = [];
        foreach ($requests as $request) {
            $ts = $this->extractTimestamp($request, ['requestedAt', 'createdAt']);
            if ($ts === null || $ts < $startTs || $ts > $nowTs) {
                continue;
            }
            $category = (string) ($request['category'] ?? '');
            if ($category === '') {
                continue;
            }
            if (isset($counts[$category]) === false) {
                $counts[$category] = 0;
            }
            $counts[$category]++;
        }

        // Sort by descending count for stable rendering.
        arsort($counts);

        $series = [];
        foreach ($counts as $category => $count) {
            if ($count <= 0) {
                continue;
            }
            $series[] = ['date' => $category, 'value' => $count];
        }

        return ['metric' => 'requests-by-category', 'period' => $period, 'series' => $series];
    }//end buildCategorySeries()

    /**
     * Lead-to-close and request-to-resolved funnel data.
     *
     * Returns:
     *   - leadFunnel:    { open, won, lost, conversionRate }
     *   - requestFunnel: { new, in_progress, completed, rejected, resolutionRate }
     *
     * @return array{
     *   leadFunnel: array<string, int|float|null>,
     *   requestFunnel: array<string, int|float|null>
     * }
     *
     * @throws RuntimeException When OpenRegister is unreachable.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function getFunnels(): array
    {
        $leads    = $this->findObjects(schemaKey: 'lead_schema');
        $requests = $this->findObjects(schemaKey: 'request_schema');

        $leadCounts = ['open' => 0, 'won' => 0, 'lost' => 0];
        foreach ($leads as $lead) {
            $status = (string) ($lead['status'] ?? '');
            if (isset($leadCounts[$status]) === true) {
                $leadCounts[$status]++;
            }
        }
        $leadTotal           = $leadCounts['open'] + $leadCounts['won'] + $leadCounts['lost'];
        $leadConversionRate  = $leadTotal === 0
            ? null
            : round(($leadCounts['won'] * 100.0) / $leadTotal, 1);

        $requestCounts = ['new' => 0, 'in_progress' => 0, 'completed' => 0, 'rejected' => 0];
        foreach ($requests as $request) {
            $status = (string) ($request['status'] ?? '');
            if (isset($requestCounts[$status]) === true) {
                $requestCounts[$status]++;
            }
        }
        $requestTotal     = array_sum($requestCounts);
        $resolutionRate   = $requestTotal === 0
            ? null
            : round(($requestCounts['completed'] * 100.0) / $requestTotal, 1);

        return [
            'leadFunnel'    => array_merge($leadCounts, ['conversionRate' => $leadConversionRate]),
            'requestFunnel' => array_merge($requestCounts, ['resolutionRate' => $resolutionRate]),
        ];
    }//end getFunnels()

    /**
     * Extract a unix timestamp from one of several candidate fields.
     *
     * @param array<string, mixed> $row    Object row.
     * @param array<int, string>   $fields Candidate field names, first non-empty wins.
     *
     * @return int|null Unix timestamp or null when no field parses.
     */
    private function extractTimestamp(array $row, array $fields): ?int
    {
        foreach ($fields as $field) {
            if (isset($row[$field]) === false) {
                continue;
            }
            $value = (string) $row[$field];
            if ($value === '') {
                continue;
            }
            $ts = strtotime($value);
            if ($ts !== false) {
                return $ts;
            }
        }
        return null;
    }//end extractTimestamp()
}//end class
