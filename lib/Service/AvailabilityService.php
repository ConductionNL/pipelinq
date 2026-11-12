<?php

/**
 * Pipelinq AvailabilityService.
 *
 * Slot computation for the appointment-booking surface (member 02 of 12).
 * Intersects working hours, vacations, existing bookings and (a seam for)
 * calendar-synced blocks into 15-minute-aligned free slots, backed by the
 * availability-cache schema declared in member 01.
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
 * @spec openspec/specs/appointment-booking/spec.md
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Util\NullablePayloadToArray;
use OCA\Pipelinq\Util\RowIdOf;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Compute per-resource per-day availability as 15-minute-aligned free blocks.
 *
 * The service is pure-computation: it pulls Resource working hours / vacations
 * and overlapping Booking objects from OpenRegister via the shared ObjectService
 * facade, applies Service buffers, optionally merges externally provided
 * calendar blocks (member 10 seam), aligns the result to 15-minute boundaries
 * and writes the outcome to the availability-cache schema with a 24-hour TTL.
 *
 * All public methods carry an `@spec` PHPDoc tag pointing at REQ-APT-003 in the
 * delta spec. ObjectService is reached via a DI lookup so the openregister app
 * stays an optional runtime dependency (ADR-015 facade). Calendar fetching is
 * behind a seam — the calendar provider is injected by member 10 and is null in
 * this slice; an empty list is returned without an external call.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Cohesive availability engine; splitting would fragment slot/cache logic.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Slot computation over many working-hour/booking/calendar dimensions is inherently complex.
 * @SuppressWarnings(PHPMD.TooManyMethods)          The engine owns its cache, so it also expires it per day, per resource and per resource type.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 * @spec openspec/specs/appointment-booking/spec.md
 */
class AvailabilityService {
	use RowIdOf;
	use NullablePayloadToArray;

	/**
	 * Slot alignment in minutes.
	 *
	 * @var int
	 */
	public const SLOT_INTERVAL_MINUTES = 15;

	/**
	 * Cache TTL in seconds (24 hours).
	 *
	 * @var int
	 */
	public const CACHE_TTL_SECONDS = 86400;

	/**
	 * App-config key for the Resource schema id/slug.
	 *
	 * @var string
	 */
	public const RESOURCE_SCHEMA_KEY = 'resource_schema';

	/**
	 * App-config key for the Booking schema id/slug.
	 *
	 * @var string
	 */
	public const BOOKING_SCHEMA_KEY = 'booking_schema';

	/**
	 * App-config key for the Service schema id/slug.
	 *
	 * @var string
	 */
	public const SERVICE_SCHEMA_KEY = 'service_schema';

	/**
	 * App-config key for the AvailabilityCache schema id/slug.
	 *
	 * @var string
	 */
	public const AVAILABILITY_CACHE_SCHEMA_KEY = 'availability_cache_schema';

	/**
	 * Optional calendar provider for blocked-time fetches (member 10 seam).
	 *
	 * Must implement `getBlockedTimes(string $resourceId, string $date): array`
	 * returning a list of `['startTime' => 'HH:MM', 'endTime' => 'HH:MM']`.
	 *
	 * @var object|null
	 */
	private ?object $calendarProvider = null;

	/**
	 * In-request L1 cache for Resource lookups (short-lived, lazy).
	 *
	 * Keyed by resource id; resolved through `ICacheFactory::createLocal()` on
	 * first use so unit tests can mock the factory without forcing an APCu
	 * dependency at construction time.
	 *
	 * @var ICache|null
	 */
	private ?ICache $resourceCache = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ICacheFactory $cacheFactory For the per-request resource L1 cache.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Inject a calendar provider for external blocked-time merges.
	 *
	 * Called by member 10 (calendar-sync) at runtime. When unset, no calendar
	 * blocks are merged — the in-register Booking blocks are the only source.
	 *
	 * @param object|null $provider Object exposing `getBlockedTimes(string, string): array`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function setCalendarProvider(?object $provider): void {
		$this->calendarProvider = $provider;
	}//end setCalendarProvider()

	/**
	 * Compute 15-minute-aligned free slots for a resource on a date.
	 *
	 * Intersects Resource working hours with vacations + overlapping Bookings +
	 * external calendar blocks, applies per-Service buffers and returns only
	 * slots whose start time leaves room for the full duration.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date ISO date `YYYY-MM-DD`.
	 * @param int $serviceDurationMinutes Service duration in minutes.
	 *
	 * @return array<int, array{startTime: string, endTime: string, durationMinutes: int}>
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) $serviceDurationMinutes is a public named-argument param; renaming breaks callers.
	 */
	public function computeAvailability(string $resourceId, string $date, int $serviceDurationMinutes): array {
		if ($resourceId === '' || $date === '' || $serviceDurationMinutes <= 0) {
			return [];
		}

		$weekDay = $this->weekDayFor(date: $date);
		$hours = $this->getWorkingHours(resourceId: $resourceId, weekDay: $weekDay);
		if ($hours === null) {
			return [];
		}

		$blocked = $this->getBlockedTimes(resourceId: $resourceId, date: $date);
		$aligned = $this->alignToSlots(
			startTime: $hours['openTime'],
			endTime: $hours['closeTime'],
			intervalMinutes: self::SLOT_INTERVAL_MINUTES
		);

		$free = [];
		foreach ($aligned as $slotStartMin) {
			$slotEndMin = ($slotStartMin + $serviceDurationMinutes);
			if ($slotEndMin > $this->minutesFromHHMM(time: $hours['closeTime'])) {
				continue;
			}

			if ($this->overlapsAny(startMin: $slotStartMin, endMin: $slotEndMin, blocks: $blocked) === true) {
				continue;
			}

			$free[] = [
				'startTime' => $this->minutesToHHMM(minutes: $slotStartMin),
				'endTime' => $this->minutesToHHMM(minutes: $slotEndMin),
				'durationMinutes' => $serviceDurationMinutes,
			];
		}

		return $free;
	}//end computeAvailability()

	/**
	 * Resolve the Resource's working hours for the given week day.
	 *
	 * Reads `workingHours` from the resource entity, which is expected to be a
	 * list of `{day, openTime, closeTime}` objects. Returns `null` when the
	 * resource is closed on that day or the resource cannot be resolved.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $weekDay Lower-case English weekday (`monday`..`sunday`).
	 *
	 * @return array{openTime: string, closeTime: string}|null
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function getWorkingHours(string $resourceId, string $weekDay): ?array {
		$resource = $this->loadResource(resourceId: $resourceId);
		if ($resource === null || is_array($resource['workingHours'] ?? []) === false) {
			return null;
		}

		$entry = $this->workingDayEntry(entries: ($resource['workingHours'] ?? []), weekDay: $weekDay);
		if ($entry === null) {
			return null;
		}

		return $this->openingHours(entry: $entry);
	}//end getWorkingHours()

	/**
	 * The first working-hours entry for a week day.
	 *
	 * @param array<int|string, mixed> $entries The resource's `workingHours`.
	 * @param string                   $weekDay The week day asked, in any case.
	 *
	 * @return array<string, mixed>|null
	 */
	private function workingDayEntry(array $entries, string $weekDay): ?array {
		$needle = strtolower(trim($weekDay));
		foreach ($entries as $entry) {
			if (is_array($entry) === true && strtolower((string)($entry['day'] ?? '')) === $needle) {
				return $entry;
			}
		}

		return null;
	}//end workingDayEntry()

	/**
	 * The opening and closing time of a working-hours entry.
	 *
	 * @param array<string, mixed> $entry A working-hours entry.
	 *
	 * @return array{openTime: string, closeTime: string}|null Null when either time is missing.
	 */
	private function openingHours(array $entry): ?array {
		$open = (string)($entry['openTime'] ?? '');
		$close = (string)($entry['closeTime'] ?? '');
		if ($open === '' || $close === '') {
			return null;
		}

		return [
			'openTime' => $open,
			'closeTime' => $close,
		];
	}//end openingHours()

	/**
	 * Merge blocked-time ranges for the given resource on the given date.
	 *
	 * Sources:
	 *   1. Resource vacations (full-day blocks for any date within range).
	 *   2. Overlapping Booking objects, expanded by Service `bufferBefore` /
	 *      `bufferAfter`.
	 *   3. External calendar blocks (member 10 seam — empty when no provider).
	 *
	 * Each returned block is `['startMin' => int, 'endMin' => int]` in minutes
	 * from midnight local time. Blocks are NOT merged/normalised — overlap
	 * detection is O(n) and tolerates duplicates.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date ISO date `YYYY-MM-DD`.
	 *
	 * @return array<int, array{startMin: int, endMin: int}>
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function getBlockedTimes(string $resourceId, string $date): array {
		$resource = $this->loadResource(resourceId: $resourceId);
		if ($resource !== null && $this->isOnVacation(resource: $resource, date: $date) === true) {
			// Full-day vacation: block the entire 24h window.
			return [['startMin' => 0, 'endMin' => (24 * 60)]];
		}

		return array_merge(
			$this->bookingBlocks(resourceId: $resourceId, date: $date),
			$this->calendarBlockRanges(resourceId: $resourceId, date: $date)
		);
	}//end getBlockedTimes()

	/**
	 * The blocks the resource's bookings on a date occupy, buffers included.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date       ISO date `YYYY-MM-DD`.
	 *
	 * @return array<int, array{startMin: int, endMin: int}>
	 */
	private function bookingBlocks(string $resourceId, string $date): array {
		$blocks = [];
		foreach ($this->loadOverlappingBookings(resourceId: $resourceId, date: $date) as $booking) {
			$block = $this->bookingBlock(booking: $booking, date: $date);
			if ($block !== null) {
				$blocks[] = $block;
			}
		}

		return $blocks;
	}//end bookingBlocks()

	/**
	 * The block one booking occupies on a date, widened by its Service's buffers.
	 *
	 * @param array<string, mixed> $booking The booking.
	 * @param string               $date    ISO date `YYYY-MM-DD`.
	 *
	 * @return array{startMin: int, endMin: int}|null Null when the booking has no window or the block is empty.
	 */
	private function bookingBlock(array $booking, string $date): ?array {
		$startAt = (string)($booking['startAt'] ?? '');
		$endAt = (string)($booking['endAt'] ?? '');
		if ($startAt === '' || $endAt === '') {
			return null;
		}

		$buffers = $this->buffersFor(serviceId: (string)($booking['serviceId'] ?? ''));
		$startMin = $this->clampToDay(value: ($this->minutesOfDay(iso: $startAt, date: $date) - $buffers['before']));
		$endMin = $this->clampToDay(value: ($this->minutesOfDay(iso: $endAt, date: $date) + $buffers['after']));

		return $this->blockOrNull(startMin: $startMin, endMin: $endMin);
	}//end bookingBlock()

	/**
	 * The blocks the external calendar reports for a resource on a date.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date       ISO date `YYYY-MM-DD`.
	 *
	 * @return array<int, array{startMin: int, endMin: int}>
	 */
	private function calendarBlockRanges(string $resourceId, string $date): array {
		$blocks = [];
		foreach ($this->fetchCalendarBlocks(resourceId: $resourceId, date: $date) as $cal) {
			$block = $this->blockOrNull(
				startMin: $this->minutesFromHHMM(time: $cal['startTime']),
				endMin: $this->minutesFromHHMM(time: $cal['endTime'])
			);
			if ($block !== null) {
				$blocks[] = $block;
			}
		}

		return $blocks;
	}//end calendarBlockRanges()

	/**
	 * A block, or null when it ends at or before its start.
	 *
	 * @param int $startMin Start, minutes from midnight.
	 * @param int $endMin   End, minutes from midnight.
	 *
	 * @return array{startMin: int, endMin: int}|null
	 */
	private function blockOrNull(int $startMin, int $endMin): ?array {
		if ($endMin <= $startMin) {
			return null;
		}

		return ['startMin' => $startMin, 'endMin' => $endMin];
	}//end blockOrNull()

	/**
	 * Split an `HH:MM` time range into a sorted list of slot start offsets.
	 *
	 * Returns minutes-from-midnight, e.g. `[540, 555, 570, ...]` for 09:00,
	 * 09:15, 09:30 in a 15-minute interval. The returned list contains EVERY
	 * boundary within `[startTime, endTime)`; the caller filters out boundaries
	 * where the service duration would not fit.
	 *
	 * @param string $startTime `HH:MM` start (inclusive).
	 * @param string $endTime `HH:MM` end (exclusive).
	 * @param int $intervalMinutes Boundary alignment, e.g. 15.
	 *
	 * @return array<int, int> Slot start offsets in minutes from midnight.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function alignToSlots(string $startTime, string $endTime, int $intervalMinutes): array {
		if ($intervalMinutes <= 0) {
			return [];
		}

		$start = $this->minutesFromHHMM(time: $startTime);
		$end = $this->minutesFromHHMM(time: $endTime);
		if ($end <= $start) {
			return [];
		}

		// Round the first boundary UP to the next alignment, then step.
		$first = (int)(ceil($start / $intervalMinutes) * $intervalMinutes);
		$slots = [];
		for ($cursor = $first; $cursor < $end; $cursor += $intervalMinutes) {
			$slots[] = $cursor;
		}

		return $slots;
	}//end alignToSlots()

	/**
	 * Delete the availability-cache entry for a resource on a date.
	 *
	 * Idempotent: a missing cache row is a no-op.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date ISO date `YYYY-MM-DD`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function invalidateCache(string $resourceId, string $date): void {
		$entry = $this->loadCacheEntry(resourceId: $resourceId, date: $date);
		if ($entry === null) {
			return;
		}

		$uuid = $this->idOf(object: $entry);
		if ($uuid === '') {
			return;
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::AVAILABILITY_CACHE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$this->getObjectService()->deleteObject(
				uuid: $uuid,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: availability cache invalidation failed',
				['resource' => $resourceId, 'date' => $date]
			);
		}
	}//end invalidateCache()

	/**
	 * Delete every availability-cache row of a resource, whatever its date.
	 *
	 * Runs on the server for every writer of the resource (the UI, the API, an
	 * import): its hours, vacations or type changed, so no cached day is still
	 * true. A row that cannot be deleted is logged; the hourly refresh job
	 * converges it.
	 *
	 * @param string $resourceId Resource UUID.
	 *
	 * @return int How many rows were deleted.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
	 */
	public function invalidateResource(string $resourceId): int {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::AVAILABILITY_CACHE_SCHEMA_KEY);
		if ($resourceId === '' || $register === '' || $schema === '') {
			return 0;
		}

		$deleted = 0;
		foreach ($this->findRows(schema: $schema, filters: ['resourceId' => $resourceId]) as $row) {
			$uuid = $this->idOf(object: $row);
			if ($uuid === '') {
				continue;
			}

			try {
				$this->getObjectService()->deleteObject(uuid: $uuid, register: $register, schema: $schema);
				$deleted++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: availability cache row could not be deleted',
					['resource' => $resourceId, 'row' => $uuid, 'exception' => $e->getMessage()]
				);
			}
		}

		return $deleted;
	}//end invalidateResource()

	/**
	 * Delete the cached availability of every resource a service can run on:
	 * the resources whose `type` is one of the service's required resource
	 * types, or every resource when the service names none.
	 *
	 * @param array<int, string> $resourceTypes The service's `requiredResourceTypes`.
	 *
	 * @return int How many rows were deleted.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
	 */
	public function invalidateForResourceTypes(array $resourceTypes): int {
		$schema = $this->schemaId(key: self::RESOURCE_SCHEMA_KEY);
		if ($schema === '') {
			return 0;
		}

		$types = array_values(array_filter($resourceTypes, static fn ($type): bool => is_string($type) === true && $type !== ''));
		$deleted = 0;
		foreach ($this->findRows(schema: $schema, filters: []) as $resource) {
			if ($types !== [] && in_array(needle: ($resource['type'] ?? null), haystack: $types, strict: true) === false) {
				continue;
			}

			$deleted += $this->invalidateResource(resourceId: $this->idOf(object: $resource));
		}

		return $deleted;
	}//end invalidateForResourceTypes()

	/**
	 * The objects of a pipelinq schema that match simple equality filters,
	 * as arrays. A failed read is logged and answers none.
	 *
	 * @param string $schema The schema id.
	 * @param array<string, string> $filters Equality filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findRows(string $schema, array $filters): array {
		$register = $this->registerId();
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters)]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: availability lookup failed',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$data = $this->toArray(object: $row);
			if ($data !== null) {
				$out[] = $data;
			}
		}

		return $out;
	}//end findRows()

	/**
	 * Read the cached free-block list, regenerating + persisting when missing.
	 *
	 * Stale entries (past `expiresAt`) are still returned but flagged stale —
	 * the caller can choose to refresh on the next mutation event. When the
	 * AvailabilityCache schema is not configured the cache step is bypassed and
	 * a fresh computation is returned.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date ISO date `YYYY-MM-DD`.
	 * @param int|null $serviceDurationMinutes Optional duration to constrain free blocks; defaults to 15.
	 *
	 * @return array<string, mixed> A `{freeBlocks, generatedAt, expiresAt, stale}` cache snapshot.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) $serviceDurationMinutes is a public named-argument param; renaming breaks callers.
	 */
	public function getOrComputeCache(string $resourceId, string $date, ?int $serviceDurationMinutes = null): array {
		$duration = ($serviceDurationMinutes ?? self::SLOT_INTERVAL_MINUTES);
		$entry = $this->loadCacheEntry(resourceId: $resourceId, date: $date);
		$nowIso = $this->nowIso();

		if ($entry !== null) {
			$expiresAt = (string)($entry['expiresAt'] ?? '');
			$isStale = ($expiresAt === '' || strtotime($expiresAt) < strtotime($nowIso));
			return [
				'freeBlocks' => $this->normaliseFreeBlocks(blocks: ($entry['freeBlocks'] ?? [])),
				'generatedAt' => (string)($entry['generatedAt'] ?? ''),
				'expiresAt' => $expiresAt,
				'stale' => $isStale,
			];
		}

		$free = $this->computeAvailability(
			resourceId: $resourceId,
			date: $date,
			serviceDurationMinutes: $duration
		);
		$entry = [
			'resourceId' => $resourceId,
			'date' => $date,
			'freeBlocks' => $free,
			'generatedAt' => $nowIso,
			'expiresAt' => $this->isoOffsetSeconds(seconds: self::CACHE_TTL_SECONDS),
		];
		$this->persistCacheEntry(entry: $entry);

		return array_diff_key($entry, ['resourceId' => true, 'date' => true]) + ['stale' => false];
	}//end getOrComputeCache()

	/**
	 * Load a Resource by id/slug, scoped to the pipelinq register/schema.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadResource(string $resourceId): ?array {
		if ($resourceId === '') {
			return null;
		}

		$cache = $this->resourceCache();
		if ($cache !== null) {
			$hit = $cache->get('res:' . $resourceId);
			if (is_array($hit) === true) {
				return $hit;
			}
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::RESOURCE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$result = $this->getObjectService()->find(
				id: $resourceId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: resource load failed',
				['resource' => $resourceId]
			);
			return null;
		}

		$normalised = $this->toArray(object: $result);
		if ($cache !== null && $normalised !== null) {
			$cache->set('res:' . $resourceId, $normalised, 60);
		}

		return $normalised;
	}//end loadResource()

	/**
	 * Lazily resolve a per-request L1 cache for resource lookups.
	 *
	 * Returns null when the cache backend is unavailable — callers tolerate
	 * a miss and re-fetch from OpenRegister.
	 *
	 * @return ICache|null
	 */
	private function resourceCache(): ?ICache {
		if ($this->resourceCache !== null) {
			return $this->resourceCache;
		}

		try {
			$this->resourceCache = $this->cacheFactory->createLocal('pipelinq_availability');
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: availability L1 cache unavailable');
			return null;
		}

		return $this->resourceCache;
	}//end resourceCache()

	/**
	 * Find Booking objects that overlap the given date for the resource.
	 *
	 * Uses `findAll(config:filters)` with simple equality filters. Server-side
	 * scoping reduces row count; per-booking date overlap is reconfirmed
	 * client-side because OpenRegister's filter DSL is equality-only.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date ISO date `YYYY-MM-DD`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadOverlappingBookings(string $resourceId, string $date): array {
		$matches = [];
		foreach ($this->findBookingRows(resourceId: $resourceId, date: $date) as $row) {
			$booking = $this->toArray(object: $row);
			if ($booking !== null && $this->overlapsDay(booking: $booking, date: $date) === true) {
				$matches[] = $booking;
			}
		}

		return $matches;
	}//end loadOverlappingBookings()

	/**
	 * The stored bookings of a resource; none when unconfigured or when the
	 * read fails.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date       ISO date `YYYY-MM-DD`, for the log line.
	 *
	 * @return array<int|string, mixed>
	 */
	private function findBookingRows(string $resourceId, string $date): array {
		try {
			return $this->findScoped(schemaKey: self::BOOKING_SCHEMA_KEY, filters: ['resourceId' => $resourceId]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: booking lookup failed',
				['resource' => $resourceId, 'date' => $date]
			);
			return [];
		}
	}//end findBookingRows()

	/**
	 * Whether a booking has a window that overlaps the local day.
	 *
	 * @param array<string, mixed> $booking The booking.
	 * @param string               $date    ISO date `YYYY-MM-DD`.
	 *
	 * @return bool
	 */
	private function overlapsDay(array $booking, string $date): bool {
		$startAt = (string)($booking['startAt'] ?? '');
		$endAt = (string)($booking['endAt'] ?? '');
		if ($startAt === '' || $endAt === '') {
			return false;
		}

		// Overlap with the local day window.
		$dayStart = ($date . 'T00:00:00');
		$dayEnd = ($date . 'T23:59:59');

		return ($endAt < $dayStart || $startAt > $dayEnd) === false;
	}//end overlapsDay()

	/**
	 * Filters scoped to the configured register and a schema.
	 *
	 * @param string                $schemaKey The schema app-config key.
	 * @param array<string, string> $filters   The record filters.
	 *
	 * @return array<string, string>|null The register, schema and filters, or
	 *                                    null when either id is unconfigured.
	 */
	private function scopedFilters(string $schemaKey, array $filters): ?array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		return array_merge(['register' => $register, 'schema' => $schema], $filters);
	}//end scopedFilters()

	/**
	 * The stored objects of a pipelinq schema matching equality filters;
	 * none when the register or schema is unconfigured.
	 *
	 * @param string                $schemaKey The schema app-config key.
	 * @param array<string, string> $filters   The record filters.
	 *
	 * @return array<int|string, mixed>
	 */
	private function findScoped(string $schemaKey, array $filters): array {
		$scoped = $this->scopedFilters(schemaKey: $schemaKey, filters: $filters);
		if ($scoped === null) {
			return [];
		}

		return $this->getObjectService()->findAll(config: ['filters' => $scoped]);
	}//end findScoped()

	/**
	 * Resolve buffer minutes for a Service id.
	 *
	 * @param string $serviceId Service id; empty yields zero-buffer.
	 *
	 * @return array{before: int, after: int}
	 */
	private function buffersFor(string $serviceId): array {
		if ($serviceId === '') {
			return ['before' => 0, 'after' => 0];
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::SERVICE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return ['before' => 0, 'after' => 0];
		}

		try {
			$svc = $this->getObjectService()->find(
				id: $serviceId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: service lookup failed', ['service' => $serviceId]);
			return ['before' => 0, 'after' => 0];
		}

		$data = $this->toArray(object: $svc);
		if ($data === null) {
			return ['before' => 0, 'after' => 0];
		}

		return [
			'before' => max(0, (int)($data['bufferBeforeMinutes'] ?? 0)),
			'after' => max(0, (int)($data['bufferAfterMinutes'] ?? 0)),
		];
	}//end buffersFor()

	/**
	 * True when any of the vacation ranges contains the given date.
	 *
	 * @param array<string, mixed> $resource Resource entity.
	 * @param string $date ISO date `YYYY-MM-DD`.
	 *
	 * @return bool
	 */
	private function isOnVacation(array $resource, string $date): bool {
		$vacations = ($resource['vacations'] ?? []);
		if (is_array($vacations) === false) {
			return false;
		}

		foreach ($vacations as $vac) {
			if ($this->vacationCovers(vacation: $vac, date: $date) === true) {
				return true;
			}
		}

		return false;
	}//end isOnVacation()

	/**
	 * Whether one vacation range contains the date, both ends inclusive.
	 *
	 * @param mixed  $vacation A vacation entry.
	 * @param string $date     ISO date `YYYY-MM-DD`.
	 *
	 * @return bool
	 */
	private function vacationCovers(mixed $vacation, string $date): bool {
		if (is_array($vacation) === false) {
			return false;
		}

		$start = (string)($vacation['startDate'] ?? '');
		$end = (string)($vacation['endDate'] ?? '');
		if ($start === '' || $end === '') {
			return false;
		}

		return $date >= $start && $date <= $end;
	}//end vacationCovers()

	/**
	 * Read an existing AvailabilityCache row for the given resource/date.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date ISO date `YYYY-MM-DD`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadCacheEntry(string $resourceId, string $date): ?array {
		foreach ($this->findCacheRows(resourceId: $resourceId, date: $date) as $row) {
			$entry = $this->toArray(object: $row);
			if ($entry !== null) {
				return $entry;
			}
		}

		return null;
	}//end loadCacheEntry()

	/**
	 * The stored AvailabilityCache rows of a resource and date; none when
	 * unconfigured or when the read fails.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date       ISO date `YYYY-MM-DD`.
	 *
	 * @return array<int|string, mixed>
	 */
	private function findCacheRows(string $resourceId, string $date): array {
		try {
			return $this->findScoped(
				schemaKey: self::AVAILABILITY_CACHE_SCHEMA_KEY,
				filters: ['resourceId' => $resourceId, 'date' => $date]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: availability cache read failed',
				['resource' => $resourceId, 'date' => $date]
			);
			return [];
		}
	}//end findCacheRows()

	/**
	 * Persist (create or update) an AvailabilityCache row.
	 *
	 * @param array<string, mixed> $entry The row: resource UUID/slug (`resourceId`), ISO date `YYYY-MM-DD` (`date`),
	 *                                    free blocks, and the ISO generation and expiry timestamps.
	 *
	 * @return void
	 */
	private function persistCacheEntry(array $entry): void {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::AVAILABILITY_CACHE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$this->getObjectService()->saveObject(
				object: $entry,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: null
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: availability cache write failed',
				['resource' => $entry['resourceId'], 'date' => $entry['date']]
			);
		}
	}//end persistCacheEntry()

	/**
	 * Pull blocked times from the optional calendar provider seam.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date ISO date `YYYY-MM-DD`.
	 *
	 * @return array<int, array{startTime: string, endTime: string}>
	 */
	private function fetchCalendarBlocks(string $resourceId, string $date): array {
		$normalised = [];
		foreach ($this->providerBlocks(resourceId: $resourceId, date: $date) as $block) {
			if (is_array($block) === false) {
				continue;
			}

			$normalised[] = [
				'startTime' => (string)($block['startTime'] ?? ''),
				'endTime' => (string)($block['endTime'] ?? ''),
			];
		}

		return $normalised;
	}//end fetchCalendarBlocks()

	/**
	 * The raw blocks the calendar provider reports; none without a provider,
	 * on a failure or on an answer that is not a list.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date       ISO date `YYYY-MM-DD`.
	 *
	 * @return array<int|string, mixed>
	 */
	private function providerBlocks(string $resourceId, string $date): array {
		if ($this->calendarProvider === null || method_exists($this->calendarProvider, 'getBlockedTimes') === false) {
			return [];
		}

		$blocks = $this->askCalendarProvider(resourceId: $resourceId, date: $date);
		if (is_array($blocks) === false) {
			return [];
		}

		return $blocks;
	}//end providerBlocks()

	/**
	 * Ask the calendar provider for its blocked times; null when it fails.
	 *
	 * @param string $resourceId Resource UUID/slug.
	 * @param string $date       ISO date `YYYY-MM-DD`.
	 *
	 * @return mixed The provider's answer.
	 */
	private function askCalendarProvider(string $resourceId, string $date): mixed {
		try {
			// @phpstan-ignore-next-line dynamic provider seam
			return $this->calendarProvider->getBlockedTimes($resourceId, $date);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: calendar provider failed',
				['resource' => $resourceId, 'date' => $date]
			);
			return null;
		}
	}//end askCalendarProvider()

	/**
	 * True when [startMin, endMin) overlaps any block.
	 *
	 * @param int $startMin Slot start (minutes from midnight).
	 * @param int $endMin Slot end (minutes from midnight).
	 * @param array<int, array{startMin: int, endMin: int}> $blocks Blocked ranges.
	 *
	 * @return bool
	 */
	private function overlapsAny(int $startMin, int $endMin, array $blocks): bool {
		foreach ($blocks as $block) {
			$blockStart = $block['startMin'];
			$blockEnd = $block['endMin'];
			if ($blockEnd <= $blockStart) {
				continue;
			}

			if ($startMin < $blockEnd && $endMin > $blockStart) {
				return true;
			}
		}

		return false;
	}//end overlapsAny()

	/**
	 * Convert `HH:MM` to minutes-from-midnight. Out-of-range returns 0.
	 *
	 * @param string $time Time as `HH:MM` or `HH:MM:SS`.
	 *
	 * @return int
	 */
	private function minutesFromHHMM(string $time): int {
		if ($time === '' || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $match) !== 1) {
			return 0;
		}

		$hours = (int)$match[1];
		$minutes = (int)$match[2];
		if ($this->isClockTime(hours: $hours, minutes: $minutes) === false) {
			return 0;
		}

		return (($hours * 60) + $minutes);
	}//end minutesFromHHMM()

	/**
	 * Whether an hour and minute form a time on a 24-hour clock.
	 *
	 * @param int $hours   The hour.
	 * @param int $minutes The minute.
	 *
	 * @return bool
	 */
	private function isClockTime(int $hours, int $minutes): bool {
		return ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) === false;
	}//end isClockTime()

	/**
	 * Convert minutes-from-midnight back to `HH:MM` (zero-padded).
	 *
	 * @param int $minutes Minutes from midnight (clamped to [0, 1440]).
	 *
	 * @return string
	 */
	private function minutesToHHMM(int $minutes): string {
		$clamped = $this->clampToDay(value: $minutes);
		$hourPart = intdiv($clamped, 60);
		$minutePart = ($clamped % 60);
		return sprintf('%02d:%02d', $hourPart, $minutePart);
	}//end minutesToHHMM()

	/**
	 * Clamp a minute offset to the [0, 1440] day window.
	 *
	 * @param int $value Minutes value.
	 *
	 * @return int
	 */
	private function clampToDay(int $value): int {
		if ($value < 0) {
			return 0;
		}

		if ($value > (24 * 60)) {
			return (24 * 60);
		}

		return $value;
	}//end clampToDay()

	/**
	 * Compute minutes-from-midnight of an ISO timestamp, clamped to the given date's day window.
	 *
	 * Bookings that start on the previous day are clamped to 0; bookings that
	 * extend past the current day are clamped to 1440.
	 *
	 * @param string $iso ISO datetime, e.g. `2026-06-01T09:30:00+00:00`.
	 * @param string $date Local date `YYYY-MM-DD`.
	 *
	 * @return int
	 */
	private function minutesOfDay(string $iso, string $date): int {
		$timestamp = strtotime($iso);
		$day = $this->dayWindow(date: $date);
		if ($timestamp === false || $day === null) {
			return 0;
		}

		if ($timestamp <= $day[0]) {
			return 0;
		}

		if ($timestamp >= $day[1]) {
			return (24 * 60);
		}

		$minutesFromDayStart = intdiv(($timestamp - $day[0]), 60);
		return $this->clampToDay(value: (int)$minutesFromDayStart);
	}//end minutesOfDay()

	/**
	 * The first and last second of a local date.
	 *
	 * @param string $date Local date `YYYY-MM-DD`.
	 *
	 * @return array{0: int, 1: int}|null Null when the date does not parse.
	 */
	private function dayWindow(string $date): ?array {
		$dayStart = strtotime($date . 'T00:00:00');
		$dayEnd = strtotime($date . 'T23:59:59');
		if ($dayStart === false || $dayEnd === false) {
			return null;
		}

		return [$dayStart, $dayEnd];
	}//end dayWindow()

	/**
	 * Lower-case English weekday for a date.
	 *
	 * @param string $date ISO `YYYY-MM-DD`.
	 *
	 * @return string `monday`..`sunday` or empty on parse failure.
	 */
	private function weekDayFor(string $date): string {
		try {
			$dateTime = new DateTimeImmutable($date, new DateTimeZone('UTC'));
		} catch (\Throwable $e) {
			return '';
		}

		return strtolower($dateTime->format('l'));
	}//end weekDayFor()

	/**
	 * Normalise a free-blocks array read from the cache.
	 *
	 * @param mixed $blocks Raw value from the cache row.
	 *
	 * @return array<int, array{startTime: string, endTime: string, durationMinutes: int}>
	 */
	private function normaliseFreeBlocks(mixed $blocks): array {
		if (is_array($blocks) === false) {
			return [];
		}

		$out = [];
		foreach ($blocks as $block) {
			if (is_array($block) === false) {
				continue;
			}

			$out[] = [
				'startTime' => (string)($block['startTime'] ?? ''),
				'endTime' => (string)($block['endTime'] ?? ''),
				'durationMinutes' => (int)($block['durationMinutes'] ?? 0),
			];
		}

		return $out;
	}//end normaliseFreeBlocks()

	/**
	 * Now in ISO-8601 UTC.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}//end nowIso()

	/**
	 * Now plus N seconds in ISO-8601 UTC.
	 *
	 * @param int $seconds Offset in seconds.
	 *
	 * @return string
	 */
	private function isoOffsetSeconds(int $seconds): string {
		$dateTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
			->modify(sprintf('+%d seconds', $seconds));
		return $dateTime->format('Y-m-d\TH:i:sP');
	}//end isoOffsetSeconds()

	/**
	 * Resolve the pipelinq register id from app config.
	 *
	 * Fails closed: '' means "unconfigured", and every caller refuses the
	 * OpenRegister call on it. An empty register must never be handed to
	 * OpenRegister — ObjectService skips setRegister() for an empty value, so
	 * the query silently inherits whatever register context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return string The configured register id, or '' when unconfigured.
	 */
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerId;
	}//end registerId()

	/**
	 * Resolve a schema id by app-config key.
	 *
	 * @param string $key App-config key, e.g. `resource_schema`.
	 *
	 * @return string
	 */
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end schemaId()

	/**
	 * Resolve the OpenRegister ObjectService via the DI container.
	 *
	 * @return object The ObjectService instance.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
