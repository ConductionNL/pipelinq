<?php

/**
 * Pipelinq DutchHolidayCalendar.
 *
 * Working-day calculator used by the Berichtenbox bridge to determine
 * when the 5-day fallback email is due (REQ-FALLBACK-004). Skips
 * weekends and the official Dutch statutory holidays. Bevrijdingsdag
 * (May 5) is a public holiday only in lustrum years (years divisible
 * by 5).
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-fallback-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Computes working-day offsets in the Europe/Amsterdam calendar.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-fallback-004
 */
class DutchHolidayCalendar
{
    /**
     * Fixed-date Dutch statutory holidays (m-d => name).
     *
     * @var array<string, string>
     */
    private const FIXED_HOLIDAYS = [
        '01-01' => 'Nieuwjaarsdag',
        '04-27' => 'Koningsdag',
        '12-25' => 'Eerste Kerstdag',
        '12-26' => 'Tweede Kerstdag',
    ];

    /**
     * Tenant-injected extra holidays (Y-m-d). Optional.
     *
     * @var array<int, string>
     */
    private array $extraHolidays = [];

    /**
     * Constructor.
     *
     * @param array<int, string> $extraHolidays Optional Y-m-d dates added
     *                                          to the holiday set (tenant
     *                                          custom).
     */
    public function __construct(array $extraHolidays=[])
    {
        foreach ($extraHolidays as $extra) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $extra) !== 1) {
                throw new InvalidArgumentException(
                    'extraHolidays entries must be ISO Y-m-d strings.'
                );
            }

            $this->extraHolidays[] = $extra;
        }
    }//end __construct()

    /**
     * Return the date that is $days working days after $from.
     *
     * Working days exclude Saturday, Sunday, fixed Dutch holidays,
     * variable Dutch holidays (Pasen+Paasmaandag, Hemelvaartsdag,
     * Pinksteren+Pinkstermaandag, Goede Vrijdag, Bevrijdingsdag in
     * lustrum years), and any tenant extra-holidays.
     *
     * @param DateTimeInterface $from           Anchor date (inclusive).
     * @param int               $days           Number of working days to add (>=0).
     * @param string            $tenantTimeZone IANA TZ; default Europe/Amsterdam.
     *
     * @return DateTimeImmutable The resulting date at 00:00 in the tenant TZ.
     */
    public function addWorkingDays(
        DateTimeInterface $from,
        int $days,
        string $tenantTimeZone='Europe/Amsterdam'
    ): DateTimeImmutable {
        if ($days < 0) {
            throw new InvalidArgumentException('days MUST be >= 0.');
        }

        $tz      = new DateTimeZone($tenantTimeZone);
        $current = DateTimeImmutable::createFromInterface($from)
            ->setTimezone($tz);
        // Normalise to midnight so the "5 working days from" semantics
        // are date-comparison, not time-of-day.
        $current = $current->setTime(0, 0, 0);

        $added = 0;
        while ($added < $days) {
            $current = $current->modify('+1 day');
            if ($this->isWorkingDay(date: $current) === true) {
                $added++;
            }
        }

        return $current;
    }//end addWorkingDays()

    /**
     * Tell whether a given date is a Dutch working day.
     *
     * @param DateTimeInterface $date The date to inspect.
     *
     * @return bool True if the day is a working day.
     */
    public function isWorkingDay(DateTimeInterface $date): bool
    {
        // Saturday (6) and Sunday (7) per ISO-8601.
        $weekday = (int) $date->format('N');
        if ($weekday === 6 || $weekday === 7) {
            return false;
        }

        return $this->isHoliday(date: $date) === false;
    }//end isWorkingDay()

    /**
     * Tell whether a given date is a Dutch statutory holiday.
     *
     * @param DateTimeInterface $date The date to inspect.
     *
     * @return bool True if the day is a holiday.
     */
    public function isHoliday(DateTimeInterface $date): bool
    {
        $md   = $date->format('m-d');
        $ymd  = $date->format('Y-m-d');
        $year = (int) $date->format('Y');

        if (isset(self::FIXED_HOLIDAYS[$md]) === true) {
            return true;
        }

        if ($md === '05-05' && ($year % 5) === 0) {
            // Bevrijdingsdag is a public holiday only in lustrum years.
            return true;
        }

        if (in_array($ymd, $this->extraHolidays, true) === true) {
            return true;
        }

        $variable = $this->variableHolidaysForYear(year: $year);
        return in_array($ymd, $variable, true);
    }//end isHoliday()

    /**
     * Compute the variable (Easter-derived) Dutch holidays for a year.
     *
     * @param int $year The Gregorian year.
     *
     * @return array<int, string> Holiday dates as Y-m-d strings.
     */
    private function variableHolidaysForYear(int $year): array
    {
        // The easter_days() PHP function needs the calendar extension; fall back to a
        // pure-PHP Anonymous Gregorian implementation so the calculator
        // works in CI containers without ext-calendar.
        $easterOffset = $this->easterOffset(year: $year);
        $easter       = (new DateTimeImmutable($year.'-03-21'))
            ->modify('+'.$easterOffset.' days');

        $goedeVrijdag      = $easter->modify('-2 days');
        $tweedePaasdag     = $easter->modify('+1 day');
        $hemelvaartsdag    = $easter->modify('+39 days');
        $pinksteren        = $easter->modify('+49 days');
        $tweedePinksterdag = $easter->modify('+50 days');

        return [
            $goedeVrijdag->format('Y-m-d'),
            $easter->format('Y-m-d'),
            $tweedePaasdag->format('Y-m-d'),
            $hemelvaartsdag->format('Y-m-d'),
            $pinksteren->format('Y-m-d'),
            $tweedePinksterdag->format('Y-m-d'),
        ];
    }//end variableHolidaysForYear()

    /**
     * Days from March 21 to Easter Sunday for a Gregorian year (Meeus/Jones/Butcher).
     *
     * @param int $year Gregorian year.
     *
     * @return int Days after March 21 (0..35).
     */
    private function easterOffset(int $year): int
    {
        $a     = ($year % 19);
        $b     = intdiv($year, 100);
        $c     = ($year % 100);
        $d     = intdiv($b, 4);
        $e     = ($b % 4);
        $f     = intdiv(($b + 8), 25);
        $g     = intdiv(($b - $f + 1), 3);
        $h     = ((19 * $a) + $b - $d - $g + 15) % 30;
        $i     = intdiv($c, 4);
        $k     = ($c % 4);
        $l     = (32 + (2 * $e) + (2 * $i) - $h - $k) % 7;
        $m     = intdiv(($a + (11 * $h) + (22 * $l)), 451);
        $month = intdiv(($h + $l - (7 * $m) + 114), 31);
        $day   = ((($h + $l - (7 * $m) + 114) % 31) + 1);

        $easter = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        $anchor = new DateTimeImmutable(sprintf('%04d-03-21', $year));
        return (int) $anchor->diff($easter)->days;
    }//end easterOffset()
}//end class
