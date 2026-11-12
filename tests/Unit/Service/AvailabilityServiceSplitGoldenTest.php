<?php

/**
 * Characterisation tests for AvailabilityService working hours, blocked
 * times, overlapping bookings, vacations, the cache read and the clock
 * conversions.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\AvailabilityService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the exact answers of the AvailabilityService units that are split
 * into named steps.
 */
class AvailabilityServiceSplitGoldenTest extends TestCase {

	/**
	 * The OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Logged warnings: message and context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The findAll configs asked, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * Build the service.
	 *
	 * @param array<string, string> $config App-config overrides.
	 *
	 * @return AvailabilityService
	 */
	private function service(array $config = []): AvailabilityService {
		$values = array_merge(
			[
				'register' => 'pipelinq',
				'resource_schema' => 'resource',
				'booking_schema' => 'booking',
				'service_schema' => 'service',
				'availability_cache_schema' => 'cache',
			],
			$config
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($values): string {
				return ($values[$key] ?? $default);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new AvailabilityService(
			appConfig: $appConfig,
			cacheFactory: $this->createMock(ICacheFactory::class),
			logger: $logger,
			objectService: $this->objects,
		);
	}//end service()

	/**
	 * A row as the entity OpenRegister returns.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $row): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($row['id'] ?? 'x'));
		$entity->setObject($row);

		return $entity;
	}//end entity()

	/**
	 * Answer the given resource, services and booking rows.
	 *
	 * @param array<string, mixed>|null      $resource The resource row, or null.
	 * @param array<int, mixed>              $bookings The booking rows findAll answers.
	 * @param array<string, array<string, mixed>> $services Service rows by id.
	 *
	 * @return void
	 */
	private function answer(?array $resource, array $bookings = [], array $services = []): void {
		$this->objects->method('find')->willReturnCallback(
			static function (mixed $id) use ($resource, $services): ?ObjectEntityInterface {
				if (isset($services[$id]) === true) {
					return self::entity($services[$id]);
				}

				if ($resource === null || $id !== 'res-1') {
					return null;
				}

				return self::entity($resource);
			}
		);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config) use ($bookings): array {
				$this->queries[] = $config;
				return $bookings;
			}
		);
	}//end answer()

	/**
	 * Working hours: missing resource, malformed lists, the first matching
	 * day, and an incomplete matching day.
	 *
	 * @return void
	 */
	public function testWorkingHours(): void {
		$this->answer(
			[
				'workingHours' => [
					'junk',
					['day' => 'Monday', 'openTime' => '09:00', 'closeTime' => '17:00'],
					['day' => 'monday', 'openTime' => '10:00', 'closeTime' => '11:00'],
					['day' => 'tuesday', 'openTime' => '09:00'],
					['day' => 'tuesday', 'openTime' => '09:00', 'closeTime' => '12:00'],
				],
			]
		);
		$service = $this->service();

		$this->assertSame(['openTime' => '09:00', 'closeTime' => '17:00'], $service->getWorkingHours('res-1', ' MONDAY '));
		$this->assertNull($service->getWorkingHours('res-1', 'tuesday'));
		$this->assertNull($service->getWorkingHours('res-1', 'sunday'));
		$this->assertNull($service->getWorkingHours('res-missing', 'monday'));
		$this->assertNull($service->getWorkingHours('', 'monday'));
	}//end testWorkingHours()

	/**
	 * A non-list workingHours answers no hours.
	 *
	 * @return void
	 */
	public function testWorkingHoursNotAList(): void {
		$this->answer(['workingHours' => 'monday']);

		$this->assertNull($this->service()->getWorkingHours('res-1', 'monday'));
	}//end testWorkingHoursNotAList()

	/**
	 * Vacations: inclusive bounds, malformed entries, non-list.
	 *
	 * @return void
	 */
	public function testVacationBlocksTheWholeDay(): void {
		$vacations = [
			'junk',
			['startDate' => '2026-06-01'],
			['startDate' => '2026-06-10', 'endDate' => '2026-06-12'],
		];
		$this->answer(['vacations' => $vacations]);
		$service = $this->service();

		$this->assertSame([['startMin' => 0, 'endMin' => 1440]], $service->getBlockedTimes('res-1', '2026-06-10'));
		$this->assertSame([['startMin' => 0, 'endMin' => 1440]], $service->getBlockedTimes('res-1', '2026-06-12'));
		$this->assertSame([], $service->getBlockedTimes('res-1', '2026-06-01'));
		$this->assertSame([], $service->getBlockedTimes('res-1', '2026-06-13'));
		$this->assertCount(2, $this->queries);
	}//end testVacationBlocksTheWholeDay()

	/**
	 * A non-list vacations field blocks nothing.
	 *
	 * @return void
	 */
	public function testVacationsNotAList(): void {
		$this->answer(['vacations' => '2026-06-10']);

		$this->assertSame([], $this->service()->getBlockedTimes('res-1', '2026-06-10'));
	}//end testVacationsNotAList()

	/**
	 * Booking blocks: the query, the day window, buffers, clamping, empty
	 * ranges and the calendar blocks after them.
	 *
	 * @return void
	 */
	public function testBlockedTimesGolden(): void {
		$bookings = [
			'not-a-row',
			['startAt' => '2026-06-01T10:00:00+00:00'],
			['startAt' => '2026-06-02T10:00:00+00:00', 'endAt' => '2026-06-02T11:00:00+00:00'],
			['startAt' => '2026-05-31T10:00:00+00:00', 'endAt' => '2026-05-31T11:00:00+00:00'],
			['startAt' => '2026-06-01T09:00:00+00:00', 'endAt' => '2026-06-01T09:30:00+00:00', 'serviceId' => 'svc-buf'],
			['startAt' => '2026-05-31T23:00:00+00:00', 'endAt' => '2026-06-01T00:30:00+00:00'],
			['startAt' => '2026-06-01T23:30:00+00:00', 'endAt' => '2026-06-02T01:00:00+00:00', 'serviceId' => 'svc-buf'],
			['startAt' => '2026-06-01T12:00:00+00:00', 'endAt' => '2026-06-01T12:00:00+00:00'],
			self::entity(['startAt' => '2026-06-01T14:00:00+00:00', 'endAt' => '2026-06-01T15:00:00+00:00', 'serviceId' => 'svc-none']),
		];
		$services = ['svc-buf' => ['bufferBeforeMinutes' => 15, 'bufferAfterMinutes' => -5]];
		$this->answer(['vacations' => []], $bookings, $services);
		$service = $this->service();
		$service->setCalendarProvider(
			new class {
				/**
				 * Return blocked times.
				 *
				 * @param string $resourceId Resource id.
				 * @param string $date       Date.
				 *
				 * @return array<int, mixed>
				 */
				public function getBlockedTimes(string $resourceId, string $date): array {
					return [['startTime' => '16:00', 'endTime' => '16:45'], 'junk', ['startTime' => '18:00'], ['startTime' => '25:00', 'endTime' => '07:60']];
				}
			}
		);

		$this->assertSame(
			[
				['startMin' => 525, 'endMin' => 570],
				['startMin' => 0, 'endMin' => 30],
				['startMin' => 1395, 'endMin' => 1440],
				['startMin' => 840, 'endMin' => 900],
				['startMin' => 960, 'endMin' => 1005],
			],
			$service->getBlockedTimes('res-1', '2026-06-01')
		);
		$this->assertSame(
			['filters' => ['register' => 'pipelinq', 'schema' => 'booking', 'resourceId' => 'res-1']],
			$this->queries[0]
		);
	}//end testBlockedTimesGolden()

	/**
	 * A failing booking read and a failing or odd calendar provider block nothing.
	 *
	 * @return void
	 */
	public function testBlockedTimesFailures(): void {
		$this->objects->method('find')->willReturn(self::entity(['vacations' => []]));
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$service = $this->service();
		$service->setCalendarProvider(
			new class {
				/**
				 * Fail.
				 *
				 * @return array<int, mixed>
				 */
				public function getBlockedTimes(): array {
					throw new RuntimeException('calendar down');
				}
			}
		);

		$this->assertSame([], $service->getBlockedTimes('res-1', '2026-06-01'));
		$this->assertSame(
			[
				['Pipelinq: booking lookup failed', ['resource' => 'res-1', 'date' => '2026-06-01']],
				['Pipelinq: calendar provider failed', ['resource' => 'res-1', 'date' => '2026-06-01']],
			],
			$this->warnings
		);

		$service->setCalendarProvider(new class {
		});
		$this->assertSame([], $service->getBlockedTimes('res-1', '2026-06-01'));
		$service->setCalendarProvider(
			new class {
				/**
				 * Answer a non-list.
				 *
				 * @return string
				 */
				public function getBlockedTimes(): string {
					return 'busy';
				}
			}
		);
		$this->assertSame([], $service->getBlockedTimes('res-1', '2026-06-01'));
	}//end testBlockedTimesFailures()

	/**
	 * An unconfigured booking schema reads nothing; an unknown resource
	 * still reads its bookings.
	 *
	 * @return void
	 */
	public function testBlockedTimesUnconfiguredAndUnknownResource(): void {
		$this->answer(null, [['startAt' => '2026-06-01T09:00:00+00:00', 'endAt' => '2026-06-01T10:00:00+00:00']]);

		$this->assertSame([], $this->service(['booking_schema' => ''])->getBlockedTimes('res-1', '2026-06-01'));
		$this->assertSame([], $this->queries);
		$this->assertSame([['startMin' => 540, 'endMin' => 600]], $this->service()->getBlockedTimes('res-1', '2026-06-01'));
	}//end testBlockedTimesUnconfiguredAndUnknownResource()

	/**
	 * The cache read: its query, the first usable row, a failure and an
	 * unconfigured schema.
	 *
	 * @return void
	 */
	public function testCacheEntryRead(): void {
		$cached = ['freeBlocks' => [], 'generatedAt' => 'g', 'expiresAt' => '2999-01-01T00:00:00+00:00'];
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config) use ($cached): array {
				$this->queries[] = $config;
				if (count($this->queries) === 2) {
					throw new RuntimeException('down');
				}

				return ['junk', $cached, ['expiresAt' => '']];
			}
		);
		$service = $this->service();

		$this->assertSame(
			['freeBlocks' => [], 'generatedAt' => 'g', 'expiresAt' => '2999-01-01T00:00:00+00:00', 'stale' => false],
			$service->getOrComputeCache('res-1', '2026-06-01')
		);
		$this->assertSame(
			['filters' => ['register' => 'pipelinq', 'schema' => 'cache', 'resourceId' => 'res-1', 'date' => '2026-06-01']],
			$this->queries[0]
		);

		$this->assertSame([], $service->getOrComputeCache('res-1', '2026-06-01')['freeBlocks']);
		$this->assertSame(['Pipelinq: availability cache read failed', ['resource' => 'res-1', 'date' => '2026-06-01']], $this->warnings[0]);
	}//end testCacheEntryRead()

	/**
	 * Free slots: closing time, duration fit and clock parsing.
	 *
	 * @return void
	 */
	public function testComputeAvailabilityGolden(): void {
		$this->answer(
			[
				'workingHours' => [
					['day' => 'monday', 'openTime' => '08:50', 'closeTime' => '10:00:00'],
					['day' => 'tuesday', 'openTime' => '9:00', 'closeTime' => '24:00'],
				],
			],
			[['startAt' => '2026-06-01T09:15:00+00:00', 'endAt' => '2026-06-01T09:30:00+00:00']]
		);
		$service = $this->service();

		$this->assertSame(
			[
				['startTime' => '09:00', 'endTime' => '09:15', 'durationMinutes' => 15],
				['startTime' => '09:30', 'endTime' => '09:45', 'durationMinutes' => 15],
				['startTime' => '09:45', 'endTime' => '10:00', 'durationMinutes' => 15],
			],
			$service->computeAvailability('res-1', '2026-06-01', 15)
		);
		$this->assertSame([], $service->computeAvailability('res-1', '2026-06-02', 15));
	}//end testComputeAvailabilityGolden()
}//end class
