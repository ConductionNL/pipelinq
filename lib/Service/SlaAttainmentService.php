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

		$accumulated = $this->accumulateBreachedEvents(events: $events, policyFilter: $policyFilter, groupBy: $groupBy);

		// For attainment we need the closed-met denominator too — query
		// tracked objects with all targets met in the period.
		$metCounts = $this->countMetObjectsInRange(start: $start, end: $end, policyFilter: $policyFilter);
		$merged = $this->mergeMetCounts(accumulated: $accumulated, metCounts: $metCounts);

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
	 *
	 * @return array{total: int, breached: int, inFlight: int, closed: int, byTarget: array<string, mixed>, groupAccum: array<string, mixed>}
	 */
	private function accumulateBreachedEvents(array $events, string $policyFilter, string $groupBy): array {
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

			$groupKey = $this->groupKey(groupBy: $groupBy, event: $event);
			$groupName = $this->groupName(groupBy: $groupBy, event: $event, key: $groupKey);
			$groupAccum[$groupKey] = ($groupAccum[$groupKey] ?? ['name' => $groupName, 'total' => 0, 'breached' => 0]);
			$groupAccum[$groupKey]['total']++;
			$groupAccum[$groupKey]['breached']++;
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
	 * @param array<string, mixed> $metCounts Met-object counts (see countMetObjectsInRange()).
	 *
	 * @return array{total: int, met: int, byTarget: array<string, mixed>, groupAccum: array<string, mixed>}
	 */
	private function mergeMetCounts(array $accumulated, array $metCounts): array {
		$byTarget = $accumulated['byTarget'];
		$groupAccum = $accumulated['groupAccum'];

		foreach ($metCounts['byTarget'] as $kind => $count) {
			$byTarget[$kind] = ($byTarget[$kind] ?? ['breached' => 0, 'met' => 0]);
			$byTarget[$kind]['met'] = $count;
		}

		$met = $metCounts['total'];
		$total = $accumulated['total'] + $metCounts['total'];

		foreach ($metCounts['byGroup'] as $key => $entry) {
			$groupAccum[$key] = ($groupAccum[$key] ?? ['name' => $entry['name'], 'total' => 0, 'breached' => 0, 'met' => 0]);
			$groupAccum[$key]['total'] = ($groupAccum[$key]['total'] ?? 0) + $entry['total'];
			$groupAccum[$key]['met'] = ($groupAccum[$key]['met'] ?? 0) + $entry['total'];
		}

		return [
			'total' => $total,
			'met' => $met,
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
			$metCount = (int)$counts['met'];
			$denom = ($counts['breached'] + $metCount);
			$attainment = $this->ratio(numerator: $metCount, denominator: $denom);

			$byTargetOut[$kind] = [
				'attainment' => $attainment,
				'breached' => $counts['breached'],
				'met' => $metCount,
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
			$metE = (int)($entry['met'] ?? 0);
			$groupAttainment = $this->ratio(numerator: $metE, denominator: $denom);

			$byGroup[] = [
				'groupKey' => (string)$key,
				'groupName' => (string)($entry['name'] ?? $key),
				'attainment' => $groupAttainment,
				'total' => $denom,
				'met' => $metE,
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
			$rows = $objectService->findAll(
				config: [
					'register' => $register,
					'schema' => $schema,
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
	 * Count tracked objects that met all targets in the time range.
	 *
	 * Walks the SLA-tracked ticket subtypes (request + complaint, both on the
	 * unified `ticket` schema, narrowed with the `ticketType` discriminator)
	 * plus the callback schema, and picks the ones with
	 * slaStatus.targets[*].metAt in range and no breached/at-risk
	 * targets remaining.
	 *
	 * @param DateTimeInterface $start Start instant.
	 * @param DateTimeInterface $end End instant.
	 * @param string $policyFilter Optional policy identity filter.
	 *
	 * @return array{total: int, byTarget: array<string, int>, byGroup: array<string, array<string, mixed>>} Counts.
	 */
	private function countMetObjectsInRange(
		DateTimeInterface $start,
		DateTimeInterface $end,
		string $policyFilter,
	): array {
		$accumulator = ['total' => 0, 'byTarget' => [], 'byGroup' => []];

		// SLA-tracked ticket subtypes — one schema, two discriminator values.
		// findByType() is fail-soft: it yields [] when the ticket surface is
		// unprovisioned or OpenRegister is unavailable.
		foreach ([TicketService::TYPE_REQUEST, TicketService::TYPE_COMPLAINT] as $ticketType) {
			$rows = $this->ticketService->findByType($ticketType, [], 5000);
			$accumulator = $this->accumulateMetRows(
				rows: $rows,
				accumulator: $accumulator,
				start: $start,
				end: $end,
				policyFilter: $policyFilter
			);
		}

		$accumulator = $this->accumulateMetRows(
			rows: $this->fetchCallbackRows(),
			accumulator: $accumulator,
			start: $start,
			end: $end,
			policyFilter: $policyFilter
		);

		return $accumulator;
	}//end countMetObjectsInRange()

	/**
	 * Fold a batch of tracked-object rows into the met-object accumulator.
	 *
	 * @param array<int, mixed> $rows Tracked-object rows.
	 * @param array<string, mixed> $accumulator Running counts.
	 * @param DateTimeInterface $start Start instant.
	 * @param DateTimeInterface $end End instant.
	 * @param string $policyFilter Optional policy identity filter.
	 *
	 * @return array{total: int, byTarget: array<string, int>, byGroup: array<string, array<string, mixed>>} Counts.
	 */
	private function accumulateMetRows(
		array $rows,
		array $accumulator,
		DateTimeInterface $start,
		DateTimeInterface $end,
		string $policyFilter,
	): array {
		foreach ($rows as $row) {
			$result = $this->evaluateTrackedObjectRow(row: $row, start: $start, end: $end, policyFilter: $policyFilter);
			if ($result === null) {
				continue;
			}

			$accumulator['total']++;
			foreach ($result['byTarget'] as $kind => $count) {
				$accumulator['byTarget'][$kind] = ($accumulator['byTarget'][$kind] ?? 0) + $count;
			}

			$key = $result['groupKey'];

			$accumulator['byGroup'][$key] = ($accumulator['byGroup'][$key] ?? ['name' => $key, 'total' => 0]);
			$accumulator['byGroup'][$key]['total']++;
		}//end foreach

		return $accumulator;
	}//end accumulateMetRows()

	/**
	 * Fetch the callback rows (callbacks keep their own schema).
	 *
	 * @return array<int, mixed> Callback rows ([] when unconfigured/unavailable).
	 */
	private function fetchCallbackRows(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'callback_schema', '');
		if ($register === '' || $schemaId === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			return [];
		}

		return $this->fetchTrackedObjectRows(objectService: $objectService, register: $register, schemaId: $schemaId);
	}//end fetchCallbackRows()

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
			$rows = $objectService->findAll(
				config: [
					'register' => $register,
					'schema' => $schemaId,
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
	 * @return array{byTarget: array<string, int>, groupKey: string}|null Null when filtered out or not fully-met-in-range.
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
			'groupKey' => (string)($slaStatus['policyId'] ?? 'unknown'),
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
		$allMet = true;
		$touchedKey = false;
		$byTarget = [];
		foreach (($slaStatus['targets'] ?? []) as $target) {
			$status = (string)($target['status'] ?? '');
			if ($status !== SlaEngineService::STATUS_MET) {
				$allMet = false;
				continue;
			}

			$metAt = $target['metAt'] ?? '';
			if ($metAt === '') {
				continue;
			}

			try {
				$metInstant = new DateTimeImmutable((string)$metAt);
			} catch (Throwable $e) {
				continue;
			}

			if ($metInstant >= $start && $metInstant < $end) {
				$touchedKey = true;
				$kind = (string)($target['kind'] ?? 'resolution');
				$byTarget[$kind] = ($byTarget[$kind] ?? 0) + 1;
			}
		}//end foreach

		return ['allMet' => $allMet, 'touchedKey' => $touchedKey, 'byTarget' => $byTarget];
	}//end evaluateSlaTargets()

	/**
	 * Derive a group key for an event according to the requested grouping.
	 *
	 * @param string $groupBy Grouping mode.
	 * @param array<string, mixed> $event Breach event.
	 *
	 * @return string Group key.
	 */
	private function groupKey(string $groupBy, array $event): string {
		return match ($groupBy) {
			'policy' => (string)($event['policyId'] ?? 'unknown'),
			'tier' => (string)($event['customerTier'] ?? 'unspecified'),
			'team' => (string)($event['team'] ?? 'unspecified'),
			'customer' => (string)($event['organisationId'] ?? 'unspecified'),
			'target' => (string)($event['targetKind'] ?? 'resolution'),
			default => 'all',
		};
	}//end groupKey()

	/**
	 * Derive a human-readable name for a group key.
	 *
	 * @param string $groupBy Grouping mode.
	 * @param array<string, mixed> $event Breach event.
	 * @param string $key Resolved group key.
	 *
	 * @return string Group display name.
	 */
	private function groupName(string $groupBy, array $event, string $key): string {
		unset($groupBy, $event);
		return $key;
	}//end groupName()

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
