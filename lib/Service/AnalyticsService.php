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
     * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
     */
    public const ALLOWED_TREND_METRICS = [
        'leads',
        'requests-by-category',
        'pipeline-value',
        'revenue',
        'pipeline-by-stage',
        'revenue-by-product-category',
        'top-customers',
    ];

    /**
     * POS transaction statuses that count as realised revenue.
     *
     * @var array<int, string>
     *
     * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
     */
    private const REVENUE_POS_STATUSES = ['settled', 'confirmed'];

    /**
     * How many customers the top-customers breakdown returns.
     *
     * @var int
     *
     * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
     */
    private const TOP_CUSTOMERS_LIMIT = 8;

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

        $leads    = $this->findObjects(schemaKey: 'lead_schema');
        $requests = $this->findObjects(schemaKey: 'request_schema');
        $cms      = $this->findObjects(schemaKey: 'contactmoment_schema');
        // Survey responses migrated to the OpenRegister forms leaf (NC Forms).
        // CSAT will be re-sourced from form-link responses once the leaf
        // exposes a query helper; see openspec/changes/migrate-forms-to-forms-leaf.
        $responses = [];

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
     * @param DateTimeInterface                $from      Inclusive lower bound.
     * @param DateTimeInterface                $to        Exclusive upper bound.
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
            $ts = $this->extractTimestamp(row: $lead, fields: ['createdAt', 'created', 'expectedCloseDate']);
            if ($ts === null || $ts < $fromTs || $ts >= $toTs) {
                continue;
            }

            $totalLeads++;
            if (((string) ($lead['status'] ?? '')) === 'won') {
                $wonLeads++;
            }
        }

        $leadConversionRate = null;
        if ($totalLeads > 0) {
            $leadConversionRate = round(($wonLeads * 100.0) / $totalLeads, 1);
        }

        // Avg request resolution time.
        $resolutionHours = 0.0;
        $resolvedCount   = 0;
        foreach ($requests as $request) {
            $requestedTs = $this->extractTimestamp(row: $request, fields: ['requestedAt']);
            $completedTs = $this->extractTimestamp(row: $request, fields: ['completedAt']);
            if ($requestedTs === null || $completedTs === null) {
                continue;
            }

            if ($completedTs < $fromTs || $completedTs >= $toTs) {
                continue;
            }

            $resolutionHours += max(0, ($completedTs - $requestedTs) / 3600.0);
            $resolvedCount++;
        }

        $avgRequestResolutionTime = null;
        if ($resolvedCount > 0) {
            $avgRequestResolutionTime = round($resolutionHours / $resolvedCount, 2);
        }

        // Contact moment volume.
        $cmCount = 0;
        foreach ($cms as $cm) {
            $ts = $this->extractTimestamp(row: $cm, fields: ['contactedAt', 'createdAt']);
            if ($ts === null || $ts < $fromTs || $ts >= $toTs) {
                continue;
            }

            $cmCount++;
        }

        // Customer satisfaction score (mean of surveyResponse.score).
        $scoreSum = 0.0;
        $scoreN   = 0;
        foreach ($responses as $r) {
            $ts = $this->extractTimestamp(row: $r, fields: ['submittedAt', 'createdAt']);
            if ($ts === null || $ts < $fromTs || $ts >= $toTs) {
                continue;
            }

            if (isset($r['score']) === false || is_numeric($r['score']) === false) {
                continue;
            }

            $scoreSum += (float) $r['score'];
            $scoreN++;
        }

        $customerSatisfactionScore = null;
        if ($scoreN > 0) {
            $customerSatisfactionScore = round($scoreSum / $scoreN, 2);
        }

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

        return match ($metric) {
            'requests-by-category'        => $this->buildCategorySeries(period: $period),
            'revenue'                     => $this->buildRevenueSeries(period: $period),
            'pipeline-by-stage'           => $this->buildPipelineByStageSeries(period: $period),
            'revenue-by-product-category' => $this->buildRevenueByCategorySeries(period: $period),
            'top-customers'               => $this->buildTopCustomersSeries(period: $period),
            default                       => $this->buildTimeBucketSeries(metric: $metric, period: $period),
        };
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
        $leads   = $this->findObjects(schemaKey: 'lead_schema');
        $days    = self::PERIOD_DAYS[$period];
        $now     = new DateTimeImmutable();
        $start   = $now->modify(sprintf('-%d days', $days));
        $startTs = $start->getTimestamp();
        $nowTs   = $now->getTimestamp();

        // Bucket granularity: day for week/month, week for quarter, month for year.
        $bucketSize = match ($period) {
            'week', 'month' => 86400,
            // 1 day.
            'quarter'       => 86400 * 7,
            // 1 week.
            'year'          => 86400 * 30,
            // ~1 month.
            default         => 86400,
        };

        $buckets = [];
        foreach ($leads as $lead) {
            $ts = $this->extractTimestamp(row: $lead, fields: ['createdAt', 'created', 'expectedCloseDate']);
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
            $bucketValue = round((float) $value, 2);
            if ($metric === 'leads') {
                $bucketValue = (int) $value;
            }

            $series[] = ['date' => $date, 'value' => $bucketValue];
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
            $ts = $this->extractTimestamp(row: $request, fields: ['requestedAt', 'createdAt']);
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

        $leadTotal          = $leadCounts['open'] + $leadCounts['won'] + $leadCounts['lost'];
        $leadConversionRate = null;
        if ($leadTotal > 0) {
            $leadConversionRate = round(($leadCounts['won'] * 100.0) / $leadTotal, 1);
        }

        $requestCounts = ['new' => 0, 'in_progress' => 0, 'completed' => 0, 'rejected' => 0];
        foreach ($requests as $request) {
            $status = (string) ($request['status'] ?? '');
            if (isset($requestCounts[$status]) === true) {
                $requestCounts[$status]++;
            }
        }

        $requestTotal   = array_sum($requestCounts);
        $resolutionRate = null;
        if ($requestTotal > 0) {
            $resolutionRate = round(($requestCounts['completed'] * 100.0) / $requestTotal, 1);
        }

        return [
            'leadFunnel'    => array_merge($leadCounts, ['conversionRate' => $leadConversionRate]),
            'requestFunnel' => array_merge($requestCounts, ['resolutionRate' => $resolutionRate]),
        ];
    }//end getFunnels()

    /**
     * Commercial KPI overview for the Commercial dashboard.
     *
     * Computes, for the trailing window of $period:
     *   - revenue:           settled-POS turnover + won-deal value closed in window
     *   - wonValue:          sum of won-lead value closed in window
     *   - winRate:           won / (won + lost) leads closed in window (0-100, null if none)
     *   - avgDealSize:       mean won-lead value in window (null if none)
     *   - weightedForecast:  sum over OPEN leads of value * probability/100 (forward-looking)
     *   - openPipelineValue: sum of value over OPEN leads (forward-looking)
     *   - previousPeriod:    windowed figures for the preceding equal-length window
     *
     * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
     *
     * @return array{
     *   revenue: float,
     *   wonValue: float,
     *   winRate: float|null,
     *   avgDealSize: float|null,
     *   weightedForecast: float,
     *   openPipelineValue: float,
     *   period: string,
     *   previousPeriod: array<string, float|null>
     * }
     *
     * @throws InvalidArgumentException When the period is not recognised.
     * @throws RuntimeException         When OpenRegister is unreachable.
     *
     * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
     */
    public function getCommercialOverview(string $period=self::DEFAULT_PERIOD): array
    {
        if (in_array($period, self::ALLOWED_OVERVIEW_PERIODS, true) === false) {
            throw new InvalidArgumentException(message: 'Invalid period');
        }

        $days = self::PERIOD_DAYS[$period];
        $now  = new DateTimeImmutable();

        $currentStart  = $now->modify(sprintf('-%d days', $days));
        $previousStart = $currentStart->modify(sprintf('-%d days', $days));

        $leads = $this->findObjects(schemaKey: 'lead_schema');
        $pos   = $this->findObjects(schemaKey: 'posTransaction_schema');

        $current  = $this->aggregateCommercialWindow(from: $currentStart, to: $now, leads: $leads, pos: $pos);
        $previous = $this->aggregateCommercialWindow(from: $previousStart, to: $currentStart, leads: $leads, pos: $pos);

        $forecast = $this->aggregateOpenPipeline(leads: $leads);

        return [
            'revenue'           => $current['revenue'],
            'wonValue'          => $current['wonValue'],
            'winRate'           => $current['winRate'],
            'avgDealSize'       => $current['avgDealSize'],
            'weightedForecast'  => $forecast['weightedForecast'],
            'openPipelineValue' => $forecast['openPipelineValue'],
            'period'            => $period,
            'previousPeriod'    => $previous,
        ];
    }//end getCommercialOverview()

    /**
     * Aggregate the windowed commercial figures for one time window.
     *
     * @param DateTimeInterface                $from  Inclusive lower bound.
     * @param DateTimeInterface                $to    Exclusive upper bound.
     * @param array<int, array<string, mixed>> $leads Lead rows.
     * @param array<int, array<string, mixed>> $pos   POS transaction rows.
     *
     * @return array{revenue: float, wonValue: float, winRate: float|null, avgDealSize: float|null}
     *
     * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
     */
    private function aggregateCommercialWindow(
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $leads,
        array $pos,
    ): array {
        $fromTs = $from->getTimestamp();
        $toTs   = $to->getTimestamp();

        $posRevenue = 0.0;
        foreach ($pos as $txn) {
            $ts = $this->posRevenueTimestamp(txn: $txn);
            if ($this->isRevenueTransaction(txn: $txn) === false || $ts === null || $ts < $fromTs || $ts >= $toTs) {
                continue;
            }

            $posRevenue += (float) ($txn['total'] ?? 0);
        }

        $wonValue  = 0.0;
        $wonCount  = 0;
        $lostCount = 0;
        foreach ($leads as $lead) {
            $status = (string) ($lead['status'] ?? '');
            if ($status !== 'won' && $status !== 'lost') {
                continue;
            }

            $ts = $this->leadCloseTimestamp(lead: $lead);
            if ($ts === null || $ts < $fromTs || $ts >= $toTs) {
                continue;
            }

            if ($status === 'won') {
                $wonValue += (float) ($lead['value'] ?? 0);
                $wonCount++;
            } else {
                $lostCount++;
            }
        }

        $closed = ($wonCount + $lostCount);

        $winRate = null;
        if ($closed > 0) {
            $winRate = round(($wonCount * 100.0) / $closed, 1);
        }

        $avgDealSize = null;
        if ($wonCount > 0) {
            $avgDealSize = round($wonValue / $wonCount, 2);
        }

        return [
            'revenue'     => round(($posRevenue + $wonValue), 2),
            'wonValue'    => round($wonValue, 2),
            'winRate'     => $winRate,
            'avgDealSize' => $avgDealSize,
        ];
    }//end aggregateCommercialWindow()

    /**
     * Forward-looking open-pipeline figures (current state, not windowed).
     *
     * @param array<int, array<string, mixed>> $leads Lead rows.
     *
     * @return array{weightedForecast: float, openPipelineValue: float}
     *
     * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
     */
    private function aggregateOpenPipeline(array $leads): array
    {
        $weightedForecast  = 0.0;
        $openPipelineValue = 0.0;
        foreach ($leads as $lead) {
            if (((string) ($lead['status'] ?? '')) !== 'open') {
                continue;
            }

            $value       = (float) ($lead['value'] ?? 0);
            $probability = (float) ($lead['probability'] ?? 0);
            $openPipelineValue += $value;
            $weightedForecast  += ($value * ($probability / 100.0));
        }

        return [
            'weightedForecast'  => round($weightedForecast, 2),
            'openPipelineValue' => round($openPipelineValue, 2),
        ];
    }//end aggregateOpenPipeline()

    /**
     * Time-bucketed revenue series: settled-POS turnover + won-deal value.
     *
     * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
     *
     * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
     */
    private function buildRevenueSeries(string $period): array
    {
        $pos   = $this->findObjects(schemaKey: 'posTransaction_schema');
        $leads = $this->findObjects(schemaKey: 'lead_schema');

        $days       = self::PERIOD_DAYS[$period];
        $now        = new DateTimeImmutable();
        $startTs    = $now->modify(sprintf('-%d days', $days))->getTimestamp();
        $nowTs      = $now->getTimestamp();
        $bucketSize = $this->bucketSizeForPeriod(period: $period);

        $buckets = [];
        foreach ($pos as $txn) {
            if ($this->isRevenueTransaction(txn: $txn) === false) {
                continue;
            }

            $key = $this->bucketKey(ts: $this->posRevenueTimestamp(txn: $txn), startTs: $startTs, nowTs: $nowTs, bucketSize: $bucketSize);
            if ($key !== null) {
                $buckets[$key] = (($buckets[$key] ?? 0.0) + (float) ($txn['total'] ?? 0));
            }
        }

        foreach ($leads as $lead) {
            if (((string) ($lead['status'] ?? '')) !== 'won') {
                continue;
            }

            $key = $this->bucketKey(ts: $this->leadCloseTimestamp(lead: $lead), startTs: $startTs, nowTs: $nowTs, bucketSize: $bucketSize);
            if ($key !== null) {
                $buckets[$key] = (($buckets[$key] ?? 0.0) + (float) ($lead['value'] ?? 0));
            }
        }

        ksort($buckets);
        $series = [];
        foreach ($buckets as $date => $value) {
            $series[] = ['date' => $date, 'value' => round((float) $value, 2)];
        }

        return ['metric' => 'revenue', 'period' => $period, 'series' => $series];
    }//end buildRevenueSeries()

    /**
     * Open-lead value summed per pipeline stage, ordered by stage order.
     *
     * @param string $period One of ALLOWED_OVERVIEW_PERIODS (echoed only).
     *
     * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
     */
    private function buildPipelineByStageSeries(string $period): array
    {
        $leads = $this->findObjects(schemaKey: 'lead_schema');

        $byStage = [];
        $order   = [];
        foreach ($leads as $lead) {
            if (((string) ($lead['status'] ?? '')) !== 'open') {
                continue;
            }

            $stage = (string) ($lead['stage'] ?? '');
            if ($stage === '') {
                $stage = 'Unassigned';
            }

            $byStage[$stage] = (($byStage[$stage] ?? 0.0) + (float) ($lead['value'] ?? 0));
            if (isset($order[$stage]) === false) {
                $order[$stage] = (int) ($lead['stageOrder'] ?? 999);
            }
        }

        $rows = [];
        foreach ($byStage as $stage => $value) {
            $rows[] = ['date' => (string) $stage, 'value' => round((float) $value, 2), 'order' => $order[$stage]];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                return ($left['order'] <=> $right['order']);
            }
        );

        $series = [];
        foreach ($rows as $row) {
            $series[] = ['date' => $row['date'], 'value' => $row['value']];
        }

        return ['metric' => 'pipeline-by-stage', 'period' => $period, 'series' => $series];
    }//end buildPipelineByStageSeries()

    /**
     * POS line revenue grouped by the linked product's category.
     *
     * Only lines whose parent transaction is a realised-revenue
     * transaction settled within the window contribute. Lines with no
     * resolvable category are summed under an "Other" entry.
     *
     * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
     *
     * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
     */
    private function buildRevenueByCategorySeries(string $period): array
    {
        $days    = self::PERIOD_DAYS[$period];
        $now     = new DateTimeImmutable();
        $startTs = $now->modify(sprintf('-%d days', $days))->getTimestamp();
        $nowTs   = $now->getTimestamp();

        $pos        = $this->findObjects(schemaKey: 'posTransaction_schema');
        $lines      = $this->findObjects(schemaKey: 'posTransactionLine_schema');
        $products   = $this->findObjects(schemaKey: 'product_schema');
        $categories = $this->findObjects(schemaKey: 'productCategory_schema');

        $eligibleTxn = [];
        foreach ($pos as $txn) {
            $ts = $this->posRevenueTimestamp(txn: $txn);
            if ($this->isRevenueTransaction(txn: $txn) === false || $ts === null || $ts < $startTs || $ts > $nowTs) {
                continue;
            }

            $id = $this->objectId(row: $txn);
            if ($id !== '') {
                $eligibleTxn[$id] = true;
            }
        }

        $categoryByProduct = $this->productCategoryIndex(products: $products);
        // Product.category is a UUID reference to a productCategory; resolve
        // it to the display name. Legacy free-text categories (no matching
        // productCategory row) fall through to their own value.
        $categoryNames = $this->categoryNameIndex(categories: $categories);

        $byCategory = [];
        foreach ($lines as $line) {
            $txnId = (string) ($line['transaction'] ?? '');
            if ($txnId === '' || isset($eligibleTxn[$txnId]) === false) {
                continue;
            }

            $categoryRef = ($categoryByProduct[(string) ($line['product'] ?? '')] ?? '');
            $category    = ($categoryNames[$categoryRef] ?? $categoryRef);
            if ($category === '') {
                $category = 'Other';
            }

            $byCategory[$category] = (($byCategory[$category] ?? 0.0) + (float) ($line['lineTotal'] ?? 0));
        }

        arsort($byCategory);
        $series = [];
        foreach ($byCategory as $category => $value) {
            $series[] = ['date' => (string) $category, 'value' => round((float) $value, 2)];
        }

        return ['metric' => 'revenue-by-product-category', 'period' => $period, 'series' => $series];
    }//end buildRevenueByCategorySeries()

    /**
     * Won-deal + POS revenue grouped by client, top N by value.
     *
     * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
     *
     * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
     */
    private function buildTopCustomersSeries(string $period): array
    {
        $days    = self::PERIOD_DAYS[$period];
        $now     = new DateTimeImmutable();
        $startTs = $now->modify(sprintf('-%d days', $days))->getTimestamp();
        $nowTs   = $now->getTimestamp();

        $leads   = $this->findObjects(schemaKey: 'lead_schema');
        $pos     = $this->findObjects(schemaKey: 'posTransaction_schema');
        $clients = $this->findObjects(schemaKey: 'client_schema');

        $nameByClient = $this->clientNameIndex(clients: $clients);

        $byClient = [];
        foreach ($leads as $lead) {
            $ts = $this->leadCloseTimestamp(lead: $lead);
            if (((string) ($lead['status'] ?? '')) !== 'won' || $ts === null || $ts < $startTs || $ts > $nowTs) {
                continue;
            }

            $client            = (string) ($lead['client'] ?? '');
            $byClient[$client] = (($byClient[$client] ?? 0.0) + (float) ($lead['value'] ?? 0));
        }

        foreach ($pos as $txn) {
            $ts = $this->posRevenueTimestamp(txn: $txn);
            if ($this->isRevenueTransaction(txn: $txn) === false || $ts === null || $ts < $startTs || $ts > $nowTs) {
                continue;
            }

            $client            = (string) ($txn['client'] ?? ($txn['customer'] ?? ''));
            $byClient[$client] = (($byClient[$client] ?? 0.0) + (float) ($txn['total'] ?? 0));
        }

        arsort($byClient);
        $series = [];
        $count  = 0;
        foreach ($byClient as $client => $value) {
            if ($count >= self::TOP_CUSTOMERS_LIMIT) {
                break;
            }

            if ((string) $client === '') {
                continue;
            }

            $series[] = ['date' => ($nameByClient[(string) $client] ?? (string) $client), 'value' => round((float) $value, 2)];
            $count++;
        }

        return ['metric' => 'top-customers', 'period' => $period, 'series' => $series];
    }//end buildTopCustomersSeries()

    /**
     * Whether a POS transaction counts as realised revenue.
     *
     * @param array<string, mixed> $txn POS transaction row.
     *
     * @return bool
     */
    private function isRevenueTransaction(array $txn): bool
    {
        return in_array((string) ($txn['status'] ?? ''), self::REVENUE_POS_STATUSES, true);
    }//end isRevenueTransaction()

    /**
     * Timestamp at which a POS transaction realised revenue.
     *
     * @param array<string, mixed> $txn POS transaction row.
     *
     * @return int|null
     */
    private function posRevenueTimestamp(array $txn): ?int
    {
        return $this->extractTimestamp(row: $txn, fields: ['settledAt', 'confirmedAt', 'created', 'createdAt']);
    }//end posRevenueTimestamp()

    /**
     * Timestamp at which a lead closed (won/lost).
     *
     * @param array<string, mixed> $lead Lead row.
     *
     * @return int|null
     */
    private function leadCloseTimestamp(array $lead): ?int
    {
        return $this->extractTimestamp(row: $lead, fields: ['stageEnteredAt', 'expectedCloseDate', 'created', 'createdAt']);
    }//end leadCloseTimestamp()

    /**
     * Bucket key (`Y-m-d`) for a timestamp, or null when out of range.
     *
     * @param int|null $ts         Unix timestamp.
     * @param int      $startTs    Window start.
     * @param int      $nowTs      Window end.
     * @param int      $bucketSize Bucket width in seconds.
     *
     * @return string|null
     */
    private function bucketKey(?int $ts, int $startTs, int $nowTs, int $bucketSize): ?string
    {
        if ($ts === null || $ts < $startTs || $ts > $nowTs) {
            return null;
        }

        $bucketTs = ($startTs + ((int) floor((($ts - $startTs) / $bucketSize)) * $bucketSize));
        return date('Y-m-d', $bucketTs);
    }//end bucketKey()

    /**
     * Bucket width in seconds for a period (day/week/month granularity).
     *
     * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
     *
     * @return int
     */
    private function bucketSizeForPeriod(string $period): int
    {
        return match ($period) {
            'week', 'month' => 86400,
            'quarter'       => (86400 * 7),
            'year'          => (86400 * 30),
            default         => 86400,
        };
    }//end bucketSizeForPeriod()

    /**
     * Extract the canonical object id from a row (`@self.id` or `id`).
     *
     * @param array<string, mixed> $row Object row.
     *
     * @return string
     */
    private function objectId(array $row): string
    {
        if (isset($row['@self']) === true && is_array($row['@self']) === true && isset($row['@self']['id']) === true) {
            return (string) $row['@self']['id'];
        }

        if (isset($row['id']) === true) {
            return (string) $row['id'];
        }

        return '';
    }//end objectId()

    /**
     * Map product object id -> category name.
     *
     * @param array<int, array<string, mixed>> $products Product rows.
     *
     * @return array<string, string>
     */
    private function productCategoryIndex(array $products): array
    {
        $index = [];
        foreach ($products as $product) {
            $id = $this->objectId(row: $product);
            if ($id !== '') {
                $index[$id] = (string) ($product['category'] ?? '');
            }
        }

        return $index;
    }//end productCategoryIndex()

    /**
     * Map client object id -> display name.
     *
     * @param array<int, array<string, mixed>> $clients Client rows.
     *
     * @return array<string, string>
     */
    private function clientNameIndex(array $clients): array
    {
        $index = [];
        foreach ($clients as $client) {
            $id = $this->objectId(row: $client);
            if ($id !== '') {
                $index[$id] = (string) ($client['name'] ?? $id);
            }
        }

        return $index;
    }//end clientNameIndex()

    /**
     * Map productCategory object id -> display name.
     *
     * @param array<int, array<string, mixed>> $categories Product-category rows.
     *
     * @return array<string, string>
     */
    private function categoryNameIndex(array $categories): array
    {
        $index = [];
        foreach ($categories as $category) {
            $id = $this->objectId(row: $category);
            if ($id !== '') {
                $index[$id] = (string) ($category['name'] ?? $id);
            }
        }

        return $index;
    }//end categoryNameIndex()

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
