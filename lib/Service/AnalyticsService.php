<?php

/**
 * Pipelinq AnalyticsService.
 *
 * Server-side aggregation of cross-module KPIs (open pipeline value,
 * open requests, contactmomenten count, active leads) for the
 * Klantbeeld 360 analytics dashboard. Avoids fetching full collections
 * client-side on large installations.
 *
 * Requests and contactmomenten are no longer separate schemas: both are
 * subtypes of the unified `ticket` schema and are read through
 * {@see TicketService} with a `ticketType` discriminator
 * (unify-ticket-supertype).
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
 * @spec openspec/changes/archive/2026-06-14-klantbeeld-360/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Cross-module analytics summary service.
 *
 * Stateless. Reads leads via the OpenRegister ObjectService and requests /
 * contactmomenten via the unified ticket schema ({@see TicketService}),
 * aggregates counts/sums in PHP and returns the four headline KPIs for the
 * Analytics dashboard.
 *
 * @spec openspec/changes/archive/2026-06-14-klantbeeld-360/tasks.md#task-1.1
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Cohesive analytics aggregator; splitting would fragment related KPI logic.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregation over many KPI dimensions; complexity is inherent, not accidental.
 * @SuppressWarnings(PHPMD.TooManyMethods)           One private helper per KPI figure keeps each figure testable on its own.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Reads the stores and the forecast target of every KPI it aggregates.
 */
class AnalyticsService {
	use PayloadToArray;

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
	 * Allowed trend metric identifiers.
	 *
	 * @var array<int, string>
	 *
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.2
	 * @spec openspec/specs/commercial-dashboard/spec.md
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
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	private const REVENUE_POS_STATUSES = ['settled', 'confirmed'];

	/**
	 * How many customers the top-customers breakdown returns.
	 *
	 * @var int
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	private const TOP_CUSTOMERS_LIMIT = 8;

	/**
	 * Trailing-window days per period (used by both summary and overview).
	 *
	 * @var array<string, int>
	 */
	private const PERIOD_DAYS = [
		'week' => 7,
		'month' => 30,
		'quarter' => 90,
		'year' => 365,
	];

	/**
	 * Allowed overview period selectors. Adds `year` on top of `ALLOWED_PERIODS`.
	 *
	 * @var array<int, string>
	 *
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.2
	 */
	public const ALLOWED_OVERVIEW_PERIODS = ['week', 'month', 'quarter', 'year'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App configuration (register/schema IDs).
	 * @param LoggerInterface $logger The logger.
	 * @param TicketService $ticketService Resolver for the unified ticket schema.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly TicketService $ticketService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Query tickets of one subtype via the shared TicketService.
	 *
	 * The legacy `request` / `complaint` / `contactmoment` schemas were merged
	 * into the unified `ticket` schema; a subtype is now selected with the
	 * `ticketType` discriminator rather than with a separate schema id.
	 * TicketService is fail-soft: an unprovisioned schema or an unreachable
	 * OpenRegister yields an empty row set (logged there).
	 *
	 * @param string $ticketType One of the TicketService::TYPE_* constants.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
	 */
	private function findTickets(string $ticketType): array {
		$objects = [];
		foreach ($this->ticketService->findByType(ticketType: $ticketType) as $result) {
			$objects[] = $this->toArray(value: $result);
		}

		return $objects;
	}//end findTickets()

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
	private function findObjects(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[AnalyticsService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		try {
			$objectService = $this->getObjectService();
			$results = $objectService->findAll(
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
			$objects[] = $this->toArray(value: $result);
		}

		return $objects;
	}//end findObjects()

	/**
	 * Resolve the OpenRegister ObjectService via DI container.
	 *
	 * @return object The ObjectService instance.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Cross-module KPI overview for the unified analytics widget.
	 *
	 * Computes:
	 *   - leadConversionRate:        won-leads / total-leads within the period (0-100)
	 *   - avgRequestResolutionTime:  mean hours between the request ticket's occurredAt
	 *                                and its resolvedAt for requests resolved within the
	 *                                period (null if none)
	 *   - contactMomentVolume:       count of contactmoment tickets within the period
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
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.2
	 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-001
	 */
	public function getOverview(string $period = self::DEFAULT_PERIOD): array {
		if (in_array($period, self::ALLOWED_OVERVIEW_PERIODS, true) === false) {
			throw new InvalidArgumentException(message: 'Invalid period');
		}

		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();

		$currentStart = $now->modify(sprintf('-%d days', $days));
		$previousStart = $currentStart->modify(sprintf('-%d days', $days));

		$leads = $this->findObjects(schemaKey: 'lead_schema');
		$requests = $this->findTickets(ticketType: TicketService::TYPE_REQUEST);
		$cms = $this->findTickets(ticketType: TicketService::TYPE_CONTACTMOMENT);
		// Survey responses migrated to the OpenRegister forms leaf (NC Forms).
		// CSAT will be re-sourced from form-link responses once the leaf
		// exposes a query helper; see openspec/changes/migrate-forms-to-forms-leaf.
		$responses = [];

		$rows = ['leads' => $leads, 'requests' => $requests, 'cms' => $cms, 'responses' => $responses];
		$current = $this->aggregateOverviewWindow(
			window: ['from' => $currentStart->getTimestamp(), 'to' => $now->getTimestamp()],
			rows: $rows
		);
		$previous = $this->aggregateOverviewWindow(
			window: ['from' => $previousStart->getTimestamp(), 'to' => $currentStart->getTimestamp()],
			rows: $rows
		);

		return [
			'leadConversionRate' => $current['leadConversionRate'],
			'avgRequestResolutionTime' => $current['avgRequestResolutionTime'],
			'contactMomentVolume' => $current['contactMomentVolume'],
			'customerSatisfactionScore' => $current['customerSatisfactionScore'],
			'period' => $period,
			'previousPeriod' => $previous,
		];
	}//end getOverview()

	/**
	 * Aggregate the four overview KPIs for one time window.
	 *
	 * Stateless helper called twice from getOverview() (current + previous).
	 *
	 * @param array{from: int, to: int} $window Inclusive lower and exclusive upper bound, as Unix timestamps.
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows under leads, requests, cms (contactmoment tickets) and responses (survey).
	 *
	 * @return array<string, float|int|null>
	 *
	 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-001
	 */
	private function aggregateOverviewWindow(array $window, array $rows): array {
		return [
			'leadConversionRate' => $this->leadConversionRate(leads: $rows['leads'], window: $window),
			'avgRequestResolutionTime' => $this->avgResolutionHours(requests: $rows['requests'], window: $window),
			'contactMomentVolume' => $this->contactMomentVolume(cms: $rows['cms'], window: $window),
			'customerSatisfactionScore' => $this->satisfactionScore(responses: $rows['responses'], window: $window),
		];
	}//end aggregateOverviewWindow()

	/**
	 * Share of the leads created in the window that are won, in percent.
	 *
	 * @param array<int, array<string, mixed>> $leads Lead rows.
	 * @param array{from: int, to: int} $window The time window.
	 *
	 * @return float|null Null when no lead falls in the window.
	 */
	private function leadConversionRate(array $leads, array $window): ?float {
		$statuses = [];
		foreach ($leads as $lead) {
			$timestamp = $this->extractTimestamp(row: $lead, fields: ['createdAt', 'created', 'expectedCloseDate']);
			if ($this->inWindow(timestamp: $timestamp, window: $window) === true) {
				$statuses[] = (string)($lead['status'] ?? '');
			}
		}

		if ($statuses === []) {
			return null;
		}

		return round((count(array_keys($statuses, 'won', true)) * 100.0) / count($statuses), 1);
	}//end leadConversionRate()

	/**
	 * Mean resolution time in hours of the requests completed in the window.
	 *
	 * On the ticket schema the request's `requestedAt` is `occurredAt` and the
	 * completion timestamp is `resolvedAt` (`completedAt` kept as a fallback
	 * for legacy rows).
	 *
	 * @param array<int, array<string, mixed>> $requests Request-ticket rows.
	 * @param array{from: int, to: int} $window The time window.
	 *
	 * @return float|null Null when no request was completed in the window.
	 */
	private function avgResolutionHours(array $requests, array $window): ?float {
		$hours = [];
		foreach ($requests as $request) {
			$requestedTs = $this->extractTimestamp(row: $request, fields: ['occurredAt']);
			$completedTs = $this->extractTimestamp(row: $request, fields: ['resolvedAt', 'completedAt']);
			if ($requestedTs === null || $this->inWindow(timestamp: $completedTs, window: $window) === false) {
				continue;
			}

			$hours[] = max(0, ($completedTs - $requestedTs) / 3600.0);
		}

		return $this->roundedMean(values: $hours, precision: 2);
	}//end avgResolutionHours()

	/**
	 * Number of contact moments in the window. The contactmoment's
	 * `contactedAt` is `occurredAt` on the ticket schema.
	 *
	 * @param array<int, array<string, mixed>> $cms Contactmoment-ticket rows.
	 * @param array{from: int, to: int} $window The time window.
	 *
	 * @return int
	 */
	private function contactMomentVolume(array $cms, array $window): int {
		$cmCount = 0;
		foreach ($cms as $cm) {
			$timestamp = $this->extractTimestamp(row: $cm, fields: ['occurredAt', 'createdAt']);
			if ($this->inWindow(timestamp: $timestamp, window: $window) === true) {
				$cmCount++;
			}
		}

		return $cmCount;
	}//end contactMomentVolume()

	/**
	 * Mean survey score (surveyResponse.score) of the responses in the window.
	 *
	 * @param array<int, array<string, mixed>> $responses Survey-response rows.
	 * @param array{from: int, to: int} $window The time window.
	 *
	 * @return float|null Null when no scored response falls in the window.
	 */
	private function satisfactionScore(array $responses, array $window): ?float {
		$scores = [];
		foreach ($responses as $response) {
			$timestamp = $this->extractTimestamp(row: $response, fields: ['submittedAt', 'createdAt']);
			if ($this->inWindow(timestamp: $timestamp, window: $window) === false
				|| isset($response['score']) === false || is_numeric($response['score']) === false
			) {
				continue;
			}

			$scores[] = (float)$response['score'];
		}

		return $this->roundedMean(values: $scores, precision: 2);
	}//end satisfactionScore()

	/**
	 * The mean of the values, rounded, or null when there are none.
	 *
	 * @param array<int, float|int> $values The values.
	 * @param int $precision Decimal digits to round to.
	 *
	 * @return float|null
	 */
	private function roundedMean(array $values, int $precision): ?float {
		if ($values === []) {
			return null;
		}

		return round(array_sum($values) / count($values), $precision);
	}//end roundedMean()

	/**
	 * Whether a timestamp falls in a window (lower bound inclusive, upper exclusive).
	 *
	 * @param int|null $timestamp The timestamp, null when the row had none.
	 * @param array{from: int, to: int} $window The time window.
	 *
	 * @return bool
	 */
	private function inWindow(?int $timestamp, array $window): bool {
		return $timestamp !== null && $timestamp >= $window['from'] && $timestamp < $window['to'];
	}//end inWindow()

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
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.2
	 */
	public function getTrends(string $metric, string $period = self::DEFAULT_PERIOD): array {
		if (in_array($metric, self::ALLOWED_TREND_METRICS, true) === false) {
			throw new InvalidArgumentException(message: 'Unsupported metric');
		}

		if (in_array($period, self::ALLOWED_OVERVIEW_PERIODS, true) === false) {
			throw new InvalidArgumentException(message: 'Invalid period');
		}

		return match ($metric) {
			'requests-by-category' => $this->buildCategorySeries(period: $period),
			'revenue' => $this->buildRevenueSeries(period: $period),
			'pipeline-by-stage' => $this->buildPipelineByStageSeries(period: $period),
			'revenue-by-product-category' => $this->buildRevenueByCategorySeries(period: $period),
			'top-customers' => $this->buildTopCustomersSeries(period: $period),
			default => $this->buildTimeBucketSeries(metric: $metric, period: $period),
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
	private function buildTimeBucketSeries(string $metric, string $period): array {
		$leads = $this->findObjects(schemaKey: 'lead_schema');
		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();
		$start = $now->modify(sprintf('-%d days', $days));
		$startTs = $start->getTimestamp();
		$nowTs = $now->getTimestamp();

		$bucketSize = $this->bucketSizeForPeriod(period: $period);

		$buckets = [];
		foreach ($leads as $lead) {
			$timestamp = $this->extractTimestamp(row: $lead, fields: ['createdAt', 'created', 'expectedCloseDate']);
			$key = $this->bucketKey(timestamp: $timestamp, startTs: $startTs, nowTs: $nowTs, bucketSize: $bucketSize);
			if ($key === null) {
				continue;
			}

			if (isset($buckets[$key]) === false) {
				$buckets[$key] = 0;
			}

			if ($metric === 'leads') {
				$buckets[$key] += 1;
				continue;
			}

			$buckets[$key] += (float)($lead['value'] ?? 0);
		}

		return ['metric' => $metric, 'period' => $period, 'series' => self::bucketSeries(buckets: $buckets, metric: $metric)];
	}//end buildTimeBucketSeries()

	/**
	 * The buckets as a dated series in date order: whole counts for leads, cents for any other metric.
	 *
	 * @param array<string, int|float> $buckets The bucket totals by date.
	 * @param string                   $metric  `leads`, `pipeline-value` or `revenue`.
	 *
	 * @return array<int, array{date: string, value: int|float}>
	 */
	private static function bucketSeries(array $buckets, string $metric): array {
		ksort($buckets);
		$series = [];
		foreach ($buckets as $date => $value) {
			$bucketValue = round((float)$value, 2);
			if ($metric === 'leads') {
				$bucketValue = (int)$value;
			}

			$series[] = ['date' => $date, 'value' => $bucketValue];
		}

		return $series;
	}//end bucketSeries()

	/**
	 * Build the requests-by-category series. Categories with zero requests in
	 * the period are excluded.
	 *
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
	 *
	 * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
	 */
	private function buildCategorySeries(string $period): array {
		$requests = $this->findTickets(ticketType: TicketService::TYPE_REQUEST);
		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();
		$startTs = $now->modify(sprintf('-%d days', $days))->getTimestamp();
		$nowTs = $now->getTimestamp();

		$counts = [];
		foreach ($requests as $request) {
			$timestamp = $this->extractTimestamp(row: $request, fields: ['occurredAt', 'createdAt']);
			if ($timestamp === null || $timestamp < $startTs || $timestamp > $nowTs) {
				continue;
			}

			// The request's own free-text `category` keeps its name on the
			// ticket schema (the complaint's category became complaintCategory).
			$category = (string)($request['category'] ?? '');
			if ($category === '') {
				continue;
			}

			if (isset($counts[$category]) === false) {
				$counts[$category] = 0;
			}

			$counts[$category]++;
		}

		return ['metric' => 'requests-by-category', 'period' => $period, 'series' => self::countSeries(counts: $counts)];
	}//end buildCategorySeries()

	/**
	 * The counts as a series, largest first; a zero count is left out.
	 *
	 * @param array<string, int> $counts Count per category.
	 *
	 * @return array<int, array{date: string, value: int}>
	 */
	private static function countSeries(array $counts): array {
		// Sort by descending count for stable rendering.
		arsort($counts);

		$series = [];
		foreach ($counts as $category => $count) {
			if ($count <= 0) {
				continue;
			}

			$series[] = ['date' => $category, 'value' => $count];
		}

		return $series;
	}//end countSeries()

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
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.2
	 */
	public function getFunnels(): array {
		$leads = $this->findObjects(schemaKey: 'lead_schema');
		$requests = $this->findTickets(ticketType: TicketService::TYPE_REQUEST);

		$leadCounts = self::statusCounts(rows: $leads, counts: ['open' => 0, 'won' => 0, 'lost' => 0]);
		$leadTotal = $leadCounts['open'] + $leadCounts['won'] + $leadCounts['lost'];
		$leadConversionRate = self::percentOf(part: $leadCounts['won'], total: $leadTotal);

		$requestCounts = self::statusCounts(rows: $requests, counts: ['new' => 0, 'in_progress' => 0, 'completed' => 0, 'rejected' => 0]);
		$requestTotal = array_sum($requestCounts);
		$resolutionRate = self::percentOf(part: $requestCounts['completed'], total: $requestTotal);

		return [
			'leadFunnel' => array_merge($leadCounts, ['conversionRate' => $leadConversionRate]),
			'requestFunnel' => array_merge($requestCounts, ['resolutionRate' => $resolutionRate]),
		];
	}//end getFunnels()

	/**
	 * How many rows carry each of the given statuses; other statuses are not counted.
	 *
	 * @param array<int, array<string, mixed>> $rows   The rows.
	 * @param array<string, int>               $counts The statuses to count, at zero, in answer order.
	 *
	 * @return array<string, int>
	 */
	private static function statusCounts(array $rows, array $counts): array {
		foreach ($rows as $row) {
			$status = (string)($row['status'] ?? '');
			if (isset($counts[$status]) === true) {
				$counts[$status]++;
			}
		}

		return $counts;
	}//end statusCounts()

	/**
	 * A part of a total as a percentage with one decimal, or null for an empty total.
	 *
	 * @param int $part  The part.
	 * @param int $total The total.
	 *
	 * @return float|null
	 */
	private static function percentOf(int $part, int $total): ?float {
		if ($total > 0) {
			return round(($part * 100.0) / $total, 1);
		}

		return null;
	}//end percentOf()

	/**
	 * Commercial KPI overview for the Commercial dashboard.
	 *
	 * Computes, for the trailing window of $period:
	 *   - revenue:           settled-POS turnover + won-deal value closed in window
	 *   - wonValue:          sum of won-lead value closed in window
	 *   - winRate:           won / (won + lost) leads closed in window (0-100, null if none)
	 *   - avgDealSize:       mean won-lead value in window (null if none)
	 *   - weightedForecast:  sum over OPEN leads of value * win chance (qualification score / 100)
	 *   - openPipelineValue: sum of value over OPEN leads (forward-looking)
	 *   - wonCount/lostCount: deals won and lost in window
	 *   - openCount/scoredOpenCount: OPEN leads, and those with a qualification score
	 *   - wonShareOfOpen:    won value as a whole percentage of the open value (null with nothing open)
	 *   - targetRemaining/pipelineCover: target less won value, open value over it (null without a target)
	 *   - previousPeriod:    windowed figures for the preceding equal-length window
	 *
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
	 * @param array<string, mixed> $leadFilter Field filters the open-pipeline figures follow, as the leads list sends them.
	 *
	 * @return array{
	 *   revenue: float,
	 *   wonValue: float,
	 *   winRate: float|null,
	 *   avgDealSize: float|null,
	 *   wonCount: int,
	 *   lostCount: int,
	 *   weightedForecast: float,
	 *   openPipelineValue: float,
	 *   openCount: int,
	 *   scoredOpenCount: int,
	 *   wonShareOfOpen: int|null,
	 *   targetRemaining: float|null,
	 *   pipelineCover: float|null,
	 *   period: string,
	 *   previousPeriod: array<string, float|int|null>
	 * }
	 *
	 * @throws InvalidArgumentException When the period is not recognised.
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	public function getCommercialOverview(string $period = self::DEFAULT_PERIOD, array $leadFilter = []): array {
		if (in_array($period, self::ALLOWED_OVERVIEW_PERIODS, true) === false) {
			throw new InvalidArgumentException(message: 'Invalid period');
		}

		$windows = $this->overviewWindows(period: $period);

		$leads = $this->findObjects(schemaKey: 'lead_schema');
		$pos = $this->findObjects(schemaKey: 'posTransaction_schema');

		$records = new CommercialRecords(leads: $leads, pos: $pos);

		$current = $this->aggregateCommercialWindow(window: $windows['current'], records: $records);
		$previous = $this->aggregateCommercialWindow(window: $windows['previous'], records: $records);

		$forecast = $this->aggregateOpenPipeline(leads: $this->leadsMatching(leads: $leads, filter: $leadFilter));
		$cover = $this->pipelineCover(openValue: $forecast['openPipelineValue'], wonValue: $current['wonValue']);

		return [
			'revenue' => $current['revenue'],
			'wonValue' => $current['wonValue'],
			'winRate' => $current['winRate'],
			'avgDealSize' => $current['avgDealSize'],
			'wonCount' => $current['wonCount'],
			'lostCount' => $current['lostCount'],
			'weightedForecast' => $forecast['weightedForecast'],
			'openPipelineValue' => $forecast['openPipelineValue'],
			'openCount' => $forecast['openCount'],
			'scoredOpenCount' => $forecast['scoredOpenCount'],
			'wonShareOfOpen' => $this->wonShareOfOpen(wonValue: $current['wonValue'], openValue: $forecast['openPipelineValue']),
			'targetRemaining' => $cover['targetRemaining'],
			'pipelineCover' => $cover['pipelineCover'],
			'period' => $period,
			'previousPeriod' => $previous,
		];
	}//end getCommercialOverview()

	/**
	 * The current trailing window of a period, ending now, and the window of
	 * the same length before it; each upper bound is exclusive.
	 *
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
	 *
	 * @return array{current: ReportingWindow, previous: ReportingWindow} The two windows.
	 */
	private function overviewWindows(string $period): array {
		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();

		$currentStart = $now->modify(sprintf('-%d days', $days));
		$previousStart = $currentStart->modify(sprintf('-%d days', $days));

		return [
			'current' => new ReportingWindow(startTs: $currentStart->getTimestamp(), lastTs: ($now->getTimestamp() - 1)),
			'previous' => new ReportingWindow(startTs: $previousStart->getTimestamp(), lastTs: ($currentStart->getTimestamp() - 1)),
		];
	}//end overviewWindows()

	/**
	 * Aggregate the windowed commercial figures for one time window.
	 *
	 * @param ReportingWindow   $window  The window, upper bound exclusive.
	 * @param CommercialRecords $records The lead and POS transaction rows.
	 *
	 * @return array{revenue: float, wonValue: float, winRate: float|null, avgDealSize: float|null, wonCount: int, lostCount: int}
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 * @spec openspec/specs/commercial-dashboard/spec.md#requirement-the-commercial-overview-counts-the-deals-behind-its-figures
	 */
	private function aggregateCommercialWindow(ReportingWindow $window, CommercialRecords $records): array {
		$posRevenue = $this->posRevenueTotal(pos: $this->revenueTransactionsWithin(pos: $records->pos, window: $window));
		$closed = $this->closedDealsWithin(leads: $records->leads, window: $window);

		return [
			'revenue' => round(($posRevenue + $closed['wonValue']), 2),
			'wonValue' => round($closed['wonValue'], 2),
			'winRate' => $this->winRate(closed: $closed),
			'avgDealSize' => $this->avgDealSize(closed: $closed),
			'wonCount' => $closed['wonCount'],
			'lostCount' => $closed['lostCount'],
		];
	}//end aggregateCommercialWindow()

	/**
	 * The POS transactions that realised revenue inside a window.
	 *
	 * @param array<int, array<string, mixed>> $pos    POS transaction rows.
	 * @param ReportingWindow                  $window The window.
	 *
	 * @return array<int, array<string, mixed>> The revenue transactions inside the window.
	 */
	private function revenueTransactionsWithin(array $pos, ReportingWindow $window): array {
		$within = [];
		foreach ($pos as $txn) {
			if ($this->isRevenueTransaction(txn: $txn) === true && $window->contains(timestamp: $this->posRevenueTimestamp(txn: $txn)) === true) {
				$within[] = $txn;
			}
		}

		return $within;
	}//end revenueTransactionsWithin()

	/**
	 * The summed total of POS transactions.
	 *
	 * @param array<int, array<string, mixed>> $pos POS transaction rows.
	 *
	 * @return float The total.
	 */
	private function posRevenueTotal(array $pos): float {
		$total = 0.0;
		foreach ($pos as $txn) {
			$total += (float)($txn['total'] ?? 0);
		}

		return $total;
	}//end posRevenueTotal()

	/**
	 * The won value and the won and lost counts of the deals closed inside a window.
	 *
	 * @param array<int, array<string, mixed>> $leads  Lead rows.
	 * @param ReportingWindow                  $window The window.
	 *
	 * @return array{wonValue: float, wonCount: int, lostCount: int}
	 */
	private function closedDealsWithin(array $leads, ReportingWindow $window): array {
		$closed = ['wonValue' => 0.0, 'wonCount' => 0, 'lostCount' => 0];
		foreach ($leads as $lead) {
			$status = (string)($lead['status'] ?? '');
			if (in_array($status, ['won', 'lost'], true) === false || $window->contains(timestamp: $this->leadCloseTimestamp(lead: $lead)) === false) {
				continue;
			}

			$closed = $this->withClosedDeal(closed: $closed, lead: $lead);
		}

		return $closed;
	}//end closedDealsWithin()

	/**
	 * Count one closed lead into the won value and the won or lost count.
	 *
	 * @param array{wonValue: float, wonCount: int, lostCount: int} $closed The tally so far.
	 * @param array<string, mixed>                                  $lead   A won or lost lead row.
	 *
	 * @return array{wonValue: float, wonCount: int, lostCount: int} The tally with the lead counted.
	 */
	private function withClosedDeal(array $closed, array $lead): array {
		if ((string)($lead['status'] ?? '') === 'won') {
			$closed['wonValue'] += (float)($lead['value'] ?? 0);
			$closed['wonCount']++;
			return $closed;
		}

		$closed['lostCount']++;
		return $closed;
	}//end withClosedDeal()

	/**
	 * The share of closed deals that was won, as a percentage.
	 *
	 * @param array{wonValue: float, wonCount: int, lostCount: int} $closed The closed deals.
	 *
	 * @return float|null The win rate, null when no deal closed.
	 */
	private function winRate(array $closed): ?float {
		$closedCount = ($closed['wonCount'] + $closed['lostCount']);
		if ($closedCount === 0) {
			return null;
		}

		return round(($closed['wonCount'] * 100.0) / $closedCount, 1);
	}//end winRate()

	/**
	 * The average value of a won deal.
	 *
	 * @param array{wonValue: float, wonCount: int, lostCount: int} $closed The closed deals.
	 *
	 * @return float|null The average, null when no deal was won.
	 */
	private function avgDealSize(array $closed): ?float {
		if ($closed['wonCount'] === 0) {
			return null;
		}

		return round($closed['wonValue'] / $closed['wonCount'], 2);
	}//end avgDealSize()

	/**
	 * Forward-looking open-pipeline figures (current state, not windowed).
	 *
	 * @param array<int, array<string, mixed>> $leads Lead rows.
	 *
	 * @return array{weightedForecast: float, openPipelineValue: float, openCount: int, scoredOpenCount: int}
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 * @spec openspec/specs/commercial-dashboard/spec.md#requirement-the-commercial-overview-counts-the-deals-behind-its-figures
	 */
	private function aggregateOpenPipeline(array $leads): array {
		$weightedForecast = 0.0;
		$openPipelineValue = 0.0;
		$openCount = 0;
		$scoredOpenCount = 0;
		foreach ($leads as $lead) {
			if (((string)($lead['status'] ?? '')) !== 'open') {
				continue;
			}

			$openCount++;
			if (is_numeric($lead['qualificationScore'] ?? null) === true) {
				$scoredOpenCount++;
			}

			$openPipelineValue += (float)($lead['value'] ?? 0);
			$weightedForecast += $this->winChanceWeightedValue(lead: $lead);
		}

		return [
			'weightedForecast' => round($weightedForecast, 2),
			'openPipelineValue' => round($openPipelineValue, 2),
			'openCount' => $openCount,
			'scoredOpenCount' => $scoredOpenCount,
		];
	}//end aggregateOpenPipeline()

	/**
	 * The open pipeline over what is still to come in against the pipeline
	 * target (the target less the won value of the window). Both are null
	 * without a target; with the target met the remainder is 0 and there is
	 * no cover to give.
	 *
	 * @param float $openValue The open pipeline value.
	 * @param float $wonValue  The won value of the window.
	 *
	 * @return array{targetRemaining: float|null, pipelineCover: float|null}
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md#requirement-the-commercial-overview-gives-the-pipeline-cover
	 */
	private function pipelineCover(float $openValue, float $wonValue): array {
		$target = $this->appConfig->getValueInt(Application::APP_ID, ForecastService::PIPELINE_TARGET_KEY, 0);
		if ($target <= 0) {
			return ['targetRemaining' => null, 'pipelineCover' => null];
		}

		$remaining = max(0.0, ((float)$target - $wonValue));
		if ($remaining <= 0.0) {
			return ['targetRemaining' => 0.0, 'pipelineCover' => null];
		}

		return [
			'targetRemaining' => round($remaining, 2),
			'pipelineCover' => round(($openValue / $remaining), 1),
		];
	}//end pipelineCover()

	/**
	 * The won value as a whole percentage of the open pipeline value, null
	 * when nothing is open.
	 *
	 * @param float $wonValue  The won value of the window.
	 * @param float $openValue The open pipeline value.
	 *
	 * @return int|null The share.
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md#requirement-the-commercial-overview-counts-the-deals-behind-its-figures
	 */
	private function wonShareOfOpen(float $wonValue, float $openValue): ?int {
		if ($openValue <= 0.0) {
			return null;
		}

		return (int)round(($wonValue * 100.0) / $openValue);
	}//end wonShareOfOpen()

	/**
	 * The leads every filter field matches: equal to its value, or to one of
	 * its values for a list. A key that is not a plain field name is ignored.
	 *
	 * @param array<int, array<string, mixed>> $leads Lead rows.
	 * @param array<string, mixed> $filter Field filters.
	 *
	 * @return array<int, array<string, mixed>> The matching leads.
	 *
	 * @spec openspec/specs/leads-list-board-line/spec.md#requirement-the-weighted-forecast-follows-the-list
	 */
	private function leadsMatching(array $leads, array $filter): array {
		$fields = [];
		foreach ($filter as $key => $expected) {
			if (is_string($key) === true && preg_match('/^[A-Za-z0-9_]+$/', $key) === 1) {
				$fields[$key] = array_map('strval', (array)$expected);
			}
		}

		if ($fields === []) {
			return $leads;
		}

		return array_values(
			array_filter(
				$leads,
				static function (array $lead) use ($fields): bool {
					foreach ($fields as $key => $values) {
						if (in_array((string)($lead[$key] ?? ''), $values, true) === false) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end leadsMatching()

	/**
	 * A lead's value weighted by its win chance.
	 *
	 * The win chance is the lead's qualification score read as a percentage,
	 * clamped to 0-100; a lead without a score counts as 0 (decided by Ruben,
	 * 2026-10-06). The `probability` field is hidden on the lead form and
	 * empty on real installs, so it never feeds a forecast. RapportageService
	 * applies the same rule; keep the two in step.
	 *
	 * @param array<string, mixed> $lead The lead row.
	 *
	 * @return float The weighted value.
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	private function winChanceWeightedValue(array $lead): float {
		$score = ($lead['qualificationScore'] ?? null);
		if (is_numeric($score) === false) {
			return 0.0;
		}

		return ((float)($lead['value'] ?? 0) * (max(0.0, min(100.0, (float)$score)) / 100.0));
	}//end winChanceWeightedValue()

	/**
	 * Time-bucketed revenue series: settled-POS turnover + won-deal value.
	 *
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
	 *
	 * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	private function buildRevenueSeries(string $period): array {
		$pos = $this->findObjects(schemaKey: 'posTransaction_schema');
		$leads = $this->findObjects(schemaKey: 'lead_schema');

		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();
		$startTs = $now->modify(sprintf('-%d days', $days))->getTimestamp();
		$nowTs = $now->getTimestamp();
		$bucketSize = $this->bucketSizeForPeriod(period: $period);

		$buckets = [];
		foreach ($pos as $txn) {
			if ($this->isRevenueTransaction(txn: $txn) === false) {
				continue;
			}

			$key = $this->bucketKey(timestamp: $this->posRevenueTimestamp(txn: $txn), startTs: $startTs, nowTs: $nowTs, bucketSize: $bucketSize);
			if ($key !== null) {
				$buckets[$key] = (($buckets[$key] ?? 0.0) + (float)($txn['total'] ?? 0));
			}
		}

		foreach ($leads as $lead) {
			if (((string)($lead['status'] ?? '')) !== 'won') {
				continue;
			}

			$key = $this->bucketKey(timestamp: $this->leadCloseTimestamp(lead: $lead), startTs: $startTs, nowTs: $nowTs, bucketSize: $bucketSize);
			if ($key !== null) {
				$buckets[$key] = (($buckets[$key] ?? 0.0) + (float)($lead['value'] ?? 0));
			}
		}

		return ['metric' => 'revenue', 'period' => $period, 'series' => self::bucketSeries(buckets: $buckets, metric: 'revenue')];
	}//end buildRevenueSeries()

	/**
	 * Open-lead value summed per pipeline stage, ordered by stage order.
	 *
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS (echoed only).
	 *
	 * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	private function buildPipelineByStageSeries(string $period): array {
		$leads = $this->findObjects(schemaKey: 'lead_schema');

		$byStage = [];
		$order = [];
		foreach ($leads as $lead) {
			if (((string)($lead['status'] ?? '')) !== 'open') {
				continue;
			}

			$stage = (string)($lead['stage'] ?? '');
			if ($stage === '') {
				$stage = 'Unassigned';
			}

			$byStage[$stage] = (($byStage[$stage] ?? 0.0) + (float)($lead['value'] ?? 0));
			if (isset($order[$stage]) === false) {
				$order[$stage] = (int)($lead['stageOrder'] ?? 999);
			}
		}

		return ['metric' => 'pipeline-by-stage', 'period' => $period, 'series' => self::stageSeries(byStage: $byStage, order: $order)];
	}//end buildPipelineByStageSeries()

	/**
	 * The stage totals as a series in stage order, rounded to cents; equal orders keep first-seen order.
	 *
	 * @param array<string, float> $byStage Value per stage.
	 * @param array<string, int>   $order   Order per stage.
	 *
	 * @return array<int, array{date: string, value: float}>
	 */
	private static function stageSeries(array $byStage, array $order): array {
		$rows = [];
		foreach ($byStage as $stage => $value) {
			$rows[] = ['date' => (string)$stage, 'value' => round((float)$value, 2), 'order' => $order[$stage]];
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

		return $series;
	}//end stageSeries()

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
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	private function buildRevenueByCategorySeries(string $period): array {
		$window = $this->trailingWindow(period: $period);

		$pos = $this->findObjects(schemaKey: 'posTransaction_schema');
		$lines = $this->findObjects(schemaKey: 'posTransactionLine_schema');
		$products = $this->findObjects(schemaKey: 'product_schema');
		$categories = $this->findObjects(schemaKey: 'productCategory_schema');

		$eligibleTxn = $this->transactionIdSet(pos: $this->revenueTransactionsWithin(pos: $pos, window: $window));
		$categoryNames = new ProductCategoryNames(
			categoryByProduct: $this->productCategoryIndex(products: $products),
			categoryNames: $this->categoryNameIndex(categories: $categories),
		);
		$byCategory = $this->lineRevenueByCategory(
			lines: $this->linesOfTransactions(lines: $lines, transactionIds: $eligibleTxn),
			categories: $categoryNames,
		);

		return ['metric' => 'revenue-by-product-category', 'period' => $period, 'series' => $this->descendingSeries(totals: $byCategory)];
	}//end buildRevenueByCategorySeries()

	/**
	 * The trailing window of a period, ending now, both bounds inclusive.
	 *
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
	 *
	 * @return ReportingWindow The window.
	 */
	private function trailingWindow(string $period): ReportingWindow {
		$days = self::PERIOD_DAYS[$period];
		$now = new DateTimeImmutable();

		$startTs = $now->modify(sprintf('-%d days', $days))->getTimestamp();

		return new ReportingWindow(startTs: $startTs, lastTs: $now->getTimestamp());
	}//end trailingWindow()

	/**
	 * The ids of POS transactions, as a set.
	 *
	 * @param array<int, array<string, mixed>> $pos POS transaction rows.
	 *
	 * @return array<string, true> Transaction id => true; rows without an id are left out.
	 */
	private function transactionIdSet(array $pos): array {
		$ids = [];
		foreach ($pos as $txn) {
			$id = $this->objectId(row: $txn);
			if ($id !== '') {
				$ids[$id] = true;
			}
		}

		return $ids;
	}//end transactionIdSet()

	/**
	 * The POS lines that belong to one of the given transactions.
	 *
	 * @param array<int, array<string, mixed>> $lines          POS line rows.
	 * @param array<string, true>              $transactionIds Transaction id set.
	 *
	 * @return array<int, array<string, mixed>> The matching lines.
	 */
	private function linesOfTransactions(array $lines, array $transactionIds): array {
		$matching = [];
		foreach ($lines as $line) {
			$txnId = (string)($line['transaction'] ?? '');
			if ($txnId !== '' && isset($transactionIds[$txnId]) === true) {
				$matching[] = $line;
			}
		}

		return $matching;
	}//end linesOfTransactions()

	/**
	 * POS line totals summed per product category.
	 *
	 * @param array<int, array<string, mixed>> $lines      POS line rows.
	 * @param ProductCategoryNames             $categories The category of each product.
	 *
	 * @return array<string, float> Category name => summed line total.
	 */
	private function lineRevenueByCategory(array $lines, ProductCategoryNames $categories): array {
		$byCategory = [];
		foreach ($lines as $line) {
			$category = $categories->nameOf(productId: (string)($line['product'] ?? ''));
			$byCategory[$category] = (($byCategory[$category] ?? 0.0) + (float)($line['lineTotal'] ?? 0));
		}

		return $byCategory;
	}//end lineRevenueByCategory()

	/**
	 * A label => value series, highest value first, values rounded to cents.
	 *
	 * @param array<string, float> $totals Label => value.
	 *
	 * @return array<int, array{date: string, value: float}> The series.
	 */
	private function descendingSeries(array $totals): array {
		arsort($totals);
		$series = [];
		foreach ($totals as $label => $value) {
			$series[] = ['date' => (string)$label, 'value' => round((float)$value, 2)];
		}

		return $series;
	}//end descendingSeries()

	/**
	 * Won-deal + POS revenue grouped by client, top N by value.
	 *
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
	 *
	 * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	private function buildTopCustomersSeries(string $period): array {
		$window = $this->trailingWindow(period: $period);

		$leads = $this->findObjects(schemaKey: 'lead_schema');
		$pos = $this->findObjects(schemaKey: 'posTransaction_schema');
		$clients = $this->findObjects(schemaKey: 'client_schema');

		$nameByClient = $this->clientNameIndex(clients: $clients);
		$byClient = $this->revenueByClient(
			wonLeads: $this->wonLeadsWithin(leads: $leads, window: $window),
			revenuePos: $this->revenueTransactionsWithin(pos: $pos, window: $window),
		);

		return ['metric' => 'top-customers', 'period' => $period, 'series' => $this->topCustomerSeries(byClient: $byClient, nameByClient: $nameByClient)];
	}//end buildTopCustomersSeries()

	/**
	 * The won leads that closed inside a window.
	 *
	 * @param array<int, array<string, mixed>> $leads  Lead rows.
	 * @param ReportingWindow                  $window The window.
	 *
	 * @return array<int, array<string, mixed>> The won leads inside the window.
	 */
	private function wonLeadsWithin(array $leads, ReportingWindow $window): array {
		$within = [];
		foreach ($leads as $lead) {
			if (((string)($lead['status'] ?? '')) === 'won' && $window->contains(timestamp: $this->leadCloseTimestamp(lead: $lead)) === true) {
				$within[] = $lead;
			}
		}

		return $within;
	}//end wonLeadsWithin()

	/**
	 * Won-deal value, then POS revenue, summed per client.
	 *
	 * @param array<int, array<string, mixed>> $wonLeads   Won lead rows.
	 * @param array<int, array<string, mixed>> $revenuePos Revenue POS transaction rows.
	 *
	 * @return array<string, float> Client id => revenue; '' collects rows without a client.
	 */
	private function revenueByClient(array $wonLeads, array $revenuePos): array {
		$byClient = [];
		foreach ($wonLeads as $lead) {
			$client = (string)($lead['client'] ?? '');
			$byClient[$client] = (($byClient[$client] ?? 0.0) + (float)($lead['value'] ?? 0));
		}

		foreach ($revenuePos as $txn) {
			$client = (string)($txn['client'] ?? ($txn['customer'] ?? ''));
			$byClient[$client] = (($byClient[$client] ?? 0.0) + (float)($txn['total'] ?? 0));
		}

		return $byClient;
	}//end revenueByClient()

	/**
	 * The top customers by revenue, named, rows without a client left out.
	 *
	 * @param array<string, float>  $byClient     Client id => revenue.
	 * @param array<string, string> $nameByClient Client id => display name.
	 *
	 * @return array<int, array{date: string, value: float}> At most TOP_CUSTOMERS_LIMIT entries.
	 */
	private function topCustomerSeries(array $byClient, array $nameByClient): array {
		arsort($byClient);
		$series = [];
		foreach ($byClient as $client => $value) {
			if (count($series) >= self::TOP_CUSTOMERS_LIMIT) {
				break;
			}

			if ((string)$client === '') {
				continue;
			}

			$series[] = ['date' => ($nameByClient[(string)$client] ?? (string)$client), 'value' => round((float)$value, 2)];
		}

		return $series;
	}//end topCustomerSeries()

	/**
	 * Whether a POS transaction counts as realised revenue.
	 *
	 * @param array<string, mixed> $txn POS transaction row.
	 *
	 * @return bool
	 */
	private function isRevenueTransaction(array $txn): bool {
		return in_array((string)($txn['status'] ?? ''), self::REVENUE_POS_STATUSES, true);
	}//end isRevenueTransaction()

	/**
	 * Timestamp at which a POS transaction realised revenue.
	 *
	 * @param array<string, mixed> $txn POS transaction row.
	 *
	 * @return int|null
	 */
	private function posRevenueTimestamp(array $txn): ?int {
		return $this->extractTimestamp(row: $txn, fields: ['settledAt', 'confirmedAt', 'created', 'createdAt']);
	}//end posRevenueTimestamp()

	/**
	 * Timestamp at which a lead closed (won/lost).
	 *
	 * @param array<string, mixed> $lead Lead row.
	 *
	 * @return int|null
	 */
	private function leadCloseTimestamp(array $lead): ?int {
		return $this->extractTimestamp(row: $lead, fields: ['stageEnteredAt', 'expectedCloseDate', 'created', 'createdAt']);
	}//end leadCloseTimestamp()

	/**
	 * Bucket key (`Y-m-d`) for a timestamp, or null when out of range.
	 *
	 * @param int|null $timestamp Unix timestamp.
	 * @param int $startTs Window start.
	 * @param int $nowTs Window end.
	 * @param int $bucketSize Bucket width in seconds.
	 *
	 * @return string|null
	 */
	private function bucketKey(?int $timestamp, int $startTs, int $nowTs, int $bucketSize): ?string {
		if ($timestamp === null || $timestamp < $startTs || $timestamp > $nowTs) {
			return null;
		}

		$bucketTs = ($startTs + ((int)floor((($timestamp - $startTs) / $bucketSize)) * $bucketSize));
		return date('Y-m-d', $bucketTs);
	}//end bucketKey()

	/**
	 * Bucket width in seconds for a period (day/week/month granularity).
	 *
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
	 *
	 * @return int
	 */
	private function bucketSizeForPeriod(string $period): int {
		return match ($period) {
			'week', 'month' => 86400,
			'quarter' => (86400 * 7),
			'year' => (86400 * 30),
			default => 86400,
		};
	}//end bucketSizeForPeriod()

	/**
	 * Extract the canonical object id from a row (`@self.id` or `id`).
	 *
	 * @param array<string, mixed> $row Object row.
	 *
	 * @return string
	 */
	private function objectId(array $row): string {
		if (isset($row['@self']) === true && is_array($row['@self']) === true && isset($row['@self']['id']) === true) {
			return (string)$row['@self']['id'];
		}

		if (isset($row['id']) === true) {
			return (string)$row['id'];
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
	private function productCategoryIndex(array $products): array {
		$index = [];
		foreach ($products as $product) {
			$id = $this->objectId(row: $product);
			if ($id !== '') {
				$index[$id] = (string)($product['category'] ?? '');
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
	private function clientNameIndex(array $clients): array {
		$index = [];
		foreach ($clients as $client) {
			$id = $this->objectId(row: $client);
			if ($id !== '') {
				$index[$id] = (string)($client['name'] ?? $id);
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
	private function categoryNameIndex(array $categories): array {
		$index = [];
		foreach ($categories as $category) {
			$id = $this->objectId(row: $category);
			if ($id !== '') {
				$index[$id] = (string)($category['name'] ?? $id);
			}
		}

		return $index;
	}//end categoryNameIndex()

	/**
	 * Extract a unix timestamp from one of several candidate fields.
	 *
	 * @param array<string, mixed> $row Object row.
	 * @param array<int, string> $fields Candidate field names, first non-empty wins.
	 *
	 * @return int|null Unix timestamp or null when no field parses.
	 */
	private function extractTimestamp(array $row, array $fields): ?int {
		foreach ($fields as $field) {
			if (isset($row[$field]) === false) {
				continue;
			}

			$value = (string)$row[$field];
			if ($value === '') {
				continue;
			}

			$timestamp = strtotime($value);
			if ($timestamp !== false) {
				return $timestamp;
			}
		}

		return null;
	}//end extractTimestamp()
}//end class
