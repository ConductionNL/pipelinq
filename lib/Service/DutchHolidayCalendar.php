<?php

/**
 * Pipelinq DutchHolidayCalendar.
 *
 * Working-day arithmetic that skips weekends and official Dutch public
 * holidays, used to compute the 5-working-day unread-message email-fallback
 * deadline mandated by BBK 1.7 Art. 3.5. Holidays with a fixed Gregorian date
 * (Nieuwjaarsdag, Koningsdag, Bevrijdingsdag in lustrum years, Kerstmis) are
 * computed analytically; Easter-derived holidays (Goede Vrijdag, Pasen,
 * Hemelvaart, Pinksteren) are derived from the Gauss Easter algorithm.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-FALLBACK-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Computes working days excluding weekends and Dutch public holidays.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-FALLBACK-004
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class DutchHolidayCalendar
{
    /**
     * The default tenant timezone.
     *
     * @var string
     */
    public const DEFAULT_TZ = 'Europe/Amsterdam';

    /**
     * Optional tenant-custom holidays as 'm-d' or 'Y-m-d' strings.
     *
     * @var array<int, string>
     */
    private array $customHolidays;

    /**
     * Constructor.
     *
     * @param array<int, string> $customHolidays Tenant-custom holidays ('m-d' or 'Y-m-d').
     */
    public function __construct(array $customHolidays=[])
    {
        $this->customHolidays = $customHolidays;
    }//end __construct()

    /**
     * Return the date that is $days working days after $from.
     *
     * @param DateTimeInterface $from           The start date.
     * @param int               $days           The number of working days to add.
     * @param string            $tenantTimeZone The tenant timezone.
     *
     * @return DateTimeImmutable The resulting date.
     */
    public function addWorkingDays(
        DateTimeInterface $from,
        int $days,
        string $tenantTimeZone=self::DEFAULT_TZ
    ): DateTimeImmutable {
        $tz     = new DateTimeZone($tenantTimeZone);
        $cursor = DateTimeImmutable::createFromInterface($from)->setTimezone($tz);
        $added  = 0;

        while ($added < $days) {
            $cursor = $cursor->modify('+1 day');
            if ($this->isWorkingDay(date: $cursor) === true) {
                $added++;
            }
        }

        return $cursor;
    }//end addWorkingDays()

    /**
     * Determine whether a date is a working day (not weekend, not holiday).
     *
     * @param DateTimeInterface $date The date to test.
     *
     * @return bool True when the date is a working day.
     */
    public function isWorkingDay(DateTimeInterface $date): bool
    {
        $dow = (int) $date->format('N');
        if ($dow >= 6) {
            return false;
        }

        return $this->isHoliday(date: $date) === false;
    }//end isWorkingDay()

    /**
     * Determine whether a date is an official Dutch public holiday.
     *
     * @param DateTimeInterface $date The date to test.
     *
     * @return bool True when the date is a holiday.
     */
    public function isHoliday(DateTimeInterface $date): bool
    {
        $year = (int) $date->format('Y');
        $key  = $date->format('Y-m-d');
        $md   = $date->format('m-d');

        if (in_array($md, $this->customHolidays, true) === true
            || in_array($key, $this->customHolidays, true) === true
        ) {
            return true;
        }

        return in_array($key, $this->holidaysForYear(year: $year), true);
    }//end isHoliday()

    /**
     * Compute the list of holiday dates (Y-m-d) for a given year.
     *
     * @param int $year The Gregorian year.
     *
     * @return array<int, string> The holiday dates as 'Y-m-d' strings.
     */
    public function holidaysForYear(int $year): array
    {
        $holidays = [
            sprintf('%04d-01-01', $year),
            // Kerstmis + Tweede Kerstdag.
            sprintf('%04d-12-25', $year),
            sprintf('%04d-12-26', $year),
        ];

        // Koningsdag: 27 April, shifted to 26 April when the 27th is a Sunday.
        $koningsdag = new DateTimeImmutable(sprintf('%04d-04-27', $year));
        if ((int) $koningsdag->format('N') === 7) {
            $koningsdag = $koningsdag->modify('-1 day');
        }

        $holidays[] = $koningsdag->format('Y-m-d');

        // Bevrijdingsdag: official free day every lustrum year (multiple of 5).
        if (($year % 5) === 0) {
            $holidays[] = sprintf('%04d-05-05', $year);
        }

        // Easter-derived holidays.
        $easter = $this->easterDate(year: $year);

        $goedeVrijdag   = $easter->modify('-2 days');
        $tweedePaasdag  = $easter->modify('+1 day');
        $hemelvaart     = $easter->modify('+39 days');
        $tweedePinkster = $easter->modify('+50 days');

        $holidays[] = $goedeVrijdag->format('Y-m-d');
        $holidays[] = $easter->format('Y-m-d');
        $holidays[] = $tweedePaasdag->format('Y-m-d');
        $holidays[] = $hemelvaart->format('Y-m-d');
        $holidays[] = $tweedePinkster->format('Y-m-d');

        return $holidays;
    }//end holidaysForYear()

    /**
     * Compute Easter Sunday for a year (Anonymous Gregorian / Meeus algorithm).
     *
     * @param int $year The Gregorian year.
     *
     * @return DateTimeImmutable Easter Sunday.
     */
    private function easterDate(int $year): DateTimeImmutable
    {
        $a = ($year % 19);
        $b = intdiv($year, 100);
        $c = ($year % 100);
        $d = intdiv($b, 4);
        $e = ($b % 4);
        $f = intdiv(($b + 8), 25);
        $g = intdiv((($b - $f) + 1), 3);
        $h = (((19 * $a) + $b - $d - $g + 15) % 30);
        $i = intdiv($c, 4);
        $k = ($c % 4);
        $l = ((32 + (2 * $e) + (2 * $i) - $h - $k) % 7);
        $m = intdiv(($a + (11 * $h) + (22 * $l)), 451);

        $month = intdiv(($h + $l - (7 * $m) + 114), 31);
        $day   = ((($h + $l - (7 * $m) + 114) % 31) + 1);

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }//end easterDate()
}//end class
