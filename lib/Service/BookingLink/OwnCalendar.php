<?php

/**
 * Pipelinq OwnCalendar.
 *
 * The booking link's view of a user's diary, through Nextcloud's calendar
 * manager: when the user is busy, and a booked slot written into it. Only the
 * user's own calendars count; a calendar a colleague shares with them neither
 * blocks a slot nor receives a booking. An event marked free (TRANSP
 * TRANSPARENT) or cancelled does not block a slot.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\BookingLink
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\BookingLink;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;

/**
 * Busy times of, and bookings into, a user's own calendars.
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */
class OwnCalendar {

	/**
	 * The most events read for one window.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IManager $calendars The calendar manager.
	 */
	public function __construct(
		private readonly IManager $calendars,
	) {
	}//end __construct()

	/**
	 * The busy ranges of the user's own calendars that overlap the window.
	 *
	 * @param string            $userId The user.
	 * @param DateTimeImmutable $from   Start of the window.
	 * @param DateTimeImmutable $until  End of the window.
	 *
	 * @return array<int, array{0: int, 1: int}> Per busy event: start and end as Unix timestamps.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	public function busy(string $userId, DateTimeImmutable $from, DateTimeImmutable $until): array {
		$principal = 'principals/users/' . $userId;
		$own = array_map(
			static fn (ICalendar $calendar): string => $calendar->getUri(),
			$this->ownCalendars(principal: $principal)
		);
		if ($own === []) {
			return [];
		}

		$query = $this->calendars->newQuery($principal);
		foreach ($own as $uri) {
			$query->addSearchCalendar($uri);
		}

		$query->addType('VEVENT');
		$query->setTimerangeStart($from);
		$query->setTimerangeEnd($until);
		$query->setLimit(self::READ_LIMIT);

		$busy = [];
		foreach ($this->calendars->searchForPrincipal($query) as $result) {
			foreach ((array)($result['objects'] ?? []) as $event) {
				$range = self::busyRange(event: (array)$event);
				if ($range !== null) {
					$busy[] = $range;
				}
			}
		}

		return $busy;
	}//end busy()

	/**
	 * Write a booked slot into the user's first own writable calendar.
	 *
	 * @param string                                       $userId The user.
	 * @param DateTimeImmutable                            $start  Start of the slot.
	 * @param DateTimeImmutable                            $end    End of the slot.
	 * @param array{summary: string, description: string} $text   The event's title and description.
	 *
	 * @return bool False when the user has no calendar a booking can be written to.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	public function book(string $userId, DateTimeImmutable $start, DateTimeImmutable $end, array $text): bool {
		foreach ($this->ownCalendars(principal: 'principals/users/' . $userId) as $calendar) {
			if (($calendar instanceof ICreateFromString) === false || self::writable(calendar: $calendar) === false) {
				continue;
			}

			$this->calendars->createEventBuilder()
				->setStartDate($start)
				->setEndDate($end)
				->setSummary($text['summary'])
				->setDescription($text['description'])
				->createInCalendar($calendar);
			return true;
		}

		return false;
	}//end book()

	/**
	 * The principal's own calendars: shared and deleted calendars are left out.
	 *
	 * @param string $principal The principal uri.
	 *
	 * @return array<int, ICalendar> The calendars.
	 */
	private function ownCalendars(string $principal): array {
		$own = [];
		foreach ($this->calendars->getCalendarsForPrincipal($principal) as $calendar) {
			if ($calendar->isDeleted() === true) {
				continue;
			}

			if ($calendar instanceof ICalendarIsShared && $calendar->isShared() === true) {
				continue;
			}

			$own[] = $calendar;
		}

		return $own;
	}//end ownCalendars()

	/**
	 * Whether a calendar that takes events is not read-only.
	 *
	 * @param ICreateFromString $calendar The calendar.
	 *
	 * @return bool False for a calendar that says it is not writable.
	 */
	private static function writable(ICreateFromString $calendar): bool {
		return ($calendar instanceof ICalendarIsWritable) === false || $calendar->isWritable() === true;
	}//end writable()

	/**
	 * The range an event keeps the user busy, or null when it does not.
	 *
	 * @param array<string, mixed> $event The event's properties, as the calendar search returns them.
	 *
	 * @return array{0: int, 1: int}|null Start and end as Unix timestamps.
	 */
	private static function busyRange(array $event): ?array {
		if (strtoupper(self::text(event: $event, name: 'TRANSP')) === 'TRANSPARENT'
			|| strtoupper(self::text(event: $event, name: 'STATUS')) === 'CANCELLED'
		) {
			return null;
		}

		$start = self::moment(event: $event, name: 'DTSTART');
		if ($start === null) {
			return null;
		}

		// An event without an end, or one that ends before it starts, blocks its start only.
		$end = (self::moment(event: $event, name: 'DTEND') ?? $start);
		if ($end <= $start) {
			$end = $start + 1;
		}

		return [$start, $end];
	}//end busyRange()

	/**
	 * The first value of an event property as text.
	 *
	 * @param array<string, mixed> $event The event's properties.
	 * @param string               $name  The property.
	 *
	 * @return string The value, or ''.
	 */
	private static function text(array $event, string $name): string {
		$value = ((($event[$name] ?? [])[0] ?? [])[0] ?? '');
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end text()

	/**
	 * The first value of a date-time event property as a Unix timestamp.
	 *
	 * @param array<string, mixed> $event The event's properties.
	 * @param string               $name  The property.
	 *
	 * @return int|null The moment, or null when the event has none.
	 */
	private static function moment(array $event, string $name): ?int {
		$value = ((($event[$name] ?? [])[0] ?? [])[0] ?? null);
		if ($value instanceof DateTimeInterface) {
			return $value->getTimestamp();
		}

		return null;
	}//end moment()
}//end class
