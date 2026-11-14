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
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\SlaAttainmentServiceInterface;
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
class SlaAttainmentService implements SlaAttainmentServiceInterface {
	public const VALID_BUCKETS = ['day', 'week', 'month', 'quarter'];

	public const VALID_GROUPS = ['policy', 'customer', 'tier', 'team', 'target'];

	/**
	 * The filter options compute() validates: their default, the valid values
	 * and the error a wrong value raises.
	 *
	 * @var array<string, array{default: string, allowed: array<int, string>, error: string}>
	 */
	private const OPTIONS = [
		'bucket' => ['default' => 'month', 'allowed' => self::VALID_BUCKETS, 'error' => 'invalidBucket'],
		'groupBy' => ['default' => 'policy', 'allowed' => self::VALID_GROUPS, 'error' => 'invalidGroupBy'],
	];

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
		$bucket = self::validOption(params: $params, key: 'bucket');
		$groupBy = self::validOption(params: $params, key: 'groupBy');

		[$start, $end] = $this->resolveBucketRange(bucket: $bucket, params: $params);
		$events = $this->loadBreachEventsInRange(range: [$start, $end]);
		$policyFilter = (string)($params['policy'] ?? '');

		// Met objects and breach events are grouped from the same tracked
		// objects, so a group's met and breached counts describe one set.
		$trackedRows = $this->loadTrackedRows();
		$context = $this->buildGroupingContext(groupBy: $groupBy, trackedRows: $trackedRows);
		$context += ['groupBy' => $groupBy, 'start' => $start, 'end' => $end, 'policyFilter' => $policyFilter];

		$accumulated = $this->accumulateBreachedEvents(events: $events, context: $context);

		// For attainment we need the closed-met denominator too — the tracked
		// objects with all targets met in the period.
		$withCounts = $this->countWithObjectsInRange(rows: $trackedRows, context: $context);
		$merged = $this->mergeWithCounts(accumulated: $accumulated, withCounts: $withCounts);

		$figures = new SlaAttainmentFigures();
		return $figures->counts(merged: $merged, accumulated: $accumulated)
			+ ['range' => $figures->range(start: $start, end: $end), 'details' => $figures->details(merged: $merged)];
	}//end compute()

	/**
	 * A requested option, its default when absent; refused when not one of the valid ones.
	 *
	 * @param array<string, mixed> $params The filter parameters.
	 * @param string               $key    `bucket` or `groupBy`.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the option is not valid.
	 */
	private static function validOption(array $params, string $key): string {
		$option = self::OPTIONS[$key];
		$value = (string)($params[$key] ?? $option['default']);
		if (in_array($value, $option['allowed'], true) === false) {
			throw new InvalidArgumentException($option['error']);
		}

		return $value;
	}//end validOption()

	/**
	 * Walk the breach events and accumulate per-target/per-group breach counts.
	 *
	 * @param array<int, array<string, mixed>> $events Breach events.
	 * @param array<string, mixed> $context Run context (see compute()).
	 *
	 * @return array{total: int, breached: int, inFlight: int, closed: int, byTarget: array<string, mixed>, groupAccum: array<string, mixed>}
	 */
	private function accumulateBreachedEvents(array $events, array $context): array {
		$tally = new BreachTally(context: $context, grouping: new SlaGrouping(context: $context));
		foreach ($events as $event) {
			if ($context['policyFilter'] !== '' && (string)($event['policyId'] ?? '') !== $context['policyFilter']) {
				continue;
			}

			$tally->add(event: $event);
		}

		return $tally->summary();
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
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		return match ($bucket) {
			'day' => $this->resolveDayRange(now: $now, params: $params),
			'week' => $this->resolveWeekRange(now: $now, params: $params),
			'month' => $this->resolveMonthRange(now: $now, params: $params),
			'quarter' => $this->resolveQuarterRange(now: $now, params: $params),
			default => throw new InvalidArgumentException('invalidBucket'),
		};
	}//end resolveBucketRange()

	/**
	 * Resolve the (start, end) range for the "day" bucket.
	 *
	 * @param DateTimeImmutable $now Current instant, in the working timezone.
	 * @param array<string, mixed> $params Filter parameters.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @throws InvalidArgumentException On an invalid date value.
	 */
	private function resolveDayRange(DateTimeImmutable $now, array $params): array {
		// An empty/missing date defaults to today so a declarative
		// dashboard can drive the bucket select alone (no client-side
		// date math); an explicitly supplied value must still be valid.
		$date = (string)($params['date'] ?? '');
		if ($date === '') {
			$date = $now->format('Y-m-d');
		} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			throw new InvalidArgumentException('invalidDate');
		}

		$start = new DateTimeImmutable($date . ' 00:00:00', $now->getTimezone());
		return [$start, $start->modify('+1 day')];
	}//end resolveDayRange()

	/**
	 * Resolve the (start, end) range for the "week" bucket.
	 *
	 * @param DateTimeImmutable $now Current instant, in the working timezone.
	 * @param array<string, mixed> $params Filter parameters.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @throws InvalidArgumentException On an invalid week value.
	 */
	private function resolveWeekRange(DateTimeImmutable $now, array $params): array {
		$week = (string)($params['week'] ?? '');
		if ($week === '') {
			$week = $now->format('o-\WW');
		}

		if (preg_match('/^(\d{4})-W(\d{1,2})$/', $week, $matches) !== 1) {
			throw new InvalidArgumentException('invalidWeek');
		}

		$start = (new DateTimeImmutable('now', $now->getTimezone()))
			->setISODate((int)$matches[1], (int)$matches[2])
			->setTime(0, 0);
		return [$start, $start->modify('+7 days')];
	}//end resolveWeekRange()

	/**
	 * Resolve the (start, end) range for the "month" bucket.
	 *
	 * @param DateTimeImmutable $now Current instant, in the working timezone.
	 * @param array<string, mixed> $params Filter parameters.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @throws InvalidArgumentException On an invalid month value.
	 */
	private function resolveMonthRange(DateTimeImmutable $now, array $params): array {
		$month = (string)($params['month'] ?? '');
		if ($month === '') {
			$month = $now->format('Y-m');
		}

		if (preg_match('/^(\d{4})-(\d{2})$/', $month) !== 1) {
			throw new InvalidArgumentException('invalidMonth');
		}

		$start = new DateTimeImmutable($month . '-01 00:00:00', $now->getTimezone());
		return [$start, $start->modify('+1 month')];
	}//end resolveMonthRange()

	/**
	 * Resolve the (start, end) range for the "quarter" bucket.
	 *
	 * @param DateTimeImmutable $now Current instant, in the working timezone.
	 * @param array<string, mixed> $params Filter parameters.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @throws InvalidArgumentException On an invalid quarter value.
	 */
	private function resolveQuarterRange(DateTimeImmutable $now, array $params): array {
		$quarter = (string)($params['quarter'] ?? '');
		if ($quarter === '') {
			$quarter = sprintf('%s-Q%d', $now->format('Y'), (int)ceil(((int)$now->format('n')) / 3));
		}

		if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $matches) !== 1) {
			throw new InvalidArgumentException('invalidQuarter');
		}

		$month = (((int)$matches[2] - 1) * 3) + 1;
		$start = new DateTimeImmutable(sprintf('%s-%02d-01 00:00:00', $matches[1], $month), $now->getTimezone());
		return [$start, $start->modify('+3 months')];
	}//end resolveQuarterRange()

	/**
	 * Load breach-event records whose breachedAt is in the time range.
	 *
	 * @param array{0: DateTimeInterface, 1: DateTimeInterface} $range Start instant and end instant (exclusive).
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private function loadBreachEventsInRange(array $range): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'sla_register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'sla_breach_event_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$objectService = $this->breachObjectService();
		if ($objectService === null) {
			return [];
		}

		$events = [];
		foreach ($this->breachRows(objectService: $objectService, filters: ['register' => $register, 'schema' => $schema]) as $row) {
			$event = $this->parseBreachEventRow(row: $row, range: $range);
			if ($event !== null) {
				$events[] = $event;
			}
		}

		return $events;
	}//end loadBreachEventsInRange()

	/**
	 * The OpenRegister object service; null (logged) when it is not available.
	 *
	 * @return object|null
	 */
	private function breachObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaAttainmentService: ObjectService not available',
				['error' => $e->getMessage()]
			);
			return null;
		}
	}//end breachObjectService()

	/**
	 * Every breach-event row of the configured schema; none (logged) when the read fails.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param array<string, mixed> $filters       The register and schema.
	 *
	 * @return array<int|string, mixed>
	 */
	private function breachRows(object $objectService, array $filters): array {
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
					'filters' => $filters,
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

		return (array)$rows;
	}//end breachRows()

	/**
	 * Parse and range-filter a single breach-event row.
	 *
	 * @param mixed $row Raw row.
	 * @param array{0: DateTimeInterface, 1: DateTimeInterface} $range Start instant and end instant (exclusive).
	 *
	 * @return array<string, mixed>|null The normalised event, or null when out of range/unparsable.
	 */
	private function parseBreachEventRow(mixed $row, array $range): ?array {
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

		if ($instant < $range[0] || $instant >= $range[1]) {
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
	 * @param array<string, mixed> $context Run context (see compute()).
	 *
	 * @return array{total: int, byTarget: array<string, int>, byGroup: array<string, array<string, mixed>>} Counts.
	 */
	private function countWithObjectsInRange(array $rows, array $context): array {
		$accumulator = ['total' => 0, 'byTarget' => [], 'byGroup' => []];
		$grouping = new SlaGrouping(context: $context);

		foreach ($rows as $row) {
			$result = $this->evaluateTrackedObjectRow(row: $row, context: $context);
			if ($result === null) {
				continue;
			}

			$accumulator['total']++;
			foreach ($result['byTarget'] as $kind => $count) {
				$accumulator['byTarget'][$kind] = ($accumulator['byTarget'][$kind] ?? 0) + $count;
			}

			$subject = ['policyId' => $result['policyId'], 'kinds' => array_keys($result['byTarget']), 'tracked' => $row, 'event' => []];
			foreach ($grouping->keysFor(subject: $subject) as $key) {
				$name = $grouping->nameOf(key: $key);
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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, $registerKey, '');
		$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($register === '' || $schemaId === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			return [];
		}

		return $this->fetchTrackedObjectRows(objectService: $objectService, filters: ['register' => $register, 'schema' => $schemaId]);
	}//end findConfiguredObjects()

	/**
	 * Fetch tracked-object rows for a schema, tolerating findAll failures.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array{register: string, schema: string} $filters The register and schema identifiers.
	 *
	 * @return array<int, mixed>
	 */
	private function fetchTrackedObjectRows(object $objectService, array $filters): array {
		try {
			// Same shape as loadBreachEventsInRange(): register/schema belong
			// inside `filters`, or the query resolves no context and returns [].
			$rows = $objectService->findAll(config: ['filters' => $filters, 'limit' => 5000]);
		} catch (Throwable $e) {
			return [];
		}

		return (array)$rows;
	}//end fetchTrackedObjectRows()

	/**
	 * Evaluate whether a tracked-object row is counted for attainment.
	 *
	 * @param mixed $row Raw row.
	 * @param array<string, mixed> $context Run context (see compute()).
	 *
	 * @return array{byTarget: array<string, int>, policyId: string}|null Null when filtered out or not fully-met-in-range.
	 */
	private function evaluateTrackedObjectRow(mixed $row, array $context): ?array {
		$array = $this->normalise(row: $row);
		$slaStatus = $array['slaStatus'] ?? null;
		if (is_array($slaStatus) === false) {
			return null;
		}

		if ($context['policyFilter'] !== '' && (string)($slaStatus['policyId'] ?? '') !== $context['policyFilter']) {
			return null;
		}

		$targets = $this->evaluateSlaTargets(slaStatus: $slaStatus, context: $context);
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
	 * @param array<string, mixed> $context Run context (see compute()); its start and end bound the range.
	 *
	 * @return array{allMet: bool, touchedKey: bool, byTarget: array<string, int>}
	 */
	private function evaluateSlaTargets(array $slaStatus, array $context): array {
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

			if ($withInstant >= $context['start'] && $withInstant < $context['end']) {
				$touchedKey = true;
				$kind = (string)($target['kind'] ?? 'resolution');
				$byTarget[$kind] = ($byTarget[$kind] ?? 0) + 1;
			}
		}//end foreach

		return ['allMet' => $allWith, 'touchedKey' => $touchedKey, 'byTarget' => $byTarget];
	}//end evaluateSlaTargets()

	/**
	 * Normalise OR row/entity to a plain associative array.
	 *
	 * @param mixed $row Raw row.
	 *
	 * @return array<string, mixed> Normalised array.
	 */
	private function normalise($row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$object = $row->getObject();
			if (is_array($object) === true) {
				return $object;
			}
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$json = $row->jsonSerialize();
			if (is_array($json) === true) {
				return $json;
			}

			return [];
		}

		return [];
	}//end normalise()
}//end class
