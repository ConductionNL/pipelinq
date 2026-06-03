<?php

/**
 * Pipelinq BusinessHoursCalculator.
 *
 * Calendar-aware date math for the SLA engine: adds an elapsed duration to a
 * start time while respecting the business-hours window, weekends and holidays,
 * and measures elapsed business time between two instants.
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
 * @link https://codeberg.org/Conduction/pipelinq
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Computes calendar-aware deadlines and elapsed business time (REQ-002).
 *
 * Three calendar modes are supported:
 * - `24x7`: wall-clock; durations add directly, holidays/weekends ignored.
 * - `business-hours`: Mon-Fri inside the configured window (default 09:00-17:00),
 *   skipping weekends and holidays.
 * - `extended-business-hours`: Mon-Fri 08:00-18:00 plus Sat 09:00-13:00,
 *   skipping holidays.
 *
 * All arithmetic walks the clock in whole minutes to stay deterministic and
 * timezone-stable; the platform timezone library handles DST transparently.
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
 */
class BusinessHoursCalculator
{
    /**
     * App-config key for the business-hours window start (HH:MM).
     *
     * @var string
     */
    public const CONFIG_START = 'sla_business_hours_start';

    /**
     * App-config key for the business-hours window end (HH:MM).
     *
     * @var string
     */
    public const CONFIG_END = 'sla_business_hours_end';

    /**
     * Default business-hours window start.
     *
     * @var string
     */
    private const DEFAULT_START = '09:00';

    /**
     * Default business-hours window end.
     *
     * @var string
     */
    private const DEFAULT_END = '17:00';

    /**
     * Calendar mode: wall-clock 24x7.
     *
     * @var string
     */
    public const CALENDAR_24X7 = '24x7';

    /**
     * Calendar mode: standard business hours.
     *
     * @var string
     */
    public const CALENDAR_BUSINESS = 'business-hours';

    /**
     * Calendar mode: extended business hours.
     *
     * @var string
     */
    public const CALENDAR_EXTENDED = 'extended-business-hours';

    /**
     * Constructor.
     *
     * @param HolidayCalendarService $holidays  The holiday calendar service.
     * @param IAppConfig             $appConfig The app configuration.
     */
    public function __construct(
        private HolidayCalendarService $holidays,
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Read the configured business-hours window.
     *
     * @return array{start: string, end: string} The HH:MM window.
     */
    public function getBusinessHoursWindow(): array
    {
        $start = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_START, self::DEFAULT_START);
        $end   = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_END, self::DEFAULT_END);

        if (preg_match('/^\d{2}:\d{2}$/', $start) !== 1) {
            $start = self::DEFAULT_START;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $end) !== 1) {
            $end = self::DEFAULT_END;
        }

        return ['start' => $start, 'end' => $end];
    }//end getBusinessHoursWindow()

    /**
     * Add an elapsed duration to a start time under the given calendar mode.
     *
     * For 24x7 the duration is added directly. For business and extended modes
     * the calculator advances through the clock and only counts minutes that
     * fall inside a working window on a working day (weekends and holidays are
     * skipped), so a 4-business-hour target opened at Friday 17:00 lands on the
     * following Monday morning.
     *
     * @param string            $calendarType    The calendar mode.
     * @param DateTimeImmutable $startTime       The start instant.
     * @param DateInterval      $duration        The elapsed duration to add.
     * @param string            $holidayCalendar The holiday calendar spec ("none" to disable).
     *
     * @return DateTimeImmutable The computed deadline.
     */
    public function addDuration(
        string $calendarType,
        DateTimeImmutable $startTime,
        DateInterval $duration,
        string $holidayCalendar
    ): DateTimeImmutable {
        $totalMinutes = $this->intervalToMinutes(duration: $duration, calendarType: $calendarType, startTime: $startTime);

        if ($calendarType === self::CALENDAR_24X7) {
            return $startTime->add($duration);
        }

        return $this->advanceBusinessMinutes(
            calendarType: $calendarType,
            from: $startTime,
            minutesToConsume: $totalMinutes,
            holidayCalendar: $holidayCalendar
        );
    }//end addDuration()

    /**
     * Measure elapsed business minutes between two instants (REQ-002).
     *
     * Used when resuming a paused timer: the wall-clock pause window is converted
     * to the number of business minutes that actually elapsed so the deadline is
     * extended by working time only, not calendar time.
     *
     * @param string            $calendarType    The calendar mode.
     * @param DateTimeImmutable $startTime       The window start.
     * @param DateTimeImmutable $endTime         The window end.
     * @param string            $holidayCalendar The holiday calendar spec.
     *
     * @return int Elapsed business minutes (floored to whole minutes).
     */
    public function elapsedBusinessMinutes(
        string $calendarType,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        string $holidayCalendar
    ): int {
        if ($endTime <= $startTime) {
            return 0;
        }

        if ($calendarType === self::CALENDAR_24X7) {
            return (int) floor(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60);
        }

        $minutes = 0;
        $cursor  = $startTime;
        // Hard cap: one year of minute steps guards against pathological input.
        $maxSteps = (525600 + 1);
        for ($i = 0; $i < $maxSteps; $i++) {
            if ($cursor >= $endTime) {
                break;
            }

            if ($this->isWithinWorkingWindow(calendarType: $calendarType, moment: $cursor, holidayCalendar: $holidayCalendar) === true) {
                $minutes++;
            }

            $cursor = $cursor->modify('+1 minute');
        }//end for

        return $minutes;
    }//end elapsedBusinessMinutes()

    /**
     * Convert a DateInterval to the number of minutes to consume.
     *
     * Calendar-relative units (days/weeks/months) are resolved against the
     * actual start time so leap and DST boundaries are honoured, then converted
     * to minutes. For business-mode calendars a "day" of duration means a
     * working-day's worth of business minutes, so calendar D/W durations are
     * scaled to the business window length.
     *
     * @param DateInterval      $duration     The duration to convert.
     * @param string            $calendarType The calendar mode.
     * @param DateTimeImmutable $startTime    The start instant (for D/W resolution).
     *
     * @return int The number of minutes to consume.
     */
    private function intervalToMinutes(DateInterval $duration, string $calendarType, DateTimeImmutable $startTime): int
    {
        $hasCalendarUnits = ($duration->y > 0 || $duration->m > 0 || $duration->d > 0);

        if ($calendarType === self::CALENDAR_24X7 || $hasCalendarUnits === false) {
            $end = $startTime->add($duration);
            return (int) floor(($end->getTimestamp() - $startTime->getTimestamp()) / 60);
        }

        // Business-mode calendar with day/week/month units: a calendar "day"
        // equals one working day of business minutes; sub-day H/M/S components
        // add as literal business minutes on top.
        $dayMinutes    = $this->workingDayMinutes(calendarType: $calendarType);
        $calendarDays  = (($duration->y * 365) + ($duration->m * 30) + $duration->d);
        $subDayMinutes = (($duration->h * 60) + $duration->i + (int) floor($duration->s / 60));

        return (($calendarDays * $dayMinutes) + $subDayMinutes);
    }//end intervalToMinutes()

    /**
     * Number of business minutes in one working day for a calendar mode.
     *
     * @param string $calendarType The calendar mode.
     *
     * @return int Business minutes per working day.
     */
    private function workingDayMinutes(string $calendarType): int
    {
        if ($calendarType === self::CALENDAR_EXTENDED) {
            // Mon-Fri 08:00-18:00 = 600 minutes.
            return 600;
        }

        $window = $this->getBusinessHoursWindow();
        return ($this->minuteOfDay(time: $window['end']) - $this->minuteOfDay(time: $window['start']));
    }//end workingDayMinutes()

    /**
     * Advance from a start instant by a number of business minutes.
     *
     * @param string            $calendarType     The calendar mode.
     * @param DateTimeImmutable $from             The start instant.
     * @param int               $minutesToConsume Business minutes to consume.
     * @param string            $holidayCalendar  The holiday calendar spec.
     *
     * @return DateTimeImmutable The instant reached after consuming the minutes.
     */
    private function advanceBusinessMinutes(
        string $calendarType,
        DateTimeImmutable $from,
        int $minutesToConsume,
        string $holidayCalendar
    ): DateTimeImmutable {
        if ($minutesToConsume <= 0) {
            return $from;
        }

        $cursor    = $from;
        $remaining = $minutesToConsume;
        // Cap: at most ~3 years of minute steps to avoid an unbounded loop on
        // a misconfigured (always-closed) calendar.
        $maxSteps = (1576800 + 1);
        for ($i = 0; $i < $maxSteps; $i++) {
            if ($remaining <= 0) {
                break;
            }

            if ($this->isWithinWorkingWindow(calendarType: $calendarType, moment: $cursor, holidayCalendar: $holidayCalendar) === true) {
                $remaining--;
            }

            $cursor = $cursor->modify('+1 minute');
        }//end for

        return $cursor;
    }//end advanceBusinessMinutes()

    /**
     * Test whether an instant falls inside a working window on a working day.
     *
     * The window is half-open [start, end): a moment exactly at the closing time
     * is NOT counted, so the last consumable minute is end-1.
     *
     * @param string            $calendarType    The calendar mode.
     * @param DateTimeImmutable $moment          The instant to test.
     * @param string            $holidayCalendar The holiday calendar spec.
     *
     * @return bool True when the instant is working time.
     */
    public function isWithinWorkingWindow(string $calendarType, DateTimeImmutable $moment, string $holidayCalendar): bool
    {
        if ($calendarType === self::CALENDAR_24X7) {
            return true;
        }

        $dayOfWeek = (int) $moment->format('N');
        if ($this->holidays->isHoliday(calendarSpec: $holidayCalendar, date: $moment) === true) {
            return false;
        }

        $minuteOfDay = ((int) $moment->format('G') * 60) + (int) $moment->format('i');

        if ($calendarType === self::CALENDAR_EXTENDED) {
            return $this->isWithinExtendedWindow(dayOfWeek: $dayOfWeek, minuteOfDay: $minuteOfDay);
        }

        // Standard business hours, Mon-Fri only.
        if ($dayOfWeek >= 6) {
            return false;
        }

        $window = $this->getBusinessHoursWindow();
        return $this->between(minuteOfDay: $minuteOfDay, start: $window['start'], end: $window['end']);
    }//end isWithinWorkingWindow()

    /**
     * Test an instant against the extended-business-hours windows.
     *
     * Mon-Fri 08:00-18:00, Sat 09:00-13:00, closed Sunday.
     *
     * @param int $dayOfWeek   ISO day of week (1=Mon .. 7=Sun).
     * @param int $minuteOfDay Minutes since midnight.
     *
     * @return bool True when the instant is extended working time.
     */
    private function isWithinExtendedWindow(int $dayOfWeek, int $minuteOfDay): bool
    {
        if ($dayOfWeek === 7) {
            return false;
        }

        if ($dayOfWeek === 6) {
            return $this->between(minuteOfDay: $minuteOfDay, start: '09:00', end: '13:00');
        }

        return $this->between(minuteOfDay: $minuteOfDay, start: '08:00', end: '18:00');
    }//end isWithinExtendedWindow()

    /**
     * Whether a minute-of-day falls in the half-open window [start, end).
     *
     * @param int    $minuteOfDay Minutes since midnight.
     * @param string $start       Window start (HH:MM).
     * @param string $end         Window end (HH:MM), exclusive.
     *
     * @return bool True when within the window.
     */
    private function between(int $minuteOfDay, string $start, string $end): bool
    {
        return ($minuteOfDay >= $this->minuteOfDay(time: $start) && $minuteOfDay < $this->minuteOfDay(time: $end));
    }//end between()

    /**
     * Convert an HH:MM string to minutes since midnight.
     *
     * @param string $time The HH:MM time.
     *
     * @return int Minutes since midnight.
     */
    private function minuteOfDay(string $time): int
    {
        $parts = explode(':', $time);
        $hour  = (int) ($parts[0] ?? 0);
        $min   = (int) ($parts[1] ?? 0);

        return (($hour * 60) + $min);
    }//end minuteOfDay()
}//end class
