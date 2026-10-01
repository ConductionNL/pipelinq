<?php

/**
 * Pipelinq SlaAttainmentService.
 *
 * Aggregates SLA breach-event records to compute attainment ratios
 * broken down by policy, customer-tier, target-kind or assignee-team
 * for a configurable time bucket (day / week / month / quarter).
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compute SLA attainment aggregations from `slaBreachEvent` records.
 *
 * The service queries breach events for a time-bucket and pairs them
 * with currently-tracked objects (request / complaint / callback) to
 * compute per-target attainment ratios. Per-target accounting: a
 * tracked object that met `acknowledgement` but breached `resolution`
 * counts as 1.0 met for acknowledgement and 0.0 met for resolution
 * (REQ-006).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Bridges OR + SLA engine constants for breach-event/tracked-object aggregation
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Attainment aggregation is inherently branchy; split into small focused methods
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-attainment-reporting
 */
class SlaAttainmentService {
	public const VALID_BUCKETS = ['day', 'week', 'month', 'quarter'];

	public const VALID_GROUPS = ['policy', 'customer', 'tier', 'team', 'target'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param TicketService $ticketService Resolver for the unified ticket schema.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private TicketService $ticketService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute attainment for the requested filter.
	 *
	 * @param array<string, mixed> $params Filter parameters: bucket, date,
	 *                                     week, month, quarter, groupBy, policy.
	 *
	 * @return array<string, mixed> Attainment payload.
	 *
	 * @throws InvalidArgumentException When required params are invalid.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function compute(array $params): array {
		$bucket = (string)($params['bucket'] ?? 'month');
		if (in_array($bucket, self::VALID_BUCKETS, true) === false) {
			throw new InvalidArgumentException('invalidBucket');
		}

		$groupBy = (string)($params['groupBy'] ?? 'policy');
		if (in_array($groupBy, self::VALID_GROUPS, true) === false) {
			throw new InvalidArgumentException('invalidGroupBy');
		}

		[$start, $end] = $this->resolveBucketRange(bucket: $bucket, params: $params);
		$events = $this->loadBreachEventsInRange(start: $start, end: $end);
		$policyFilter = (string)($params['policy'] ?? '');

		// Met objects and breach events are grouped from the same tracked
		// objects, so a group's met and breached counts describe one set.
		$trackedRows = $this->loadTrackedRows();
		$context = $this->buildGroupingContext(groupBy: $groupBy, trackedRows: $trackedRows);

		$accumulated = $this->accumulateBreachedEvents(
			events: $events,
			policyFilter: $policyFilter,
			groupBy: $groupBy,
			context: $context
		);

		// For attainment we need the closed-met denominator too — the tracked
		// objects with all targets met in the period.
		$withCounts = $this->countWithObjectsInRange(
			rows: $trackedRows,
			start: $start,
			end: $end,
			policyFilter: $policyFilter,
			groupBy: $groupBy,
			context: $context
		);
		$merged = $this->mergeWithCounts(accumulated: $accumulated, withCounts: $withCounts);

		$byTargetOut = $this->buildByTargetOut(byTarget: $merged['byTarget']);
		$byGroup = $this->buildByGroup(groupAccum: $merged['groupAccum']);
		$overallAttainment = $this->ratio(numerator: $merged['met'], denominator: $merged['total']);

		return [
			'attainment' => $overallAttainment,
			// Same value scaled to a literal percent (0–100) so a declarative
			// dashboard stat widget can render it with format.style "percent".
			'attainmentPercent' => round(($overallAttainment * 100), 1),
			'total' => $merged['total'],
			'met' => $merged['met'],
			'breached' => $accumulated['breached'],
			'inFlightBreached' => $accumulated['inFlight'],
			'closedBreached' => $accumulated['closed'],
			'range' => [
				'start' => $start->format(DateTimeInterface::ATOM),
				'end' => $end->format(DateTimeInterface::ATOM),
			],
			'details' => [
				'byTarget' => $byTargetOut,
				'byGroup' => $byGroup,
			],
		];
	}//end compute()

	/**
	 * Walk the breach events and accumulate per-target/per-group breach counts.
	 *
	 * @param array<int, array<string, mixed>> $events Breach events.
	 * @param string $policyFilter Optional policy identity filter.
	 * @param string $groupBy Grouping mode.
	 * @param array<string, array<string, mixed>> $context Grouping context (see buildGroupingContext()).
	 *
	 * @return array{total: int, breached: int, inFlight: int, closed: int, byTarget: array<string, mixed>, groupAccum: array<string, mixed>}
	 */
	private function accumulateBreachedEvents(array $events, string $policyFilter, string $groupBy, array $context): array {
		$total = 0;
		$breached = 0;
		$inFlight = 0;
		$closed = 0;
		$byTarget = [];
		$groupAccum = [];

		foreach ($events as $event) {
			if ($policyFilter !== '' && (string)($event['policyId'] ?? '') !== $policyFilter) {
				continue;
			}

			$total++;
			$kind = (string)($event['targetKind'] ?? 'resolution');
			$resolved = isset($event['resolvedAt']) === true && $event['resolvedAt'] !== '';
			if ($resolved === true) {
				$closed++;
			}

			if ($resolved === false) {
				$inFlight++;
			}

			$breached++;
			$byTarget[$kind] = ($byTarget[$kind] ?? ['breached' => 0, 'met' => 0]);
			$byTarget[$kind]['breached']++;

			// The object the breach belongs to supplies its tier, team and customer.
			$tracked = ($context['tracked'][(string)($event['targetObjectId'] ?? '')] ?? []);
			$groupKeys = $this->groupKeys(
				groupBy: $groupBy,
				policyId: (string)($event['policyId'] ?? ''),
				kinds: [$kind],
				tracked: $tracked,
				event: $event,
				context: $context
			);
			foreach ($groupKeys as $groupKey) {
				$groupName = $this->groupName(groupBy: $groupBy, key: $groupKey, context: $context);
				$groupAccum[$groupKey] = ($groupAccum[$groupKey] ?? ['name' => $groupName, 'total' => 0, 'breached' => 0]);
				$groupAccum[$groupKey]['total']++;
				$groupAccum[$groupKey]['breached']++;
			}
		}//end foreach

		return [
			'total' => $total,
			'breached' => $breached,
			'inFlight' => $inFlight,
			'closed' => $closed,
			'byTarget' => $byTarget,
			'groupAccum' => $groupAccum,
		];
	}//end accumulateBreachedEvents()

	/**
	 * Merge the closed-met tracked-object counts into the breached-event accumulation.
	 *
	 * @param array<string, mixed> $accumulated Breach-event accumulation (see accumulateBreachedEvents()).
	 * @param array<string, mixed> $withCounts Met-object counts (see countMetObjectsInRange()).
	 *
	 * @return array{total: int, met: int, byTarget: array<string, mixed>, groupAccum: array<string, mixed>}
	 */
	private function mergeWithCounts(array $accumulated, array $withCounts): array {
		$byTarget = $accumulated['byTarget'];
		$groupAccum = $accumulated['groupAccum'];

		foreach ($withCounts['byTarget'] as $kind => $count) {
			$byTarget[$kind] = ($byTarget[$kind] ?? ['breached' => 0, 'met' => 0]);
			$byTarget[$kind]['met'] = $count;
		}

		$with = $withCounts['total'];
		$total = $accumulated['total'] + $withCounts['total'];

		foreach ($withCounts['byGroup'] as $key => $entry) {
			$groupAccum[$key] = ($groupAccum[$key] ?? ['name' => $entry['name'], 'total' => 0, 'breached' => 0, 'met' => 0]);
			$groupAccum[$key]['total'] = ($groupAccum[$key]['total'] ?? 0) + $entry['total'];
			$groupAccum[$key]['met'] = ($groupAccum[$key]['met'] ?? 0) + $entry['total'];
		}

		return [
			'total' => $total,
			'met' => $with,
			'byTarget' => $byTarget,
			'groupAccum' => $groupAccum,
		];
	}//end mergeMetCounts()

	/**
	 * Build the `details.byTarget` output payload from the merged per-target counts.
	 *
	 * @param array<string, array<string, int>> $byTarget Merged per-target breached/met counts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function buildByTargetOut(array $byTarget): array {
		$byTargetOut = [];
		foreach ($byTarget as $kind => $counts) {
			$withCount = (int)$counts['met'];
			$denom = ($counts['breached'] + $withCount);
			$attainment = $this->ratio(numerator: $withCount, denominator: $denom);

			$byTargetOut[$kind] = [
				'attainment' => $attainment,
				'breached' => $counts['breached'],
				'met' => $withCount,
			];
		}

		return $byTargetOut;
	}//end buildByTargetOut()

	/**
	 * Build the `details.byGroup` output payload from the merged per-group counts.
	 *
	 * @param array<string, array<string, mixed>> $groupAccum Merged per-group breached/met counts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildByGroup(array $groupAccum): array {
		$byGroup = [];
		foreach ($groupAccum as $key => $entry) {
			$denom = (int)$entry['total'];
			$withE = (int)($entry['met'] ?? 0);
			$groupAttainment = $this->ratio(numerator: $withE, denominator: $denom);

			$byGroup[] = [
				'groupKey' => (string)$key,
				'groupName' => (string)($entry['name'] ?? $key),
				'attainment' => $groupAttainment,
				'total' => $denom,
				'met' => $withE,
				'breached' => (int)$entry['breached'],
			];
		}

		return $byGroup;
	}//end buildByGroup()

	/**
	 * Ratio rounded to 4 decimals, or 0.0 when the denominator is not positive.
	 *
	 * @param int $numerator The numerator.
	 * @param int $denominator The denominator.
	 *
	 * @return float
	 */
	private function ratio(int $numerator, int $denominator): float {
		if ($denominator > 0) {
			return round(($numerator / $denominator), 4);
		}

		return 0.0;
	}//end ratio()

	/**
	 * Resolve the (start, end) instant pair for the requested bucket.
	 *
	 * @param string $bucket Bucket identifier.
	 * @param array<string, mixed> $params Filter parameters.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} Range.
	 *
	 * @throws InvalidArgumentException On missing/invalid bucket value.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function resolveBucketRange(string $bucket, array $params): array {
		$tz = new DateTimeZone('UTC');
		$now = new DateTimeImmutable('now', $tz);

		return match ($bucket) {
			'day' => $this->resolveDayRange(now: $now, tz: $tz, params: $params),
			'week' => $this->resolveWeekRange(now: $now, tz: $tz, params: $params),
			'month' => $this->resolveMonthRange(now: $now, tz: $tz, params: $params),
			'quarter' => $this->resolveQuarterRange(now: $now, tz: $tz, params: $params),
			default => throw new InvalidArgumentException('invalidBucket'),
		};
	}//end resolveBucketRange()

	/**
	 * Resolve the (start, end) range for the "day" bucket.
	 *
	 * @param DateTimeImmutable $now Current instant.
	 * @param DateTimeZone $tz Working timezone.
	 * @param array<string, mixed> $params Filter parameters.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @throws InvalidArgumentException On an invalid date value.
	 */
	private function resolveDayRange(DateTimeImmutable $now, DateTimeZone $tz, array $params): array {
		// An empty/missing date defaults to today so a declarative
		// dashboard can drive the bucket select alone (no client-side
		// date math); an explicitly supplied value must still be valid.
		$date = (string)($params['date'] ?? '');
		if ($date === '') {
			$date = $now->format('Y-m-d');
		} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			throw new InvalidArgumentException('invalidDate');
		}

		$start = new DateTimeImmutable($date . ' 00:00:00', $tz);
		return [$start, $start->modify('+1 day')];
	}//end resolveDayRange()

	/**
	 * Resolve the (start, end) range for the "week" bucket.
	 *
	 * @param DateTimeImmutable $now Current instant.
	 * @param DateTimeZone $tz Working timezone.
	 * @param array<string, mixed> $params Filter parameters.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @throws InvalidArgumentException On an invalid week value.
	 */
	private function resolveWeekRange(DateTimeImmutable $now, DateTimeZone $tz, array $params): array {
		$week = (string)($params['week'] ?? '');
		if ($week === '') {
			$week = $now->format('o-\WW');
		}

		if (preg_match('/^(\d{4})-W(\d{1,2})$/', $week, $matches) !== 1) {
			throw new InvalidArgumentException('invalidWeek');
		}

		$start = (new DateTimeImmutable('now', $tz))
			->setISODate((int)$matches[1], (int)$matches[2])
			->setTime(0, 0);
		return [$start, $start->modify('+7 days')];
	}//end resolveWeekRange()

	/**
	 * Resolve the (start, end) range for the "month" bucket.
	 *
	 * @param DateTimeImmutable $now Current instant.
	 * @param DateTimeZone $tz Working timezone.
	 * @param array<string, mixed> $params Filter parameters.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @throws InvalidArgumentException On an invalid month value.
	 */
	private function resolveMonthRange(DateTimeImmutable $now, DateTimeZone $tz, array $params): array {
		$month = (string)($params['month'] ?? '');
		if ($month === '') {
			$month = $now->format('Y-m');
		}

		if (preg_match('/^(\d{4})-(\d{2})$/', $month) !== 1) {
			throw new InvalidArgumentException('invalidMonth');
		}

		$start = new DateTimeImmutable($month . '-01 00:00:00', $tz);
		return [$start, $start->modify('+1 month')];
	}//end resolveMonthRange()

	/**
	 * Resolve the (start, end) range for the "quarter" bucket.
	 *
	 * @param DateTimeImmutable $now Current instant.
	 * @param DateTimeZone $tz Working timezone.
	 * @param array<string, mixed> $params Filter parameters.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @throws InvalidArgumentException On an invalid quarter value.
	 */
	private function resolveQuarterRange(DateTimeImmutable $now, DateTimeZone $tz, array $params): array {
		$quarter = (string)($params['quarter'] ?? '');
		if ($quarter === '') {
			$quarter = sprintf('%s-Q%d', $now->format('Y'), (int)ceil(((int)$now->format('n')) / 3));
		}

		if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $matches) !== 1) {
			throw new InvalidArgumentException('invalidQuarter');
		}

		$month = (((int)$matches[2] - 1) * 3) + 1;
		$start = new DateTimeImmutable(sprintf('%s-%02d-01 00:00:00', $matches[1], $month), $tz);
		return [$start, $start->modify('+3 months')];
	}//end resolveQuarterRange()

	/**
	 * Load breach-event records whose breachedAt is in the time range.
	 *
	 * @param DateTimeInterface $start Start instant.
	 * @param DateTimeInterface $end End instant (exclusive).
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private function loadBreachEventsInRange(DateTimeInterface $start, DateTimeInterface $end): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'sla_breach_event_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaAttainmentService: ObjectService not available',
				['error' => $e->getMessage()]
			);
			return [];
		}

		try {
			// 🔴 register/schema GO INSIDE `filters`, NOT AT THE TOP LEVEL.
			//
			// ObjectService::prepareFindAllConfig() reads them from
			// $config['filters'] and nowhere else, so a top-level pair resolved
			// NO register/schema context and MagicMapper::findAll() answered [].
			// /api/sla/attainment therefore returned 200 while reporting zero
			// breaches forever — a dashboard that is confidently wrong rather
			// than visibly broken.
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 5000,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaAttainmentService: findAll failed',
				['error' => $e->getMessage()]
			);
			return [];
		}

		$events = [];
		foreach ((array)$rows as $row) {
			$event = $this->parseBreachEventRow(row: $row, start: $start, end: $end);
			if ($event !== null) {
				$events[] = $event;
			}
		}

		return $events;
	}//end loadBreachEventsInRange()

	/**
	 * Parse and range-filter a single breach-event row.
	 *
	 * @param mixed $row Raw row.
	 * @param DateTimeInterface $start Start instant.
	 * @param DateTimeInterface $end End instant (exclusive).
	 *
	 * @return array<string, mixed>|null The normalised event, or null when out of range/unparsable.
	 */
	private function parseBreachEventRow(mixed $row, DateTimeInterface $start, DateTimeInterface $end): ?array {
		$array = $this->normalise(row: $row);
		$when = $array['breachedAt'] ?? '';
		if ($when === '') {
			return null;
		}

		try {
			$instant = new DateTimeImmutable((string)$when);
		} catch (Throwable $e) {
			return null;
		}

		if ($instant < $start || $instant >= $end) {
			return null;
		}

		return $array;
	}//end parseBreachEventRow()

	/**
	 * Load every SLA-tracked object: the ticket subtypes (request +
	 * complaint, both on the unified `ticket` schema, narrowed with the
	 * `ticketType` discriminator) plus the callback schema. Each row is a
	 * plain array carrying its `id`, which breach events point at.
	 *
	 * @return array<int, array<string, mixed>> Tracked objects.
	 */
	private function loadTrackedRows(): array {
		// The ticket lookup is fail-soft: it yields [] when the ticket surface
		// is unprovisioned or OpenRegister is unavailable.
		$raw = [];
		foreach ([TicketService::TYPE_REQUEST, TicketService::TYPE_COMPLAINT] as $ticketType) {
			$raw = array_merge($raw, (array)$this->ticketService->findByType($ticketType, [], 5000));
		}

		$raw = array_merge($raw, $this->findConfiguredObjects(registerKey: 'register', schemaKey: 'callback_schema'));

		return array_map(fn ($row): array => $this->normalise(row: $row), $raw);
	}//end loadTrackedRows()

	/**
	 * What grouping needs beyond the rows themselves: tracked objects by id,
	 * the policies (for their names and tiers) and, when grouping by customer,
	 * the client names.
	 *
	 * @param string $groupBy Grouping mode.
	 * @param array<int, array<string, mixed>> $trackedRows Tracked objects (see loadTrackedRows()).
	 *
	 * @return array{tracked: array<string, array<string, mixed>>, policies: array<string, array<string, mixed>>, clients: array<string, string>}
	 */
	private function buildGroupingContext(string $groupBy, array $trackedRows): array {
		$tracked = [];
		foreach ($trackedRows as $row) {
			if (($row['id'] ?? '') !== '') {
				$tracked[(string)$row['id']] = $row;
			}
		}

		$policies = [];
		if (in_array($groupBy, ['policy', 'tier'], true) === true) {
			foreach ($this->findConfiguredObjects(registerKey: 'sla_register', schemaKey: 'sla_policy_schema') as $row) {
				$array = $this->normalise(row: $row);
				$policies[(string)($array['id'] ?? '')] = $array;
			}
		}

		$clients = [];
		if ($groupBy === 'customer') {
			foreach ($this->findConfiguredObjects(registerKey: 'register', schemaKey: 'client_schema') as $row) {
				$array = $this->normalise(row: $row);
				$clients[(string)($array['id'] ?? '')] = (string)($array['name'] ?? '');
			}
		}

		return ['tracked' => $tracked, 'policies' => $policies, 'clients' => $clients];
	}//end buildGroupingContext()

	/**
	 * Count tracked objects that met all targets in the time range: the ones
	 * with slaStatus.targets[*].withAt in range and no breached/at-risk
	 * targets remaining.
	 *
	 * @param array<int, array<string, mixed>> $rows Tracked objects (see loadTrackedRows()).
	 * @param DateTimeInterface $start Start instant.
	 * @param DateTimeInterface $end End instant.
	 * @param string $policyFilter Optional policy identity filter.
	 * @param string $groupBy Grouping mode.
	 * @param array<string, array<string, mixed>> $context Grouping context (see buildGroupingContext()).
	 *
	 * @return array{total: int, byTarget: array<string, int>, byGroup: array<string, array<string, mixed>>} Counts.
	 */
	private function countWithObjectsInRange(
		array $rows,
		DateTimeInterface $start,
		DateTimeInterface $end,
		string $policyFilter,
		string $groupBy,
		array $context,
	): array {
		$accumulator = ['total' => 0, 'byTarget' => [], 'byGroup' => []];

		foreach ($rows as $row) {
			$result = $this->evaluateTrackedObjectRow(row: $row, start: $start, end: $end, policyFilter: $policyFilter);
			if ($result === null) {
				continue;
			}

			$accumulator['total']++;
			foreach ($result['byTarget'] as $kind => $count) {
				$accumulator['byTarget'][$kind] = ($accumulator['byTarget'][$kind] ?? 0) + $count;
			}

			$groupKeys = $this->groupKeys(
				groupBy: $groupBy,
				policyId: $result['policyId'],
				kinds: array_keys($result['byTarget']),
				tracked: $row,
				event: [],
				context: $context
			);
			foreach ($groupKeys as $key) {
				$name = $this->groupName(groupBy: $groupBy, key: $key, context: $context);
				$accumulator['byGroup'][$key] = ($accumulator['byGroup'][$key] ?? ['name' => $name, 'total' => 0]);
				$accumulator['byGroup'][$key]['total']++;
			}
		}//end foreach

		return $accumulator;
	}//end countWithObjectsInRange()

	/**
	 * Fetch the objects of a register and schema named by two app config keys.
	 *
	 * @param string $registerKey App config key holding the register id.
	 * @param string $schemaKey App config key holding the schema id.
	 *
	 * @return array<int, mixed> Rows ([] when unconfigured/unavailable).
	 */
	private function findConfiguredObjects(string $registerKey, string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, $registerKey, '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schemaId === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			return [];
		}

		return $this->fetchTrackedObjectRows(objectService: $objectService, register: $register, schemaId: $schemaId);
	}//end findConfiguredObjects()

	/**
	 * Fetch tracked-object rows for a schema, tolerating findAll failures.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register identifier.
	 * @param string $schemaId The schema identifier.
	 *
	 * @return array<int, mixed>
	 */
	private function fetchTrackedObjectRows(object $objectService, string $register, string $schemaId): array {
		try {
			// Same shape as loadBreachEventsInRange(): register/schema belong
			// inside `filters`, or the query resolves no context and returns [].
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schemaId,
					],
					'limit' => 5000,
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		return (array)$rows;
	}//end fetchTrackedObjectRows()

	/**
	 * Evaluate whether a tracked-object row is counted for attainment.
	 *
	 * @param mixed $row Raw row.
	 * @param DateTimeInterface $start Start instant.
	 * @param DateTimeInterface $end End instant.
	 * @param string $policyFilter Optional policy identity filter.
	 *
	 * @return array{byTarget: array<string, int>, policyId: string}|null Null when filtered out or not fully-met-in-range.
	 */
	private function evaluateTrackedObjectRow(mixed $row, DateTimeInterface $start, DateTimeInterface $end, string $policyFilter): ?array {
		$array = $this->normalise(row: $row);
		$slaStatus = $array['slaStatus'] ?? null;
		if (is_array($slaStatus) === false) {
			return null;
		}

		if ($policyFilter !== '' && (string)($slaStatus['policyId'] ?? '') !== $policyFilter) {
			return null;
		}

		$targets = $this->evaluateSlaTargets(slaStatus: $slaStatus, start: $start, end: $end);
		if ($targets['allMet'] === false || $targets['touchedKey'] === false) {
			return null;
		}

		return [
			'byTarget' => $targets['byTarget'],
			'policyId' => (string)($slaStatus['policyId'] ?? ''),
		];
	}//end evaluateTrackedObjectRow()

	/**
	 * Evaluate every SLA target on a tracked object.
	 *
	 * @param array<string, mixed> $slaStatus The object's slaStatus.
	 * @param DateTimeInterface $start Start instant.
	 * @param DateTimeInterface $end End instant.
	 *
	 * @return array{allMet: bool, touchedKey: bool, byTarget: array<string, int>}
	 */
	private function evaluateSlaTargets(array $slaStatus, DateTimeInterface $start, DateTimeInterface $end): array {
		$allWith = true;
		$touchedKey = false;
		$byTarget = [];
		foreach (($slaStatus['targets'] ?? []) as $target) {
			$status = (string)($target['status'] ?? '');
			if ($status !== SlaEngineService::STATUS_MET) {
				$allWith = false;
				continue;
			}

			$withAt = $target['withAt'] ?? '';
			if ($withAt === '') {
				continue;
			}

			try {
				$withInstant = new DateTimeImmutable((string)$withAt);
			} catch (Throwable $e) {
				continue;
			}

			if ($withInstant >= $start && $withInstant < $end) {
				$touchedKey = true;
				$kind = (string)($target['kind'] ?? 'resolution');
				$byTarget[$kind] = ($byTarget[$kind] ?? 0) + 1;
			}
		}//end foreach

		return ['allMet' => $allWith, 'touchedKey' => $touchedKey, 'byTarget' => $byTarget];
	}//end evaluateSlaTargets()

	/**
	 * The groups a met object or a breach event counts in. One group, except
	 * when grouping by target: a met object counts once per target it met.
	 *
	 * A breach event's own fields win; otherwise the values come from the
	 * tracked object it belongs to, so met and breached rows of one group
	 * describe the same objects. A tier falls back to the policy's own tier,
	 * which is how the engine chose the policy.
	 *
	 * @param string $groupBy Grouping mode.
	 * @param string $policyId The SLA policy identity.
	 * @param array<int, string> $kinds The target kinds counted.
	 * @param array<string, mixed> $tracked The tracked object ([] when unknown).
	 * @param array<string, mixed> $event The breach event ([] for a met object).
	 * @param array<string, array<string, mixed>> $context Grouping context (see buildGroupingContext()).
	 *
	 * @return array<int, string> Group keys.
	 */
	private function groupKeys(
		string $groupBy,
		string $policyId,
		array $kinds,
		array $tracked,
		array $event,
		array $context,
	): array {
		$policyTier = (string)($context['policies'][$policyId]['customerTier'] ?? '');
		if ($policyTier === '*') {
			$policyTier = '';
		}

		return match ($groupBy) {
			'policy' => [$this->firstFilled(values: [$policyId])],
			'target' => $kinds,
			'tier' => [
				$this->firstFilled(
					values: [
						$event['customerTier'] ?? null,
						$tracked['slaTier'] ?? null,
						$tracked['customerTier'] ?? null,
						$policyTier,
					]
				),
			],
			'team' => [$this->firstFilled(values: [$event['team'] ?? null, $tracked['team'] ?? null])],
			'customer' => [$this->firstFilled(values: [$event['organisationId'] ?? null, $tracked['client'] ?? null])],
			default => ['all'],
		};
	}//end groupKeys()

	/**
	 * The first non-empty string, or `unspecified`.
	 *
	 * @param array<int, mixed> $values Candidates, in order of preference.
	 *
	 * @return string The value.
	 */
	private function firstFilled(array $values): string {
		foreach ($values as $value) {
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return 'unspecified';
	}//end firstFilled()

	/**
	 * The display name of a group: the policy's or client's name where the
	 * key is an id, the key itself otherwise.
	 *
	 * @param string $groupBy Grouping mode.
	 * @param string $key Group key.
	 * @param array<string, array<string, mixed>> $context Grouping context (see buildGroupingContext()).
	 *
	 * @return string Group display name.
	 */
	private function groupName(string $groupBy, string $key, array $context): string {
		$name = match ($groupBy) {
			'policy' => (string)($context['policies'][$key]['name'] ?? ''),
			'customer' => (string)($context['clients'][$key] ?? ''),
			default => '',
		};

		if ($name === '') {
			return $key;
		}

		return $name;
	}//end groupName()

	/**
	 * Normalise OR row/entity to a plain associative array. An entity's data
	 * leaves out its id, so the id is taken from its uuid.
	 *
	 * @param mixed $row Raw row.
	 *
	 * @return array<string, mixed> Normalised array.
	 */
	private function normalise($row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === false) {
			return [];
		}

		$data = null;
		if (method_exists($row, 'getObject') === true) {
			$data = $row->getObject();
		}

		if (is_array($data) === false && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
		}

		if (is_array($data) === false) {
			return [];
		}

		if (($data['id'] ?? '') === '' && method_exists($row, 'getUuid') === true) {
			$data['id'] = (string)$row->getUuid();
		}

		return $data;
	}//end normalise()
}//end class
