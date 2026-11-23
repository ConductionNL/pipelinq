<?php

/**
 * Pipelinq BookingLinkService.
 *
 * What the holder of a user's personal booking link may do: see the free
 * slots of one day and book one of them into the user's calendar. A slot is
 * free when it lies within the working day, is not in the past and no event of
 * the user's own calendars overlaps it. A booking checks the slot again right
 * before it writes, so a slot taken since the page was read is refused.
 *
 * The working day is fixed for now: Monday to Friday, 09:00 to 17:00 in the
 * user's own time zone, in slots of 30 minutes, up to 60 days ahead.
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
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The free slots behind a booking link, and booking one.
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */
class BookingLinkService {

	/**
	 * The outcome of a booking that was written.
	 *
	 * @var string
	 */
	public const BOOKED = 'booked';

	/**
	 * The outcome for a link nobody holds.
	 *
	 * @var string
	 */
	public const UNKNOWN_LINK = 'unknown-link';

	/**
	 * The outcome for a missing name, a wrong email address or a start that is no moment.
	 *
	 * @var string
	 */
	public const INVALID = 'invalid';

	/**
	 * The outcome for a start that is not one of the free slots.
	 *
	 * @var string
	 */
	public const NOT_FREE = 'not-free';

	/**
	 * The outcome when the user has no calendar the booking can be written to.
	 *
	 * @var string
	 */
	public const NO_CALENDAR = 'no-calendar';

	/**
	 * Start of the working day, minutes after midnight.
	 *
	 * @var int
	 */
	private const DAY_START = 540;

	/**
	 * End of the working day, minutes after midnight.
	 *
	 * @var int
	 */
	private const DAY_END = 1020;

	/**
	 * The length of a slot, in minutes.
	 *
	 * @var int
	 */
	private const SLOT = 30;

	/**
	 * How many days ahead a slot can be booked.
	 *
	 * @var int
	 */
	private const DAYS_AHEAD = 60;

	/**
	 * The longest name a booker can give.
	 *
	 * @var int
	 */
	private const NAME_MAX = 120;

	/**
	 * Constructor.
	 *
	 * @param BookingLinkTokens $tokens   Finds the user behind a link.
	 * @param OwnCalendar       $calendar The user's own calendars.
	 * @param ITimeFactory      $time     The clock.
	 * @param BookingLinkOwner  $owners   The user's time zone and language.
	 */
	public function __construct(
		private readonly BookingLinkTokens $tokens,
		private readonly OwnCalendar $calendar,
		private readonly ITimeFactory $time,
		private readonly BookingLinkOwner $owners,
	) {
	}//end __construct()

	/**
	 * The free slots of one day behind a link, or null for a link nobody holds.
	 *
	 * A day that is not a working day, lies in the past or too far ahead, or
	 * is not a `YYYY-MM-DD` date has no slots.
	 *
	 * @param string $token The token from the link.
	 * @param string $date  The day, `YYYY-MM-DD`, in the user's time zone.
	 *
	 * @return array<int, array{start: string, end: string}>|null The slots, earliest first, as ISO-8601 with the user's offset.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	public function slots(string $token, string $date): ?array {
		$owner = $this->tokens->ownerOf(token: $token);
		if ($owner === null) {
			return null;
		}

		return $this->freeSlots(owner: $owner, date: $date);
	}//end slots()

	/**
	 * Book one free slot into the calendar of the link's owner.
	 *
	 * @param string $token The token from the link.
	 * @param string $start The slot's start, ISO-8601 with an offset, as `slots()` gave it.
	 * @param string $name  The booker's name.
	 * @param string $email The booker's email address.
	 *
	 * @return string One of the outcome constants of this class.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	public function book(string $token, string $start, string $name, string $email): string {
		$owner = $this->tokens->ownerOf(token: $token);
		if ($owner === null) {
			return self::UNKNOWN_LINK;
		}

		$name = trim($name);
		$email = trim($email);
		$moment = $this->parseStart(start: $start, owner: $owner);
		if ($name === '' || mb_strlen($name) > self::NAME_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $moment === null) {
			return self::INVALID;
		}

		$offered = array_column($this->freeSlots(owner: $owner, date: $moment->format('Y-m-d')), 'start');
		if (in_array($moment->format(DATE_ATOM), $offered, true) === false) {
			return self::NOT_FREE;
		}

		$l10n = $this->owners->translations(owner: $owner);
		$written = $this->calendar->book(
			userId: $owner,
			start: $moment,
			end: $moment->modify('+' . self::SLOT . ' minutes'),
			text: [
				'summary' => $l10n->t('Meeting with %s', [$name]),
				'description' => $l10n->t('Booked through your booking link by %1$s (%2$s).', [$name, $email]),
			]
		);
		if ($written === false) {
			return self::NO_CALENDAR;
		}

		return self::BOOKED;
	}//end book()

	/**
	 * The free slots of one day of the owner.
	 *
	 * @param string $owner The owner's uid.
	 * @param string $date  The day, `YYYY-MM-DD`.
	 *
	 * @return array<int, array{start: string, end: string}> The slots.
	 */
	private function freeSlots(string $owner, string $date): array {
		$midnight = $this->bookableDay(owner: $owner, date: $date);
		if ($midnight === null) {
			return [];
		}

		$dayStart = $midnight->modify('+' . self::DAY_START . ' minutes');
		$dayEnd = $midnight->modify('+' . self::DAY_END . ' minutes');
		$busy = $this->calendar->busy(userId: $owner, from: $dayStart, until: $dayEnd);
		$now = $this->time->getTime();

		$slots = [];
		for ($slot = $dayStart; $slot < $dayEnd; $slot = $slot->modify('+' . self::SLOT . ' minutes')) {
			$end = $slot->modify('+' . self::SLOT . ' minutes');
			if ($slot->getTimestamp() < $now || self::overlaps(start: $slot->getTimestamp(), end: $end->getTimestamp(), busy: $busy) === true) {
				continue;
			}

			$slots[] = ['start' => $slot->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM)];
		}

		return $slots;
	}//end freeSlots()

	/**
	 * Midnight of a working day from today up to the booking horizon, in the owner's zone.
	 *
	 * @param string $owner The owner's uid.
	 * @param string $date  The day, `YYYY-MM-DD`.
	 *
	 * @return DateTimeImmutable|null Midnight, or null for a day that cannot be booked.
	 */
	private function bookableDay(string $owner, string $date): ?DateTimeImmutable {
		$zone = $this->owners->zone(owner: $owner);
		$day = date_create_immutable_from_format('!Y-m-d', $date, $zone);
		if ($day === false || $day->format('Y-m-d') !== $date || (int)$day->format('N') > 5) {
			return null;
		}

		$today = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($zone)->setTime(0, 0);
		if ($day < $today || $day > $today->modify('+' . self::DAYS_AHEAD . ' days')) {
			return null;
		}

		return $day;
	}//end bookableDay()

	/**
	 * The start a booker sent, in the owner's zone, or null when it is no moment.
	 *
	 * @param string $start ISO-8601 with an offset.
	 * @param string $owner The owner's uid.
	 *
	 * @return DateTimeImmutable|null The start.
	 */
	private function parseStart(string $start, string $owner): ?DateTimeImmutable {
		$moment = date_create_immutable_from_format(DATE_ATOM, trim($start));
		if ($moment === false) {
			return null;
		}

		return $moment->setTimezone($this->owners->zone(owner: $owner));
	}//end parseStart()

	/**
	 * Whether a range overlaps any busy range.
	 *
	 * @param int                               $start Start, Unix timestamp.
	 * @param int                               $end   End, Unix timestamp.
	 * @param array<int, array{0: int, 1: int}> $busy  The busy ranges.
	 *
	 * @return bool True when the range cannot be booked.
	 */
	private static function overlaps(int $start, int $end, array $busy): bool {
		foreach ($busy as [$from, $until]) {
			if ($from < $end && $until > $start) {
				return true;
			}
		}

		return false;
	}//end overlaps()
}//end class
