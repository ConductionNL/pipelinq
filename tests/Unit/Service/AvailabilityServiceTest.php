<?php

/**
 * Unit tests for AvailabilityService — slot computation, alignment, buffers, cache.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-02-availability-service/specs/appointment-booking/spec.md#req-apt-003
 * @spec openspec/changes/appointment-booking-02-availability-service/specs/appointment-booking/spec.md#req-apt-016
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\AvailabilityService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AvailabilityService.
 *
 * Mocks ObjectService + the optional calendar seam — no real DB.
 */
class AvailabilityServiceTest extends TestCase {

	/**
	 * Resource fixture: works Monday/Tuesday/Wednesday with a December vacation block.
	 *
	 * @var array<string, mixed>
	 */
	private const RESOURCE_SARAH = [
		'@self' => ['id' => 'res-sarah'],
		'name' => 'Sarah',
		'type' => 'staff',
		'workingHours' => [
			['day' => 'monday',    'openTime' => '09:00', 'closeTime' => '17:30'],
			['day' => 'tuesday',   'openTime' => '09:00', 'closeTime' => '12:00'],
			['day' => 'wednesday', 'openTime' => '09:00', 'closeTime' => '17:30'],
		],
		'vacations' => [
			['startDate' => '2026-12-20', 'endDate' => '2026-12-31'],
		],
	];

	/**
	 * Build a service with overridable mocks.
	 *
	 * @param ObjectServiceInterface|null $objectService Optional pre-built mock.
	 *
	 * @return array{0: AvailabilityService, 1: ObjectService}
	 */
	private function buildService(?ObjectServiceInterface $objectService = null): array {
		$objectService = ($objectService ?? $this->createMock(originalClassName: ObjectServiceInterface::class));
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			callback: static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'pipelinq',
					'resource_schema' => 'resource',
					'booking_schema' => 'booking',
					'service_schema' => 'service',
					'availability_cache_schema' => 'availability-cache',
				];
				return ($values[$key] ?? $default);
			}
		);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$service = new AvailabilityService(
			container: $container,
			appConfig: $appConfig,
			cacheFactory: $cacheFactory,
			logger: $logger
		);

		return [$service, $objectService];
	}//end buildService()

	/**
	 * Free slots are emitted between 09:00 and 17:30 when no bookings exist.
	 *
	 * @return void
	 */
	public function testComputeAvailabilityReturnsFreeSlotsWhenResourceIsAvailable(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::RESOURCE_SARAH);
		$object->method('findAll')->willReturn([]);

		[$service] = $this->buildService(objectService: $object);

		// 2026-06-01 was a Monday.
		$slots = $service->computeAvailability(resourceId: 'res-sarah', date: '2026-06-01', serviceDurationMinutes: 30);

		$this->assertNotEmpty(actual: $slots);
		$this->assertSame(expected: '09:00', actual: $slots[0]['startTime']);
		$this->assertSame(expected: '09:30', actual: $slots[0]['endTime']);
		$this->assertSame(expected: 30, actual: $slots[0]['durationMinutes']);

		// Last 30-minute slot fitting under 17:30 starts at 17:00.
		$last = $slots[(count($slots) - 1)];
		$this->assertSame(expected: '17:00', actual: $last['startTime']);
		$this->assertSame(expected: '17:30', actual: $last['endTime']);

	}//end testComputeAvailabilityReturnsFreeSlotsWhenResourceIsAvailable()

	/**
	 * A booking at 10:00-10:30 with no buffers blocks 45-min slots that
	 * overlap that window. Slot at 09:00 must remain available; slots at
	 * 09:45 / 10:00 / 10:15 must NOT (they'd run into the booking).
	 *
	 * @return void
	 */
	public function testComputeAvailabilityExcludesBookedTimes(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			callback: static function (mixed $id) {
				if ($id === 'res-sarah') {
					return self::RESOURCE_SARAH;
				}

				return ['bufferBeforeMinutes' => 0, 'bufferAfterMinutes' => 0];
			}
		);
		$object->method('findAll')->willReturnCallback(
			callback: static function (array $config) {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? '') !== 'booking') {
					return [];
				}

				return [
					[
						'@self' => ['id' => 'b1'],
						'serviceId' => 'svc-cut',
						'resourceId' => 'res-sarah',
						'startAt' => '2026-06-01T10:00:00+00:00',
						'endAt' => '2026-06-01T10:30:00+00:00',
					],
				];
			}
		);

		[$service] = $this->buildService(objectService: $object);

		$slots = $service->computeAvailability(resourceId: 'res-sarah', date: '2026-06-01', serviceDurationMinutes: 45);

		$startTimes = array_column($slots, 'startTime');

		$this->assertContains(needle: '09:00', haystack: $startTimes, message: '09:00-09:45 should be free');
		$this->assertContains(needle: '10:30', haystack: $startTimes, message: '10:30-11:15 should be free');
		$this->assertNotContains(needle: '09:45', haystack: $startTimes, message: '09:45-10:30 overlaps the booking');
		$this->assertNotContains(needle: '10:00', haystack: $startTimes, message: '10:00-10:45 overlaps the booking');
		$this->assertNotContains(needle: '10:15', haystack: $startTimes, message: '10:15-11:00 overlaps the booking');

	}//end testComputeAvailabilityExcludesBookedTimes()

	/**
	 * A vacation entry blocks the whole day — no slots are returned.
	 *
	 * @return void
	 */
	public function testComputeAvailabilityExcludesVacationDates(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::RESOURCE_SARAH);
		$object->method('findAll')->willReturn([]);

		[$service] = $this->buildService(objectService: $object);

		// 2026-12-21 is inside the configured vacation window.
		$slots = $service->computeAvailability(resourceId: 'res-sarah', date: '2026-12-21', serviceDurationMinutes: 30);

		$this->assertSame(expected: [], actual: $slots);

	}//end testComputeAvailabilityExcludesVacationDates()

	/**
	 * `alignToSlots` returns 15-minute boundaries within the window.
	 *
	 * Working 09:00-12:00 with 45-minute service: the spec scenario asks for
	 * start times `09:00, 09:15, 09:30, 09:45, 10:00, 10:15, 10:30, 10:45,
	 * 11:15` — i.e. every 15-minute boundary where the full 45 minutes still
	 * fits before 12:00. `alignToSlots` emits ALL boundaries; the duration
	 * filter is applied by `computeAvailability`.
	 *
	 * @return void
	 */
	public function testAlignToSlotsReturnsFifteenMinuteAlignedBoundaries(): void {
		[$service] = $this->buildService();

		$boundaries = $service->alignToSlots(startTime: '09:00', endTime: '12:00', intervalMinutes: 15);

		// 09:00 .. 11:45 inclusive = 12 boundaries.
		$this->assertCount(expectedCount: 12, haystack: $boundaries);
		$this->assertSame(expected: 540, actual: $boundaries[0]);
		$this->assertSame(expected: 555, actual: $boundaries[1]);
		$this->assertSame(expected: 705, actual: $boundaries[11]);

		// Non-aligned start time is rounded UP.
		$rounded = $service->alignToSlots(startTime: '09:07', endTime: '10:00', intervalMinutes: 15);
		$this->assertSame(expected: 555, actual: $rounded[0]);

	}//end testAlignToSlotsReturnsFifteenMinuteAlignedBoundaries()

	/**
	 * `computeAvailability` honours the buffer scenario:
	 *
	 * GIVEN service has bufferBefore=10, bufferAfter=5, duration=30, AND
	 * resource is open 09:00-12:00, AND booking at 09:30-10:00, THEN any
	 * candidate 30-minute slot overlapping 09:20-10:05 must be blocked.
	 *
	 * @return void
	 */
	public function testBuffersAreAppliedAroundBookings(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			callback: static function (mixed $id) {
				if ($id === 'res-sarah') {
					return [
						'@self' => ['id' => 'res-sarah'],
						'workingHours' => [
							['day' => 'monday', 'openTime' => '09:00', 'closeTime' => '12:00'],
						],
						'vacations' => [],
					];
				}

				return ['bufferBeforeMinutes' => 10, 'bufferAfterMinutes' => 5];
			}
		);
		$object->method('findAll')->willReturnCallback(
			callback: static function (array $config) {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? '') !== 'booking') {
					return [];
				}

				return [
					[
						'@self' => ['id' => 'b1'],
						'serviceId' => 'svc-buf',
						'resourceId' => 'res-sarah',
						'startAt' => '2026-06-01T09:30:00+00:00',
						'endAt' => '2026-06-01T10:00:00+00:00',
					],
				];
			}
		);

		[$service] = $this->buildService(objectService: $object);

		$slots = $service->computeAvailability(resourceId: 'res-sarah', date: '2026-06-01', serviceDurationMinutes: 30);

		$startTimes = array_column($slots, 'startTime');

		// Buffer-extended booking spans 09:20-10:05; any 30-minute slot overlapping that is blocked.
		$this->assertNotContains(needle: '09:00', haystack: $startTimes, message: '09:00-09:30 overlaps 09:20 buffer');
		$this->assertNotContains(needle: '09:15', haystack: $startTimes, message: '09:15-09:45 overlaps booking');
		$this->assertNotContains(needle: '09:30', haystack: $startTimes, message: '09:30-10:00 IS the booking');
		$this->assertNotContains(needle: '09:45', haystack: $startTimes, message: '09:45-10:15 overlaps booking and after-buffer');
		$this->assertNotContains(needle: '10:00', haystack: $startTimes, message: '10:00-10:30 overlaps after-buffer until 10:05');

		// 10:15 onward must be free (10:05 buffer end <= 10:15).
		$this->assertContains(needle: '10:15', haystack: $startTimes);
		$this->assertContains(needle: '11:30', haystack: $startTimes);

	}//end testBuffersAreAppliedAroundBookings()

	/**
	 * Cache hit: when a fresh AvailabilityCache row exists, getOrComputeCache
	 * returns it without re-computing or persisting.
	 *
	 * @return void
	 */
	public function testGetOrComputeCacheReturnsFreshEntry(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$freshIso = (new DateTimeImmutable('+12 hours', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
		$genIso = (new DateTimeImmutable('-1 hour', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');

		$object->method('findAll')->willReturn(
			value: [
				[
					'@self' => ['id' => 'cache-1'],
					'resourceId' => 'res-sarah',
					'date' => '2026-06-01',
					'freeBlocks' => [
						['startTime' => '09:00', 'endTime' => '09:30', 'durationMinutes' => 30],
					],
					'generatedAt' => $genIso,
					'expiresAt' => $freshIso,
				],
			]
		);
		$object->expects($this->never())->method('saveObject');

		[$service] = $this->buildService(objectService: $object);

		$result = $service->getOrComputeCache(resourceId: 'res-sarah', date: '2026-06-01', serviceDurationMinutes: 30);

		$this->assertFalse(condition: $result['stale']);
		$this->assertSame(expected: $freshIso, actual: $result['expiresAt']);
		$this->assertCount(expectedCount: 1, haystack: $result['freeBlocks']);
		$this->assertSame(expected: '09:00', actual: $result['freeBlocks'][0]['startTime']);

	}//end testGetOrComputeCacheReturnsFreshEntry()

	/**
	 * Stale cache (past expiresAt) is still returned but flagged stale.
	 *
	 * @return void
	 */
	public function testGetOrComputeCacheReturnsStaleEntryFlagged(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$oldIso = (new DateTimeImmutable('-25 hours', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
		$genIso = (new DateTimeImmutable('-26 hours', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');

		$object->method('findAll')->willReturn(
			value: [
				[
					'@self' => ['id' => 'cache-stale'],
					'resourceId' => 'res-sarah',
					'date' => '2026-06-01',
					'freeBlocks' => [
						['startTime' => '09:00', 'endTime' => '09:30', 'durationMinutes' => 30],
					],
					'generatedAt' => $genIso,
					'expiresAt' => $oldIso,
				],
			]
		);
		$object->expects($this->never())->method('saveObject');

		[$service] = $this->buildService(objectService: $object);

		$result = $service->getOrComputeCache(resourceId: 'res-sarah', date: '2026-06-01', serviceDurationMinutes: 30);

		$this->assertTrue(condition: $result['stale']);
		$this->assertCount(expectedCount: 1, haystack: $result['freeBlocks']);

	}//end testGetOrComputeCacheReturnsStaleEntryFlagged()

	/**
	 * Cache miss: the service computes availability + persists a new cache row.
	 *
	 * @return void
	 */
	public function testGetOrComputeCachePersistsOnMiss(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::RESOURCE_SARAH);
		$object->method('findAll')->willReturn([]);
		$object->expects($this->once())->method('saveObject')->willReturn(['id' => 'new']);

		[$service] = $this->buildService(objectService: $object);

		$result = $service->getOrComputeCache(resourceId: 'res-sarah', date: '2026-06-01', serviceDurationMinutes: 30);

		$this->assertFalse(condition: $result['stale']);
		$this->assertNotEmpty(actual: $result['freeBlocks']);
		$this->assertNotSame(expected: '', actual: $result['expiresAt']);

	}//end testGetOrComputeCachePersistsOnMiss()

	/**
	 * Calendar seam: when a provider is registered, its blocks are honoured.
	 *
	 * @return void
	 */
	public function testCalendarProviderBlocksAreMerged(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::RESOURCE_SARAH);
		$object->method('findAll')->willReturn([]);

		[$service] = $this->buildService(objectService: $object);

		$provider = new class {
			/**
			 * Return blocked times.
			 *
			 * @param string $resourceId Resource id.
			 * @param string $date Date.
			 *
			 * @return array<int, array{startTime: string, endTime: string}>
			 */
			public function getBlockedTimes(string $resourceId, string $date): array {
				return [['startTime' => '11:00', 'endTime' => '12:00']];
			}//end getBlockedTimes()
		};

		$service->setCalendarProvider(provider: $provider);

		$slots = $service->computeAvailability(resourceId: 'res-sarah', date: '2026-06-01', serviceDurationMinutes: 30);
		$startTimes = array_column($slots, 'startTime');

		$this->assertNotContains(needle: '11:00', haystack: $startTimes);
		$this->assertNotContains(needle: '11:30', haystack: $startTimes);
		$this->assertContains(needle: '10:30', haystack: $startTimes);
		$this->assertContains(needle: '12:00', haystack: $startTimes);

	}//end testCalendarProviderBlocksAreMerged()

	/**
	 * `invalidateCache` deletes the cache entry by uuid.
	 *
	 * @return void
	 */
	public function testInvalidateCacheDeletesEntry(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('findAll')->willReturn(
			value: [['@self' => ['id' => 'cache-zap'], 'resourceId' => 'res-sarah', 'date' => '2026-06-01']]
		);
		$object->expects($this->once())
			->method('deleteObject')
			->with('cache-zap', 'pipelinq', 'availability-cache')
			->willReturn(true);

		[$service] = $this->buildService(objectService: $object);

		$service->invalidateCache(resourceId: 'res-sarah', date: '2026-06-01');

	}//end testInvalidateCacheDeletesEntry()
}//end class
