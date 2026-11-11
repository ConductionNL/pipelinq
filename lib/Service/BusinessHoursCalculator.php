<?php

/**
 * Pipelinq BusinessHoursCalculator.
 *
 * Computes deadline arithmetic for SLA targets, honouring 24x7,
 * business-hours and extended-business-hours calendar windows, and
 * skipping weekends + holidays.
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

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Calendar-aware deadline calculator.
 *
 * Three calendar modes:
 *  - `24x7` — wall-clock addition.
 *  - `business-hours` — Mon-Fri inside a configurable window
 *    (default 09:00-17:00), skipping weekends and holidays.
 *  - `extended-business-hours` — Mon-Fri 08:00-18:00 + Sat 09:00-13:00.
 *
 * The class operates on minute precision (seconds floored) to keep the
 * loop cheap; SLA deadlines are by spec rounded down anyway.
 *
 * @spec openspec/changes/sla-engine-and-escalation/tasks.md#3.3
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BusinessHoursCalculator {
	public const CALENDAR_24X7 = '24x7';
	public const CALENDAR_BUSINESS = 'business-hours';
	public const CALENDAR_EXT_BUSINESS = 'extended-business-hours';

	/**
	 * Constructor.
	 *
	 * @param HolidayCalendarService $holidays Holiday lookup service.
	 * @param IAppConfig $appConfig App configuration.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private HolidayCalendarService $holidays,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add a duration to a start time using the configured calendar mode.
	 *
	 * @param string $calendarType Calendar mode.
	 * @param DateTimeInterface $startTime Start instant.
	 * @param DateInterval $duration Duration to add.
	 * @param string $holidayCalendar Holiday calendar slug.
	 *
	 * @return DateTimeImmutable End instant (immutable copy).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface is a stdlib factory, not injectable.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function addDuration(
		string $calendarType,
		DateTimeInterface $startTime,
		DateInterval $duration,
		string $holidayCalendar,
	): DateTimeImmutable {
		$start = DateTimeImmutable::createFromInterface($startTime);

		if ($calendarType === self::CALENDAR_24X7) {
			return $start->add($duration);
		}

		$minutes = $this->intervalToMinutes(duration: $duration);
		if ($minutes <= 0) {
			return $start;
		}

		return $this->advanceWithinWindow(
			calendar: new BusinessCalendar(type: $calendarType, holidayCalendar: $holidayCalendar),
			work: new RemainingMinutes(from: $start, minutes: $minutes)
		);
	}//end addDuration()

	/**
	 * Convert a DateInterval to whole minutes (floored).
	 *
	 * @param DateInterval $duration Duration.
	 *
	 * @return int Minutes.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function intervalToMinutes(DateInterval $duration): int {
		// Convert by reference instant — DateInterval can mix m/y with d/h.
		$ref = new DateTimeImmutable('@0', new DateTimeZone('UTC'));
		$next = $ref->add($duration);
		return (int)floor(($next->getTimestamp() - $ref->getTimestamp()) / 60);
	}//end intervalToMinutes()

	/**
	 * Compute the configured business-hours window.
	 *
	 * @return array{start: string, end: string} HH:MM window.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function getBusinessHoursWindow(): array {
		$start = $this->appConfig->getValueString(Application::APP_ID, 'sla_business_hours_start', '09:00');
		$end = $this->appConfig->getValueString(Application::APP_ID, 'sla_business_hours_end', '17:00');
		if (preg_match('/^\d{2}:\d{2}$/', $start) !== 1) {
			$start = '09:00';
		}

		if (preg_match('/^\d{2}:\d{2}$/', $end) !== 1) {
			$end = '17:00';
		}

		return ['start' => $start, 'end' => $end];
	}//end getBusinessHoursWindow()

	/**
	 * Compute elapsed business minutes between two instants.
	 *
	 * Used by the SLA engine to charge paused time + to derive
	 * `consumedPercentage` for partially-elapsed targets.
	 *
	 * @param DateTimeInterface $start Start instant.
	 * @param DateTimeInterface $end End instant.
	 * @param string $holidayCalendar Holiday calendar slug.
	 * @param string $calendarType Calendar mode (24x7 / business-hours / extended).
	 *
	 * @return int Elapsed minutes (>=0).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface is a stdlib factory, not injectable.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function elapsedBusinessMinutes(
		DateTimeInterface $start,
		DateTimeInterface $end,
		string $holidayCalendar,
		string $calendarType = self::CALENDAR_BUSINESS,
	): int {
		if ($calendarType === self::CALENDAR_24X7) {
			return max(0, (int)floor(($end->getTimestamp() - $start->getTimestamp()) / 60));
		}

		if ($end <= $start) {
			return 0;
		}

		$calendar = new BusinessCalendar(type: $calendarType, holidayCalendar: $holidayCalendar);
		$cursor = DateTimeImmutable::createFromInterface($start);
		$endStamp = $end->getTimestamp();
		$total = 0;

		// Step forward day-by-day, capping each window contribution.
		while ($cursor->getTimestamp() < $endStamp) {
			$total += $this->minutesOpenOn(calendar: $calendar, span: new DaySpan(from: $cursor, untilStamp: $endStamp));
			$cursor = $this->nextDayStart(day: $cursor);
		}

		return $total;
	}//end elapsedBusinessMinutes()

	/**
	 * The open minutes of one day within the span counted.
	 *
	 * @param BusinessCalendar $calendar The calendar.
	 * @param DaySpan          $span     From an instant on the day up to the end of the count.
	 *
	 * @return int The open minutes (floored).
	 */
	private function minutesOpenOn(BusinessCalendar $calendar, DaySpan $span): int {
		[$winStart, $winEnd] = $this->dayWindow(calendar: $calendar, day: $span->from);
		if ($winStart === null || $winEnd === null) {
			return 0;
		}

		$segmentStart = max($span->from->getTimestamp(), $winStart->getTimestamp());
		$segmentEnd = min($span->untilStamp, $winEnd->getTimestamp());
		if ($segmentEnd > $segmentStart) {
			return (int)floor(($segmentEnd - $segmentStart) / 60);
		}

		return 0;
	}//end minutesOpenOn()

	/**
	 * Walk forward through business-hours windows until the requested
	 * minute count has been consumed.
	 *
	 * @param BusinessCalendar $calendar The calendar.
	 * @param RemainingMinutes $work     The start instant and the minutes to consume.
	 *
	 * @return DateTimeImmutable End instant.
	 */
	private function advanceWithinWindow(BusinessCalendar $calendar, RemainingMinutes $work): DateTimeImmutable {
		// Safety: bound at 365 day iterations to prevent infinite loop on
		// misconfiguration (e.g. all-holiday calendar).
		$maxLoops = 365 * 2;

		while ($work->minutes > 0 && $maxLoops-- > 0) {
			$next = $this->advanceOneDay(calendar: $calendar, work: $work);
			if ($next instanceof DateTimeImmutable) {
				return $next;
			}

			$work = $next;
		}

		if ($maxLoops <= 0) {
			$this->logger->error(
				'BusinessHoursCalculator: hit safety bound advancing deadline; calendar likely misconfigured',
				['calendarType' => $calendar->type, 'holidayCalendar' => $calendar->holidayCalendar]
			);
		}

		return $work->from;
	}//end advanceWithinWindow()

	/**
	 * Consume one day's window: the deadline when it fits, else what is left from the window end.
	 *
	 * @param BusinessCalendar $calendar The calendar.
	 * @param RemainingMinutes $work     Where the walk stands.
	 *
	 * @return DateTimeImmutable|RemainingMinutes The deadline, or the walk's next position.
	 */
	private function advanceOneDay(BusinessCalendar $calendar, RemainingMinutes $work): DateTimeImmutable|RemainingMinutes {
		$window = $this->dayWindow(calendar: $calendar, day: $work->from);
		$cursor = $this->openCursor(cursor: $work->from, window: $window);
		if ($cursor === null) {
			// Non-business day, or past the window end; jump to next day start.
			return new RemainingMinutes(from: $this->nextDayStart(day: $work->from), minutes: $work->minutes);
		}

		$winEnd = $window[1];
		$availMinutes = (int)floor(($winEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
		if ($availMinutes >= $work->minutes) {
			$advanced = $cursor->modify('+' . $work->minutes . ' minutes');
			assert($advanced !== false);
			return $advanced;
		}

		return new RemainingMinutes(from: $winEnd, minutes: ($work->minutes - $availMinutes));
	}//end advanceOneDay()

	/**
	 * Where the walk can start consuming a day's window: the later of the cursor
	 * and the window start, or null on a closed day or past the window end.
	 *
	 * @param DateTimeImmutable                                        $cursor The walk's instant.
	 * @param array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} $window The day's window.
	 *
	 * @return DateTimeImmutable|null The instant to consume from.
	 */
	private function openCursor(DateTimeImmutable $cursor, array $window): ?DateTimeImmutable {
		if (in_array(null, $window, true) === true) {
			return null;
		}

		[$winStart, $winEnd] = $window;
		// Position cursor at max(cursor, winStart).
		if ($cursor < $winStart) {
			$cursor = $winStart;
		}

		if ($cursor >= $winEnd) {
			return null;
		}

		return $cursor;
	}//end openCursor()

	/**
	 * Midnight at the start of the next day.
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return DateTimeImmutable The next day's start.
	 */
	private function nextDayStart(DateTimeImmutable $day): DateTimeImmutable {
		return $day->modify('+1 day')->setTime(0, 0);
	}//end nextDayStart()

	/**
	 * Compute the business-hours window for a given calendar day.
	 *
	 * Returns `[null, null]` for non-business days (weekend in the
	 * business-hours mode, or full-Sunday in extended mode, or a
	 * holiday).
	 *
	 * @param BusinessCalendar  $calendar The calendar.
	 * @param DateTimeImmutable $day      Day cursor.
	 *
	 * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} Window start, end.
	 */
	private function dayWindow(BusinessCalendar $calendar, DateTimeImmutable $day): array {
		$dow = (int)$day->format('N');
		// 1=Mon..7=Sun.
		if ($this->holidays->isHoliday($calendar->holidayCalendar, $day) === true) {
			return [null, null];
		}

		$hours = $this->openingHours(calendarType: $calendar->type, dow: $dow);
		if ($hours === null) {
			return [null, null];
		}

		return [
			$this->withTime(day: $day, time: $hours[0]),
			$this->withTime(day: $day, time: $hours[1]),
		];
	}//end dayWindow()

	/**
	 * The HH:MM opening hours of a weekday in a calendar mode, or null when closed.
	 *
	 * An unknown calendar type is treated as business-hours.
	 *
	 * @param string $calendarType Calendar mode.
	 * @param int    $dow          ISO weekday, 1=Mon..7=Sun.
	 *
	 * @return array{0: string, 1: string}|null Opening and closing time.
	 */
	private function openingHours(string $calendarType, int $dow): ?array {
		$win = $this->getBusinessHoursWindow();
		if ($calendarType === self::CALENDAR_EXT_BUSINESS) {
			return $this->extendedOpeningHours(dow: $dow);
		}

		if ($dow >= 6) {
			return null;
		}

		return [$win['start'], $win['end']];
	}//end openingHours()

	/**
	 * The extended-business-hours opening hours: Mon-Fri 08:00-18:00, Sat 09:00-13:00.
	 *
	 * @param int $dow ISO weekday, 1=Mon..7=Sun.
	 *
	 * @return array{0: string, 1: string}|null Opening and closing time, or null on Sunday.
	 */
	private function extendedOpeningHours(int $dow): ?array {
		if ($dow <= 5) {
			return ['08:00', '18:00'];
		}

		if ($dow === 6) {
			return ['09:00', '13:00'];
		}

		return null;
	}//end extendedOpeningHours()

	/**
	 * Build a new DateTimeImmutable on the same date with the given HH:MM.
	 *
	 * @param DateTimeImmutable $day Day anchor.
	 * @param string $time HH:MM string.
	 *
	 * @return DateTimeImmutable New instant.
	 */
	private function withTime(DateTimeImmutable $day, string $time): DateTimeImmutable {
		[$hours, $minutes] = array_map('intval', explode(':', $time));
		return $day->setTime($hours, $minutes, 0);
	}//end withTime()
}//end class
