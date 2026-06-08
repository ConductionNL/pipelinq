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
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
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
use Throwable;

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
class BusinessHoursCalculator
{
    public const CALENDAR_24X7         = '24x7';
    public const CALENDAR_BUSINESS     = 'business-hours';
    public const CALENDAR_EXT_BUSINESS = 'extended-business-hours';

    /**
     * Constructor.
     *
     * @param HolidayCalendarService $holidays  Holiday lookup service.
     * @param IAppConfig             $appConfig App configuration.
     * @param LoggerInterface        $logger    PSR logger.
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
     * @param string            $calendarType    Calendar mode.
     * @param DateTimeInterface $startTime       Start instant.
     * @param DateInterval      $duration        Duration to add.
     * @param string            $holidayCalendar Holiday calendar slug.
     *
     * @return DateTimeImmutable End instant (immutable copy).
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
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
            calendarType: $calendarType,
            start: $start,
            minutesNeeded: $minutes,
            holidayCalendar: $holidayCalendar
        );
    }//end addDuration()

    /**
     * Convert a DateInterval to whole minutes (floored).
     *
     * @param DateInterval $duration Duration.
     *
     * @return int Minutes.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
     */
    public function intervalToMinutes(DateInterval $duration): int
    {
        // Convert by reference instant — DateInterval can mix m/y with d/h.
        $ref  = new DateTimeImmutable('@0', new DateTimeZone('UTC'));
        $next = $ref->add($duration);
        return (int) floor(($next->getTimestamp() - $ref->getTimestamp()) / 60);
    }//end intervalToMinutes()

    /**
     * Compute the configured business-hours window.
     *
     * @return array{start: string, end: string} HH:MM window.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
     */
    public function getBusinessHoursWindow(): array
    {
        $start = $this->appConfig->getValueString(Application::APP_ID, 'sla_business_hours_start', '09:00');
        $end   = $this->appConfig->getValueString(Application::APP_ID, 'sla_business_hours_end', '17:00');
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
     * @param DateTimeInterface $start           Start instant.
     * @param DateTimeInterface $end             End instant.
     * @param string            $holidayCalendar Holiday calendar slug.
     * @param string            $calendarType    Calendar mode (24x7 / business-hours / extended).
     *
     * @return int Elapsed minutes (>=0).
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
     */
    public function elapsedBusinessMinutes(
        DateTimeInterface $start,
        DateTimeInterface $end,
        string $holidayCalendar,
        string $calendarType=self::CALENDAR_BUSINESS,
    ): int {
        if ($calendarType === self::CALENDAR_24X7) {
            return max(0, (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60));
        }

        if ($end <= $start) {
            return 0;
        }

        $cursor   = DateTimeImmutable::createFromInterface($start);
        $endStamp = $end->getTimestamp();
        $total    = 0;

        // Step forward day-by-day, capping each window contribution.
        while ($cursor->getTimestamp() < $endStamp) {
            [$winStart, $winEnd] = $this->dayWindow(
                calendarType: $calendarType,
                day: $cursor,
                holidayCalendar: $holidayCalendar
            );
            if ($winStart === null || $winEnd === null) {
                $cursor = $cursor->modify('+1 day')->setTime(0, 0);
                continue;
            }

            $segmentStart = max($cursor->getTimestamp(), $winStart->getTimestamp());
            $segmentEnd   = min($endStamp, $winEnd->getTimestamp());

            if ($segmentEnd > $segmentStart) {
                $total += (int) floor(($segmentEnd - $segmentStart) / 60);
            }

            $cursor = $cursor->modify('+1 day')->setTime(0, 0);
        }

        return $total;
    }//end elapsedBusinessMinutes()

    /**
     * Walk forward through business-hours windows until the requested
     * minute count has been consumed.
     *
     * @param string            $calendarType    Calendar mode.
     * @param DateTimeImmutable $start           Start instant.
     * @param int               $minutesNeeded   Minutes left to consume.
     * @param string            $holidayCalendar Holiday calendar slug.
     *
     * @return DateTimeImmutable End instant.
     */
    private function advanceWithinWindow(
        string $calendarType,
        DateTimeImmutable $start,
        int $minutesNeeded,
        string $holidayCalendar,
    ): DateTimeImmutable {
        $cursor = $start;
        // Safety: bound at 365 day iterations to prevent infinite loop on
        // misconfiguration (e.g. all-holiday calendar).
        $maxLoops = 365 * 2;

        while ($minutesNeeded > 0 && $maxLoops-- > 0) {
            [$winStart, $winEnd] = $this->dayWindow(
                calendarType: $calendarType,
                day: $cursor,
                holidayCalendar: $holidayCalendar
            );
            if ($winStart === null || $winEnd === null) {
                // Non-business day; jump to next day start.
                $cursor = $cursor->modify('+1 day')->setTime(0, 0);
                continue;
            }

            // Position cursor at max(cursor, winStart).
            if ($cursor < $winStart) {
                $cursor = $winStart;
            }

            if ($cursor >= $winEnd) {
                // Past the window end; skip to next day.
                $cursor = $cursor->modify('+1 day')->setTime(0, 0);
                continue;
            }

            $availMinutes = (int) floor(($winEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            if ($availMinutes >= $minutesNeeded) {
                return $cursor->modify('+'.$minutesNeeded.' minutes');
            }

            $minutesNeeded -= $availMinutes;
            $cursor         = $winEnd;
        }//end while

        if ($maxLoops <= 0) {
            $this->logger->error(
                'BusinessHoursCalculator: hit safety bound advancing deadline; calendar likely misconfigured',
                ['calendarType' => $calendarType, 'holidayCalendar' => $holidayCalendar]
            );
        }

        return $cursor;
    }//end advanceWithinWindow()

    /**
     * Compute the business-hours window for a given calendar day.
     *
     * Returns `[null, null]` for non-business days (weekend in the
     * business-hours mode, or full-Sunday in extended mode, or a
     * holiday).
     *
     * @param string            $calendarType    Calendar mode.
     * @param DateTimeImmutable $day             Day cursor.
     * @param string            $holidayCalendar Holiday calendar slug.
     *
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} Window start, end.
     */
    private function dayWindow(
        string $calendarType,
        DateTimeImmutable $day,
        string $holidayCalendar,
    ): array {
        $dow = (int) $day->format('N');
        // 1=Mon..7=Sun.
        if ($this->holidays->isHoliday($holidayCalendar, $day) === true) {
            return [null, null];
        }

        $win = $this->getBusinessHoursWindow();
        if ($calendarType === self::CALENDAR_BUSINESS) {
            if ($dow >= 6) {
                return [null, null];
            }

            return [
                $this->withTime(day: $day, time: $win['start']),
                $this->withTime(day: $day, time: $win['end']),
            ];
        }

        if ($calendarType === self::CALENDAR_EXT_BUSINESS) {
            if ($dow <= 5) {
                return [
                    $this->withTime(day: $day, time: '08:00'),
                    $this->withTime(day: $day, time: '18:00'),
                ];
            }

            if ($dow === 6) {
                return [
                    $this->withTime(day: $day, time: '09:00'),
                    $this->withTime(day: $day, time: '13:00'),
                ];
            }

            return [null, null];
        }

        // Fall through: treat unknown calendar type as business-hours.
        if ($dow >= 6) {
            return [null, null];
        }

        return [
            $this->withTime(day: $day, time: $win['start']),
            $this->withTime(day: $day, time: $win['end']),
        ];
    }//end dayWindow()

    /**
     * Build a new DateTimeImmutable on the same date with the given HH:MM.
     *
     * @param DateTimeImmutable $day  Day anchor.
     * @param string            $time HH:MM string.
     *
     * @return DateTimeImmutable New instant.
     */
    private function withTime(DateTimeImmutable $day, string $time): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $day->setTime($h, $m, 0);
    }//end withTime()
}//end class
