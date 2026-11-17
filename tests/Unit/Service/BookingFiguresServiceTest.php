<?php

/**
 * Unit tests for BookingFiguresService — the busy figures of a service and a resource.
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
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-resource-page-shows-how-much-of-its-bookable-time-is-booked-req-srg-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\BookingFiguresService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The week and month figures of appointment services and resources.
 *
 * "Now" is Wednesday 11 November 2026, 10:00 in Amsterdam: this week runs from
 * Monday 9 November 00:00 up to Monday 16 November 00:00, this month is November.
 *
 * @covers \OCA\Pipelinq\Service\BookingFiguresService
 * @uses   \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses   \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-service-page-shows-how-busy-the-service-is-req-srg-003
 */
final class BookingFiguresServiceTest extends TestCase {

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The configured app values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [
		'register' => 'pipelinq',
		'booking_schema' => 'appointmentBooking',
		'resource_schema' => 'appointmentResource',
	];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test, at the fixed "now".
	 *
	 * @return BookingFiguresService
	 */
	private function service(): BookingFiguresService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->config[$key] ?? $default)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-11T10:00:00', new DateTimeZone('Europe/Amsterdam')));

		return new BookingFiguresService($appConfig, $time, $this->logger, $this->objects);
	}//end service()

	/**
	 * A stored booking.
	 *
	 * @param string                           $startAt     Start, ISO 8601.
	 * @param string                           $endAt       End, ISO 8601.
	 * @param string                           $status      The booking status.
	 * @param string                           $serviceId   The service.
	 * @param array<int, array<string, mixed>> $assignments The resource assignments.
	 *
	 * @return ObjectEntity
	 */
	private static function booking(string $startAt, string $endAt, string $status='confirmed', string $serviceId='svc-passport', array $assignments=[]): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(
			[
				'serviceId' => $serviceId,
				'startAt' => $startAt,
				'endAt' => $endAt,
				'status' => $status,
				'resourceAssignments' => $assignments,
			]
		);

		return $entity;
	}//end booking()

	/**
	 * A booking of one hour assigned to a resource.
	 *
	 * @param string $resourceId The resource.
	 * @param string $startAt    Start, ISO 8601 with offset.
	 * @param string $endAt      End, ISO 8601 with offset.
	 * @param string $status     The booking status.
	 *
	 * @return ObjectEntity
	 */
	private static function assigned(string $resourceId, string $startAt, string $endAt, string $status='confirmed'): ObjectEntity {
		return self::booking(
			startAt: $startAt,
			endAt: $endAt,
			status: $status,
			assignments: [['resourceId' => $resourceId, 'startAt' => $startAt, 'endAt' => $endAt]]
		);
	}//end assigned()

	/**
	 * A resource with working hours and vacations, as `find` answers it.
	 *
	 * @param array<int, array<string, string>> $workingHours The working hours.
	 * @param array<int, array<string, string>> $vacations    The vacations.
	 *
	 * @return ObjectEntity
	 */
	private static function resource(array $workingHours, array $vacations=[]): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['name' => 'Fatma Yildiz', 'workingHours' => $workingHours, 'vacations' => $vacations]);

		return $entity;
	}//end resource()

	/**
	 * Monday to Thursday, 09:00 to 17:00.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function mondayToThursday(): array {
		$hours = [];
		foreach (['monday', 'tuesday', 'wednesday', 'thursday'] as $day) {
			$hours[] = ['day' => $day, 'openTime' => '09:00', 'closeTime' => '17:00'];
		}

		return $hours;
	}//end mondayToThursday()

	/**
	 * A service counts the bookings starting this week that take place, and the
	 * no-shows starting this month.
	 *
	 * @return void
	 */
	public function testAServiceCountsThisWeeksBookingsAndThisMonthsNoShows(): void {
		$this->objects->expects(self::once())->method('findAll')
			->with(['filters' => ['register' => 'pipelinq', 'schema' => 'appointmentBooking', 'serviceId' => 'svc-passport']])
			->willReturn(
				[
					// This week: Monday 00:00 and Sunday 23:00, a no-show on Tuesday.
					self::booking('2026-11-09T00:00:00+01:00', '2026-11-09T00:20:00+01:00'),
					self::booking('2026-11-15T23:00:00+01:00', '2026-11-15T23:20:00+01:00', 'completed'),
					self::booking('2026-11-10T09:00:00+01:00', '2026-11-10T09:20:00+01:00', 'no-show'),
					// Cancelled or rescheduled this week: not counted.
					self::booking('2026-11-11T09:00:00+01:00', '2026-11-11T09:20:00+01:00', 'cancelled-by-customer'),
					self::booking('2026-11-11T09:30:00+01:00', '2026-11-11T09:50:00+01:00', 'rescheduled'),
					// Another service, answered all the same: not counted.
					self::booking('2026-11-11T11:00:00+01:00', '2026-11-11T11:20:00+01:00', 'confirmed', 'svc-other'),
					// Next Monday 00:00 is next week; a no-show earlier this month counts for the month only.
					self::booking('2026-11-16T00:00:00+01:00', '2026-11-16T00:20:00+01:00'),
					self::booking('2026-11-02T09:00:00+01:00', '2026-11-02T09:20:00+01:00', 'no-show'),
					// A no-show in October and a booking without a start: neither.
					self::booking('2026-10-30T09:00:00+01:00', '2026-10-30T09:20:00+01:00', 'no-show'),
					self::booking('', ''),
				]
			);

		self::assertSame(['bookingsThisWeek' => 3, 'noShowsThisMonth' => 2], $this->service()->serviceFigures(serviceId: 'svc-passport'));
	}//end testAServiceCountsThisWeeksBookingsAndThisMonthsNoShows()

	/**
	 * The week boundary is local time: 23:30 UTC on Sunday 15 November is already
	 * next week in Amsterdam.
	 *
	 * @return void
	 */
	public function testTheWeekIsTheLocalWeek(): void {
		$this->objects->method('findAll')->willReturn(
			[
				self::booking('2026-11-15T23:30:00Z', '2026-11-15T23:50:00Z'),
				self::booking('2026-11-08T23:30:00Z', '2026-11-08T23:50:00Z'),
			]
		);

		self::assertSame(1, $this->service()->serviceFigures(serviceId: 'svc-passport')['bookingsThisWeek']);
	}//end testTheWeekIsTheLocalWeek()

	/**
	 * A resource's share is its booked minutes this week over its working hours,
	 * minus the days a vacation covers.
	 *
	 * @return void
	 */
	public function testAResourcesShareIsBookedOverBookableMinutes(): void {
		$this->objects->method('find')->willReturn(
			self::resource(
				workingHours: self::mondayToThursday(),
				vacations: [['startDate' => '2026-11-12', 'endDate' => '2026-11-20']]
			)
		);
		$this->objects->expects(self::once())->method('findAll')
			->with(['filters' => ['register' => 'pipelinq', 'schema' => 'appointmentBooking']])
			->willReturn(
				[
					self::assigned('res-fatma', '2026-11-09T09:00:00+01:00', '2026-11-09T10:00:00+01:00'),
					self::assigned('res-fatma', '2026-11-10T09:00:00+01:00', '2026-11-10T11:00:00+01:00'),
					self::assigned('res-fatma', '2026-11-11T09:00:00+01:00', '2026-11-11T09:30:00+01:00', 'no-show'),
					// Cancelled, another resource, and last week: not counted.
					self::assigned('res-fatma', '2026-11-11T13:00:00+01:00', '2026-11-11T14:00:00+01:00', 'cancelled-by-business'),
					self::assigned('res-desk', '2026-11-11T13:00:00+01:00', '2026-11-11T14:00:00+01:00'),
					self::assigned('res-fatma', '2026-11-02T09:00:00+01:00', '2026-11-02T10:00:00+01:00'),
					// Started last Sunday: its 30 minutes this week fill time, but it is not this week's booking.
					self::assigned('res-fatma', '2026-11-08T23:30:00+01:00', '2026-11-09T00:30:00+01:00'),
				]
			);

		$figures = $this->service()->resourceFigures(resourceId: 'res-fatma');

		// Bookable: Monday to Wednesday 3 x 480 minutes; Thursday is on vacation.
		// Booked: 60 + 120 + 30 + 30 = 240 of 1440 minutes = 16.7%.
		self::assertSame(
			['bookingsThisWeek' => 3, 'bookedMinutes' => 240, 'bookableMinutes' => 1440, 'bookedShare' => 17],
			$figures
		);
	}//end testAResourcesShareIsBookedOverBookableMinutes()

	/**
	 * Only the booking's assignment to this resource fills its time, not the whole booking.
	 *
	 * @return void
	 */
	public function testOnlyTheResourcesOwnAssignmentFillsItsTime(): void {
		$this->objects->method('find')->willReturn(self::resource(workingHours: self::mondayToThursday()));
		$this->objects->method('findAll')->willReturn(
			[
				self::booking(
					startAt: '2026-11-10T09:00:00+01:00',
					endAt: '2026-11-10T11:00:00+01:00',
					assignments: [
						['resourceId' => 'res-room', 'startAt' => '2026-11-10T09:00:00+01:00', 'endAt' => '2026-11-10T11:00:00+01:00'],
						['resourceId' => 'res-fatma', 'startAt' => '2026-11-10T10:00:00+01:00', 'endAt' => '2026-11-10T10:45:00+01:00'],
					]
				),
			]
		);

		$figures = $this->service()->resourceFigures(resourceId: 'res-fatma');

		self::assertSame(1, $figures['bookingsThisWeek']);
		self::assertSame(45, $figures['bookedMinutes']);
		self::assertSame(1920, $figures['bookableMinutes']);
		self::assertSame(2, $figures['bookedShare']);
	}//end testOnlyTheResourcesOwnAssignmentFillsItsTime()

	/**
	 * A resource on vacation all week has no bookable time: the count without a share.
	 *
	 * @return void
	 */
	public function testAResourceOnVacationAllWeekHasNoShare(): void {
		$this->objects->method('find')->willReturn(
			self::resource(
				workingHours: self::mondayToThursday(),
				vacations: [['startDate' => '2026-11-01', 'endDate' => '2026-11-30']]
			)
		);
		$this->objects->method('findAll')->willReturn(
			[self::assigned('res-fatma', '2026-11-10T09:00:00+01:00', '2026-11-10T10:00:00+01:00')]
		);

		self::assertSame(
			['bookingsThisWeek' => 1, 'bookedMinutes' => 60, 'bookableMinutes' => 0, 'bookedShare' => null],
			$this->service()->resourceFigures(resourceId: 'res-fatma')
		);
	}//end testAResourceOnVacationAllWeekHasNoShare()

	/**
	 * Working hours that are not clock times, or close before they open, add nothing.
	 *
	 * @return void
	 */
	public function testMalformedWorkingHoursAddNoBookableTime(): void {
		$this->objects->method('find')->willReturn(
			self::resource(
				workingHours: [
					['day' => 'Monday', 'openTime' => '09:00', 'closeTime' => '12:00'],
					['day' => 'monday', 'openTime' => '13:00', 'closeTime' => '14:30'],
					['day' => 'tuesday', 'openTime' => 'nine', 'closeTime' => '17:00'],
					['day' => 'wednesday', 'openTime' => '17:00', 'closeTime' => '09:00'],
					['day' => 'thursday', 'openTime' => '25:00', 'closeTime' => '26:00'],
					['day' => 'FRIDAY', 'openTime' => '10:00', 'closeTime' => '11:00'],
					['day' => 'friday', 'openTime' => '13:00', 'closeTime' => '14:30'],
				],
				vacations: [['startDate' => '', 'endDate' => '2026-11-30'], 'not-a-vacation', ['startDate' => '2026-11-09T00:00:00+01:00']]
			)
		);
		$this->objects->method('findAll')->willReturn([]);

		$figures = $this->service()->resourceFigures(resourceId: 'res-fatma');

		// Monday is away (a vacation without an end covers its start day).
		// Friday: 60 + 90 minutes, day names read case-insensitively; the
		// malformed Tuesday to Thursday rows add nothing.
		self::assertSame(150, $figures['bookableMinutes']);
		self::assertSame(0, $figures['bookedShare']);
	}//end testMalformedWorkingHoursAddNoBookableTime()

	/**
	 * Failed reads are logged and read as no bookings and no bookable time.
	 *
	 * @return void
	 */
	public function testFailedReadsAreLoggedAndReadAsNothing(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->objects->method('find')->willThrowException(new RuntimeException('down'));
		$this->logger->expects(self::exactly(2))->method('warning');

		self::assertSame(
			['bookingsThisWeek' => 0, 'bookedMinutes' => 0, 'bookableMinutes' => 0, 'bookedShare' => null],
			$this->service()->resourceFigures(resourceId: 'res-fatma')
		);
	}//end testFailedReadsAreLoggedAndReadAsNothing()

	/**
	 * Without a configured register OpenRegister is never called unscoped.
	 *
	 * @return void
	 */
	public function testWithoutARegisterNothingIsRead(): void {
		$this->config['register'] = '';
		$this->objects->expects(self::never())->method('findAll');
		$this->objects->expects(self::never())->method('find');

		self::assertSame(['bookingsThisWeek' => 0, 'noShowsThisMonth' => 0], $this->service()->serviceFigures(serviceId: 'svc-passport'));
		self::assertNull($this->service()->resourceFigures(resourceId: 'res-fatma')['bookedShare']);
	}//end testWithoutARegisterNothingIsRead()
}//end class
