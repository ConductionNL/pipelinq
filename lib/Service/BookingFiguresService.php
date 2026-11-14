<?php

/**
 * Pipelinq BookingFiguresService.
 *
 * How busy an appointment service or resource is: the figures the service and
 * resource pages show (REQ-SRG-003, REQ-SRG-004). A service's figure is its
 * bookings starting this week and its no-shows this month; a resource's figure
 * is its bookings this week and the share of its bookable time (working hours
 * this week minus vacations) those bookings fill. Each page gets its figures
 * from one call; all data is read from OpenRegister.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 *
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-service-page-shows-how-busy-the-service-is-req-srg-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Util\NullablePayloadToArray;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Week and month figures for appointment services and resources.
 *
 * A booking that was cancelled or rescheduled did not take place, so it is
 * neither counted nor filling bookable time. A no-show did take its slot and is
 * counted. "This week" is the ISO week (Monday 00:00 up to the next Monday) and
 * "this month" the calendar month, both in the server's time zone.
 *
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-resource-page-shows-how-much-of-its-bookable-time-is-booked-req-srg-004
 */
class BookingFiguresService {
	use NullablePayloadToArray;

	/**
	 * App-config key of the booking schema.
	 *
	 * @var string
	 */
	public const BOOKING_SCHEMA_KEY = 'booking_schema';

	/**
	 * App-config key of the resource schema.
	 *
	 * @var string
	 */
	public const RESOURCE_SCHEMA_KEY = 'resource_schema';

	/**
	 * Booking statuses of bookings that did not take place.
	 *
	 * @var array<int, string>
	 */
	private const NOT_TAKING_PLACE = ['cancelled-by-customer', 'cancelled-by-business', 'rescheduled'];

	/**
	 * Week day names in ISO order (Monday first), as resource working hours name them.
	 *
	 * @var array<int, string>
	 */
	private const WEEK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     Holds the register and schema ids.
	 * @param ITimeFactory           $timeFactory   The current time.
	 * @param LoggerInterface        $logger        Logs failed reads.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * A service's bookings starting this week and its no-shows this month.
	 *
	 * @param string $serviceId The service id.
	 *
	 * @return array{bookingsThisWeek: int, noShowsThisMonth: int}
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-service-page-shows-how-busy-the-service-is-req-srg-003
	 */
	public function serviceFigures(string $serviceId): array {
		[$weekStart, $weekEnd] = $this->week();
		$monthStart = $this->now()->modify('first day of this month')->setTime(0, 0);
		$monthEnd = $monthStart->modify('+1 month');

		$thisWeek = 0;
		$noShows = 0;
		foreach ($this->bookings(filters: ['serviceId' => $serviceId]) as $booking) {
			if ((string)($booking['serviceId'] ?? '') !== $serviceId || $this->takesPlace(booking: $booking) === false) {
				continue;
			}

			$startAt = $this->moment(value: ($booking['startAt'] ?? null));
			if ($startAt === null) {
				continue;
			}

			if ($startAt >= $weekStart && $startAt < $weekEnd) {
				$thisWeek++;
			}

			if (($booking['status'] ?? null) === 'no-show' && $startAt >= $monthStart && $startAt < $monthEnd) {
				$noShows++;
			}
		}

		return ['bookingsThisWeek' => $thisWeek, 'noShowsThisMonth' => $noShows];
	}//end serviceFigures()

	/**
	 * A resource's bookings this week and the share of its bookable time they fill.
	 *
	 * Bookable time is the resource's working hours on each day of this week,
	 * skipping the days a vacation covers. The share is a whole percentage of
	 * booked over bookable minutes, and null when there is no bookable time, so
	 * a resource on vacation all week shows its count without a share.
	 *
	 * @param string $resourceId The resource id.
	 *
	 * @return array{bookingsThisWeek: int, bookedMinutes: int, bookableMinutes: int, bookedShare: int|null}
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-resource-page-shows-how-much-of-its-bookable-time-is-booked-req-srg-004
	 */
	public function resourceFigures(string $resourceId): array {
		[$weekStart, $weekEnd] = $this->week();

		$count = 0;
		$booked = 0;
		foreach ($this->bookings(filters: []) as $booking) {
			if ($this->takesPlace(booking: $booking) === false) {
				continue;
			}

			$assignments = $this->assignmentsOf(booking: $booking, resourceId: $resourceId);
			if ($assignments === []) {
				continue;
			}

			$startAt = $this->moment(value: ($booking['startAt'] ?? null));
			if ($startAt !== null && $startAt >= $weekStart && $startAt < $weekEnd) {
				$count++;
			}

			foreach ($assignments as $assignment) {
				$booked += $this->minutesWithin(assignment: $assignment, from: $weekStart, until: $weekEnd);
			}
		}

		$bookable = $this->bookableMinutes(resource: $this->resource(resourceId: $resourceId), weekStart: $weekStart);
		$share = null;
		if ($bookable > 0) {
			$share = (int)round(($booked / $bookable) * 100);
		}

		return [
			'bookingsThisWeek' => $count,
			'bookedMinutes' => $booked,
			'bookableMinutes' => $bookable,
			'bookedShare' => $share,
		];
	}//end resourceFigures()

	/**
	 * The minutes a resource can be booked in the week starting at the given Monday.
	 *
	 * @param array<string, mixed>|null $resource  The resource, null when unreadable.
	 * @param DateTimeImmutable         $weekStart Monday 00:00 of the week.
	 *
	 * @return int
	 */
	private function bookableMinutes(?array $resource, DateTimeImmutable $weekStart): int {
		if ($resource === null || is_array($resource['workingHours'] ?? null) === false) {
			return 0;
		}

		$total = 0;
		foreach (self::WEEK_DAYS as $offset => $weekDay) {
			$date = $weekStart->modify('+' . $offset . ' days')->format('Y-m-d');
			if ($this->isOnVacation(resource: $resource, date: $date) === true) {
				continue;
			}

			foreach ($resource['workingHours'] as $entry) {
				if (is_array($entry) === false || strtolower((string)($entry['day'] ?? '')) !== $weekDay) {
					continue;
				}

				$open = $this->minutesOfClock(time: (string)($entry['openTime'] ?? ''));
				$close = $this->minutesOfClock(time: (string)($entry['closeTime'] ?? ''));
				if ($open !== null && $close !== null && $close > $open) {
					$total += ($close - $open);
				}
			}
		}

		return $total;
	}//end bookableMinutes()

	/**
	 * Whether one of the resource's vacations covers the date, both ends
	 * inclusive; a vacation without an end covers its start day.
	 *
	 * @param array<string, mixed> $resource The resource.
	 * @param string               $date     ISO date `YYYY-MM-DD`.
	 *
	 * @return bool
	 */
	private function isOnVacation(array $resource, string $date): bool {
		$vacations = ($resource['vacations'] ?? []);
		if (is_array($vacations) === false) {
			return false;
		}

		foreach ($vacations as $vacation) {
			if (is_array($vacation) === false) {
				continue;
			}

			$start = substr((string)($vacation['startDate'] ?? ''), 0, 10);
			$end = substr((string)($vacation['endDate'] ?? ''), 0, 10);
			if ($end === '') {
				// A vacation without an end covers its start day.
				$end = $start;
			}

			if ($start !== '' && $date >= $start && $date <= $end) {
				return true;
			}
		}

		return false;
	}//end isOnVacation()

	/**
	 * Minutes from midnight of a `HH:MM` clock time.
	 *
	 * @param string $time The clock time.
	 *
	 * @return int|null Null when it is not a clock time.
	 */
	private function minutesOfClock(string $time): ?int {
		if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $match) !== 1) {
			return null;
		}

		$hours = (int)$match[1];
		$minutes = (int)$match[2];
		if ($hours > 24 || $minutes > 59) {
			return null;
		}

		return min(1440, ($hours * 60) + $minutes);
	}//end minutesOfClock()

	/**
	 * The minutes of an assignment that fall inside a window.
	 *
	 * @param array<string, mixed> $assignment A resource assignment of a booking.
	 * @param DateTimeImmutable    $from       Window start, inclusive.
	 * @param DateTimeImmutable    $until      Window end, exclusive.
	 *
	 * @return int
	 */
	private function minutesWithin(array $assignment, DateTimeImmutable $from, DateTimeImmutable $until): int {
		$start = $this->moment(value: ($assignment['startAt'] ?? null));
		$end = $this->moment(value: ($assignment['endAt'] ?? null));
		if ($start === null || $end === null) {
			return 0;
		}

		$start = max($start, $from);
		$end = min($end, $until);
		if ($end <= $start) {
			return 0;
		}

		return intdiv(($end->getTimestamp() - $start->getTimestamp()), 60);
	}//end minutesWithin()

	/**
	 * The booking's assignments to the given resource.
	 *
	 * @param array<string, mixed> $booking    The booking.
	 * @param string               $resourceId The resource id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function assignmentsOf(array $booking, string $resourceId): array {
		$assignments = ($booking['resourceAssignments'] ?? []);
		if (is_array($assignments) === false) {
			return [];
		}

		$out = [];
		foreach ($assignments as $assignment) {
			if (is_array($assignment) === true && (string)($assignment['resourceId'] ?? '') === $resourceId) {
				$out[] = $assignment;
			}
		}

		return $out;
	}//end assignmentsOf()

	/**
	 * Whether a booking takes (or took) place.
	 *
	 * @param array<string, mixed> $booking The booking.
	 *
	 * @return bool
	 */
	private function takesPlace(array $booking): bool {
		return in_array(($booking['status'] ?? null), self::NOT_TAKING_PLACE, true) === false;
	}//end takesPlace()

	/**
	 * A date-time value as a moment, in the server's time zone when it names none.
	 *
	 * @param mixed $value An ISO 8601 date-time.
	 *
	 * @return DateTimeImmutable|null Null when it is not a date-time.
	 */
	private function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value, $this->now()->getTimezone());
		} catch (\Exception $e) {
			return null;
		}
	}//end moment()

	/**
	 * Monday 00:00 of this week and of the next.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 */
	private function week(): array {
		$start = $this->now()->modify('monday this week')->setTime(0, 0);

		return [$start, $start->modify('+7 days')];
	}//end week()

	/**
	 * The current time.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return $this->timeFactory->now();
	}//end now()

	/**
	 * The stored bookings matching equality filters; none when unconfigured or unreadable.
	 *
	 * @param array<string, string> $filters The record filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function bookings(array $filters): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::BOOKING_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters)]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: booking list failed for booking figures', ['exception' => $e]);
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$booking = $this->toArray(object: $row);
			if ($booking !== null) {
				$out[] = $booking;
			}
		}

		return $out;
	}//end bookings()

	/**
	 * One resource; null when unconfigured, absent or unreadable.
	 *
	 * @param string $resourceId The resource id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resource(string $resourceId): ?array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::RESOURCE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$row = $this->objectService->find(id: $resourceId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: resource load failed for booking figures', ['resource' => $resourceId]);
			return null;
		}

		return $this->toArray(object: $row);
	}//end resource()

	/**
	 * The configured register id.
	 *
	 * @return string Empty when unconfigured; OpenRegister is then not called unscoped.
	 */
	private function registerId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
	}//end registerId()

	/**
	 * A configured schema id.
	 *
	 * @param string $key The app-config key.
	 *
	 * @return string
	 */
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end schemaId()
}//end class
