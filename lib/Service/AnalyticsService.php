<?php

/**
 * Pipelinq AnalyticsService.
 *
 * Cross-module analytics aggregation for the unified dashboard panel.
 *
 * Aggregates KPIs and trend series across existing OpenRegister entities
 * (lead, request, contactmoment, surveyResponse) without introducing any
 * new schemas. All object access goes through the OpenRegister ObjectService
 * with RBAC + multitenancy enabled (the service defaults), so a user only
 * ever aggregates objects they are authorised to read — no cross-tenant
 * leakage. The pure aggregation maths is split into array-in helper methods
 * so it is unit-testable without a live OpenRegister instance.
 *
 * Deduplication (tasks.md#task-0.1): this service contains ONLY the
 * cross-module aggregation maths. It reuses OpenRegister `ObjectService`
 * for data access; no custom chart component, dashboard layout, export
 * controller, or LLM wrapper is introduced here.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/dashboard/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service that aggregates cross-module KPIs and trends for the dashboard.
 *
 * The class is a deliberately cohesive aggregation surface: every method is a
 * small, pure, independently-testable calculation over one entity collection.
 * Splitting it across files would scatter tightly-related maths and obscure the
 * single responsibility (dashboard aggregation), so the overall class
 * complexity is accepted by design.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/dashboard/tasks.md#task-2.2
 */
class AnalyticsService
{
    /**
     * The supported trend metrics.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_METRICS = ['leads', 'requests-by-category'];

    /**
     * The supported reporting periods.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_PERIODS = ['week', 'month', 'quarter', 'year'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (lazy OR lookup).
     * @param IAppConfig         $appConfig The app config (schema id lookup).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the cross-module KPI overview for a period.
     *
     * Returns the current-period KPIs plus the equivalent figures for the
     * immediately preceding period so the frontend can render trend arrows.
     *
     * @param string $period One of week|month|quarter|year.
     *
     * @return array<string, mixed> The overview payload.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function getOverview(string $period): array
    {
        $period = $this->normalisePeriod(period: $period);

        [$currentFrom, $currentTo]   = $this->periodBounds(period: $period, offset: 0);
        [$previousFrom, $previousTo] = $this->periodBounds(period: $period, offset: 1);

        $leads           = $this->fetch(schemaKey: 'lead_schema');
        $requests        = $this->fetch(schemaKey: 'request_schema');
        $contactMoments  = $this->fetch(schemaKey: 'contactmoment_schema');
        $surveyResponses = $this->fetchSurveyResponses();

        $current  = $this->computeKpis(
            leads: $leads,
            requests: $requests,
            contactMoments: $contactMoments,
            surveyResponses: $surveyResponses,
            from: $currentFrom,
            to: $currentTo
        );
        $previous = $this->computeKpis(
            leads: $leads,
            requests: $requests,
            contactMoments: $contactMoments,
            surveyResponses: $surveyResponses,
            from: $previousFrom,
            to: $previousTo
        );

        return [
            'period'         => $period,
            'previousPeriod' => $previous,
        ] + $current;
    }//end getOverview()

    /**
     * Compute the four cross-module KPIs for a time window.
     *
     * Pure aggregation maths — accepts plain arrays so it can be unit-tested
     * without OpenRegister. Each object is expected to be an associative array.
     *
     * @param array<int, array<string, mixed>> $leads           Lead objects.
     * @param array<int, array<string, mixed>> $requests        Request objects.
     * @param array<int, array<string, mixed>> $contactMoments  Contact moment objects.
     * @param array<int, array<string, mixed>> $surveyResponses Survey response objects.
     * @param int                              $from            Window start (unix ts).
     * @param int                              $to              Window end (unix ts).
     *
     * @return array{leadConversionRate: float, avgRequestResolutionTime: ?float, contactMomentVolume: int, customerSatisfactionScore: ?float}
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function computeKpis(
        array $leads,
        array $requests,
        array $contactMoments,
        array $surveyResponses,
        int $from,
        int $to,
    ): array {
        return [
            'leadConversionRate'        => $this->leadConversionRate(leads: $leads, from: $from, to: $to),
            'avgRequestResolutionTime'  => $this->avgRequestResolutionTime(requests: $requests, from: $from, to: $to),
            'contactMomentVolume'       => $this->contactMomentVolume(contactMoments: $contactMoments, from: $from, to: $to),
            'customerSatisfactionScore' => $this->customerSatisfactionScore(responses: $surveyResponses, from: $from, to: $to),
        ];
    }//end computeKpis()

    /**
     * Compute the lead conversion rate (won / total) as a percentage.
     *
     * @param array<int, array<string, mixed>> $leads Lead objects.
     * @param int                              $from  Window start (unix ts).
     * @param int                              $to    Window end (unix ts).
     *
     * @return float Conversion rate (0-100), one decimal.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function leadConversionRate(array $leads, int $from, int $to): float
    {
        $total = 0;
        $won   = 0;
        foreach ($leads as $lead) {
            if ($this->inWindow(object: $lead, field: 'expectedCloseDate', from: $from, to: $to) === false
                && $this->inWindow(object: $lead, field: 'created', from: $from, to: $to) === false
            ) {
                continue;
            }

            $total++;
            if (($lead['status'] ?? '') === 'won') {
                $won++;
            }
        }

        if ($total === 0) {
            return 0.0;
        }

        return round(($won / $total) * 100, 1);
    }//end leadConversionRate()

    /**
     * Compute the average request resolution time in hours.
     *
     * Measures the mean elapsed time between `requestedAt` and `completedAt`
     * for requests that completed inside the window. Returns null when no
     * resolved requests fall inside the window.
     *
     * @param array<int, array<string, mixed>> $requests Request objects.
     * @param int                              $from     Window start (unix ts).
     * @param int                              $to       Window end (unix ts).
     *
     * @return float|null Mean resolution hours, or null when no data.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function avgRequestResolutionTime(array $requests, int $from, int $to): ?float
    {
        $durations = [];
        foreach ($requests as $request) {
            $completedAt = $this->timestamp(value: ($request['completedAt'] ?? null));
            $requestedAt = $this->timestamp(value: ($request['requestedAt'] ?? null));
            if ($completedAt === null || $requestedAt === null) {
                continue;
            }

            if ($completedAt < $from || $completedAt > $to) {
                continue;
            }

            $elapsed = ($completedAt - $requestedAt);
            if ($elapsed < 0) {
                continue;
            }

            $durations[] = ($elapsed / 3600);
        }

        if (count($durations) === 0) {
            return null;
        }

        return round((array_sum($durations) / count($durations)), 1);
    }//end avgRequestResolutionTime()

    /**
     * Count contact moments inside the window.
     *
     * @param array<int, array<string, mixed>> $contactMoments Contact moment objects.
     * @param int                              $from           Window start (unix ts).
     * @param int                              $to             Window end (unix ts).
     *
     * @return int The contact moment volume.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function contactMomentVolume(array $contactMoments, int $from, int $to): int
    {
        $count = 0;
        foreach ($contactMoments as $moment) {
            if ($this->inWindow(object: $moment, field: 'date', from: $from, to: $to) === true
                || $this->inWindow(object: $moment, field: 'created', from: $from, to: $to) === true
            ) {
                $count++;
            }
        }

        return $count;
    }//end contactMomentVolume()

    /**
     * Compute the mean customer satisfaction score (1-5) in the window.
     *
     * Returns null when no survey responses with a numeric score fall inside
     * the window, so the frontend can render "N/A" rather than a misleading 0.
     *
     * @param array<int, array<string, mixed>> $responses Survey response objects.
     * @param int                              $from      Window start (unix ts).
     * @param int                              $to        Window end (unix ts).
     *
     * @return float|null The mean score (one decimal), or null when no data.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function customerSatisfactionScore(array $responses, int $from, int $to): ?float
    {
        $scores = [];
        foreach ($responses as $response) {
            $score = $this->extractScore(response: $response);
            if ($score === null) {
                continue;
            }

            if ($this->inWindow(object: $response, field: 'submittedAt', from: $from, to: $to) === false
                && $this->inWindow(object: $response, field: 'created', from: $from, to: $to) === false
            ) {
                continue;
            }

            $scores[] = $score;
        }

        if (count($scores) === 0) {
            return null;
        }

        return round((array_sum($scores) / count($scores)), 1);
    }//end customerSatisfactionScore()

    /**
     * Get a time-series for a chartable metric.
     *
     * @param string $metric One of the SUPPORTED_METRICS.
     * @param string $period One of week|month|quarter|year.
     *
     * @return array{metric: string, period: string, series: array<int, array{date: string, value: float}>}
     *
     * @throws InvalidArgumentException When the metric is not supported.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function getTrends(string $metric, string $period): array
    {
        if (in_array($metric, self::SUPPORTED_METRICS, true) === false) {
            throw new InvalidArgumentException('Unsupported metric');
        }

        $period      = $this->normalisePeriod(period: $period);
        [$from, $to] = $this->periodBounds(period: $period, offset: 0);

        $series = $this->requestsByCategorySeries(
            requests: $this->fetch(schemaKey: 'request_schema'),
            from: $from,
            to: $to
        );
        if ($metric === 'leads') {
            $series = $this->leadTrendSeries(
                leads: $this->fetch(schemaKey: 'lead_schema'),
                period: $period,
                from: $from,
                to: $to
            );
        }

        return [
            'metric' => $metric,
            'period' => $period,
            'series' => $series,
        ];
    }//end getTrends()

    /**
     * Build a date-bucketed lead-count series for a window.
     *
     * Pure helper — bucket granularity follows the period (day for
     * week/month, week for quarter, month for year).
     *
     * @param array<int, array<string, mixed>> $leads  Lead objects.
     * @param string                           $period The period name.
     * @param int                              $from   Window start (unix ts).
     * @param int                              $to     Window end (unix ts).
     *
     * @return array<int, array{date: string, value: float}> Sorted series.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function leadTrendSeries(array $leads, string $period, int $from, int $to): array
    {
        $format  = $this->bucketFormat(period: $period);
        $buckets = [];
        foreach ($leads as $lead) {
            $stamp = $this->timestamp(value: ($lead['expectedCloseDate'] ?? ($lead['created'] ?? null)));
            if ($stamp === null || $stamp < $from || $stamp > $to) {
                continue;
            }

            $key           = gmdate($format, $stamp);
            $buckets[$key] = (($buckets[$key] ?? 0) + 1);
        }

        ksort($buckets);
        $series = [];
        foreach ($buckets as $date => $value) {
            $series[] = ['date' => $date, 'value' => (float) $value];
        }

        return $series;
    }//end leadTrendSeries()

    /**
     * Build a requests-by-category series for a window.
     *
     * Categories with zero requests in the window are excluded entirely.
     *
     * @param array<int, array<string, mixed>> $requests Request objects.
     * @param int                              $from     Window start (unix ts).
     * @param int                              $to       Window end (unix ts).
     *
     * @return array<int, array{date: string, value: float}> Category buckets.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function requestsByCategorySeries(array $requests, int $from, int $to): array
    {
        $buckets = [];
        foreach ($requests as $request) {
            if ($this->inWindow(object: $request, field: 'requestedAt', from: $from, to: $to) === false
                && $this->inWindow(object: $request, field: 'created', from: $from, to: $to) === false
            ) {
                continue;
            }

            $category = (string) ($request['category'] ?? '');
            if ($category === '') {
                $category = 'overig';
            }

            $buckets[$category] = (($buckets[$category] ?? 0) + 1);
        }

        // Categories only ever appear in $buckets when at least one request
        // incremented them, so every entry already has a positive count.
        ksort($buckets);
        $series = [];
        foreach ($buckets as $category => $value) {
            $series[] = ['date' => $category, 'value' => (float) $value];
        }

        return $series;
    }//end requestsByCategorySeries()

    /**
     * Compute the lead-to-close and request-to-resolved funnels.
     *
     * @return array{leadFunnel: array<string, int>, requestFunnel: array<string, int>}
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function getFunnels(): array
    {
        return [
            'leadFunnel'    => $this->buildLeadFunnel(leads: $this->fetch(schemaKey: 'lead_schema')),
            'requestFunnel' => $this->buildRequestFunnel(requests: $this->fetch(schemaKey: 'request_schema')),
        ];
    }//end getFunnels()

    /**
     * Build the lead funnel stage counts (total -> open -> won).
     *
     * @param array<int, array<string, mixed>> $leads Lead objects.
     *
     * @return array{total: int, open: int, won: int, lost: int}
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function buildLeadFunnel(array $leads): array
    {
        $funnel = ['total' => 0, 'open' => 0, 'won' => 0, 'lost' => 0];
        foreach ($leads as $lead) {
            $funnel['total']++;
            $status = (string) ($lead['status'] ?? 'open');
            $bucket = 'open';
            if ($status === 'won') {
                $bucket = 'won';
            } else if ($status === 'lost') {
                $bucket = 'lost';
            }

            $funnel[$bucket]++;
        }

        return $funnel;
    }//end buildLeadFunnel()

    /**
     * Build the request funnel stage counts (total -> in progress -> resolved).
     *
     * @param array<int, array<string, mixed>> $requests Request objects.
     *
     * @return array{total: int, inProgress: int, resolved: int}
     *
     * @spec openspec/changes/dashboard/tasks.md#task-2.2
     */
    public function buildRequestFunnel(array $requests): array
    {
        $funnel = ['total' => 0, 'inProgress' => 0, 'resolved' => 0];
        foreach ($requests as $request) {
            $funnel['total']++;
            $status = (string) ($request['status'] ?? 'new');
            if ($status === 'in_progress') {
                $funnel['inProgress']++;
            } else if (in_array($status, ['completed', 'converted'], true) === true) {
                $funnel['resolved']++;
            }
        }

        return $funnel;
    }//end buildRequestFunnel()

    /**
     * Normalise an arbitrary period string to a supported value.
     *
     * @param string $period The requested period.
     *
     * @return string A supported period (defaults to 'month').
     */
    private function normalisePeriod(string $period): string
    {
        if (in_array($period, self::SUPPORTED_PERIODS, true) === true) {
            return $period;
        }

        return 'month';
    }//end normalisePeriod()

    /**
     * Compute the [from, to] unix-timestamp bounds for a period.
     *
     * `offset` shifts the window backwards by whole periods (0 = current,
     * 1 = the immediately preceding equal period).
     *
     * @param string $period The period name.
     * @param int    $offset Periods to shift backwards.
     *
     * @return array{0: int, 1: int} The [from, to] bounds.
     */
    private function periodBounds(string $period, int $offset): array
    {
        $now = $this->now();
        $to  = $now;

        $spec = match ($period) {
            'week'    => '7 days',
            'quarter' => '90 days',
            'year'    => '365 days',
            default   => '30 days',
        };

        $length = (strtotime('+'.$spec, 0) - 0);
        $to     = ($now - ($offset * $length));
        $from   = ($to - $length);

        return [$from, $to];
    }//end periodBounds()

    /**
     * The bucket date format for a period (gmdate format string).
     *
     * @param string $period The period name.
     *
     * @return string A gmdate() format string.
     */
    private function bucketFormat(string $period): string
    {
        return match ($period) {
            'year'    => 'Y-m',
            'quarter' => 'o-\WW',
            default   => 'Y-m-d',
        };
    }//end bucketFormat()

    /**
     * Whether an object's date field falls inside a window.
     *
     * @param array<string, mixed> $object The object.
     * @param string               $field  The date field name.
     * @param int                  $from   Window start (unix ts).
     * @param int                  $to     Window end (unix ts).
     *
     * @return bool True when the field parses and is inside [from, to].
     */
    private function inWindow(array $object, string $field, int $from, int $to): bool
    {
        $stamp = $this->timestamp(value: ($object[$field] ?? null));
        if ($stamp === null) {
            return false;
        }

        return ($stamp >= $from && $stamp <= $to);
    }//end inWindow()

    /**
     * Parse a date value into a unix timestamp.
     *
     * @param mixed $value The raw date value (string or numeric).
     *
     * @return int|null The timestamp, or null when unparseable.
     */
    private function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) === true) {
            return (int) $value;
        }

        if (is_string($value) === false) {
            return null;
        }

        $stamp = strtotime($value);
        if ($stamp === false) {
            return null;
        }

        return $stamp;
    }//end timestamp()

    /**
     * Extract a numeric 1-5 satisfaction score from a survey response.
     *
     * Looks at a `score` field first, then a nested `answers.satisfaction`
     * style field. Non-numeric or out-of-range values are ignored.
     *
     * @param array<string, mixed> $response The survey response object.
     *
     * @return float|null The score, or null when absent/invalid.
     */
    private function extractScore(array $response): ?float
    {
        $raw = ($response['score'] ?? ($response['satisfaction'] ?? null));
        if (is_array($raw) === true) {
            return null;
        }

        if ($raw === null || is_numeric($raw) === false) {
            return null;
        }

        $score = (float) $raw;
        if ($score < 1 || $score > 5) {
            return null;
        }

        return $score;
    }//end extractScore()

    /**
     * Fetch all objects for a configured schema in this app's register.
     *
     * Uses OpenRegister ObjectService::findAll with RBAC + multitenancy
     * enabled (the defaults), so results are scoped to the current user's
     * access. Returns an empty array when the register/schema is not
     * configured or OpenRegister is unavailable (graceful no-op).
     *
     * @param string $schemaKey The app-config key holding the schema id.
     *
     * @return array<int, array<string, mixed>> The objects as plain arrays.
     */
    private function fetch(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                    'limit'   => 1000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[AnalyticsService] Failed to fetch objects for analytics',
                ['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
            );
            return [];
        }

        return $this->normaliseResults(results: $results);
    }//end fetch()

    /**
     * Fetch survey responses (config key may be absent in older installs).
     *
     * @return array<int, array<string, mixed>> The survey response objects.
     */
    private function fetchSurveyResponses(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'surveyresponse_schema', '');
        if ($schema === '') {
            $schema = $this->appConfig->getValueString(Application::APP_ID, 'survey_response_schema', '');
        }

        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                    'limit'   => 1000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[AnalyticsService] Failed to fetch survey responses',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        return $this->normaliseResults(results: $results);
    }//end fetchSurveyResponses()

    /**
     * Normalise an OpenRegister result set into plain associative arrays.
     *
     * @param mixed $results The raw findAll result.
     *
     * @return array<int, array<string, mixed>> The objects as arrays.
     */
    private function normaliseResults(mixed $results): array
    {
        $objects = [];
        foreach (($results ?? []) as $result) {
            if (is_array($result) === true) {
                $objects[] = $result;
                continue;
            }

            if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
                $serialized = $result->jsonSerialize();
                if (is_array($serialized) === true) {
                    $objects[] = $serialized;
                    continue;
                }
            }

            if (is_object($result) === true && method_exists($result, 'getObject') === true) {
                $data = $result->getObject();
                if (is_array($data) === true) {
                    $objects[] = $data;
                    continue;
                }
            }

            $objects[] = (array) $result;
        }//end foreach

        return $objects;
    }//end normaliseResults()

    /**
     * Current unix timestamp (override point for tests).
     *
     * @return int The current timestamp.
     */
    protected function now(): int
    {
        return time();
    }//end now()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException When OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()
}//end class
