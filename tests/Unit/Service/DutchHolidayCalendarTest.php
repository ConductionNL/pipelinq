<?php

/**
 * Unit tests for DutchHolidayCalendar.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Service\DutchHolidayCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DutchHolidayCalendar working-day arithmetic.
 */
class DutchHolidayCalendarTest extends TestCase
{
    /**
     * The calendar under test.
     *
     * @var DutchHolidayCalendar
     */
    private DutchHolidayCalendar $calendar;

    /**
     * Set up the calendar.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->calendar = new DutchHolidayCalendar();
    }//end setUp()

    /**
     * Five working days from a Monday lands on the next Monday.
     *
     * Mon 2026-06-01 + 5 working days = Mon 2026-06-08 (no holidays that week).
     *
     * @return void
     */
    public function testFiveWorkingDaysWithinRegularWeeks(): void
    {
        $from   = new DateTimeImmutable('2026-06-01');
        $result = $this->calendar->addWorkingDays($from, 5);

        $this->assertSame('2026-06-08', $result->format('Y-m-d'));
    }//end testFiveWorkingDaysWithinRegularWeeks()

    /**
     * Working days skip the weekend.
     *
     * Thu 2026-06-04 + 2 working days = Mon 2026-06-08 (skips Sat/Sun).
     *
     * @return void
     */
    public function testSkipsWeekend(): void
    {
        $from   = new DateTimeImmutable('2026-06-04');
        $result = $this->calendar->addWorkingDays($from, 2);

        $this->assertSame('2026-06-08', $result->format('Y-m-d'));
    }//end testSkipsWeekend()

    /**
     * Koningsdag (27 April) is a holiday and is skipped.
     *
     * @return void
     */
    public function testKoningsdagIsHoliday(): void
    {
        // 2026-04-27 is a Monday.
        $this->assertTrue($this->calendar->isHoliday(new DateTimeImmutable('2026-04-27')));
        $this->assertFalse($this->calendar->isWorkingDay(new DateTimeImmutable('2026-04-27')));

        // Fri 2026-04-24 + 1 working day skips Sat/Sun AND Koningsdag -> Tue 2026-04-28.
        $result = $this->calendar->addWorkingDays(new DateTimeImmutable('2026-04-24'), 1);
        $this->assertSame('2026-04-28', $result->format('Y-m-d'));
    }//end testKoningsdagIsHoliday()

    /**
     * Bevrijdingsdag (5 May) is only a free day in lustrum years.
     *
     * @return void
     */
    public function testBevrijdingsdagOnlyInLustrumYears(): void
    {
        // 2025 is a lustrum year (divisible by 5).
        $this->assertTrue($this->calendar->isHoliday(new DateTimeImmutable('2025-05-05')));
        // 2026 is not.
        $this->assertFalse($this->calendar->isHoliday(new DateTimeImmutable('2026-05-05')));
    }//end testBevrijdingsdagOnlyInLustrumYears()

    /**
     * Christmas days are holidays.
     *
     * @return void
     */
    public function testChristmasIsHoliday(): void
    {
        $this->assertTrue($this->calendar->isHoliday(new DateTimeImmutable('2026-12-25')));
        $this->assertTrue($this->calendar->isHoliday(new DateTimeImmutable('2026-12-26')));
    }//end testChristmasIsHoliday()

    /**
     * Easter-derived holidays are computed correctly for 2026.
     *
     * Easter Sunday 2026 = 2026-04-05; Goede Vrijdag = 2026-04-03;
     * Tweede Paasdag = 2026-04-06; Hemelvaart = 2026-05-14.
     *
     * @return void
     */
    public function testEasterDerivedHolidays(): void
    {
        $this->assertTrue($this->calendar->isHoliday(new DateTimeImmutable('2026-04-03')));
        $this->assertTrue($this->calendar->isHoliday(new DateTimeImmutable('2026-04-06')));
        $this->assertTrue($this->calendar->isHoliday(new DateTimeImmutable('2026-05-14')));
    }//end testEasterDerivedHolidays()

    /**
     * A tenant-custom holiday is honoured.
     *
     * @return void
     */
    public function testCustomHoliday(): void
    {
        $calendar = new DutchHolidayCalendar(['2026-06-15']);

        $this->assertTrue($calendar->isHoliday(new DateTimeImmutable('2026-06-15')));
    }//end testCustomHoliday()
}//end class
