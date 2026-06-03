<?php

/**
 * Unit tests for BusinessHoursCalculator.
 *
 * Exercises the calendar-aware deadline math (REQ-002): 24x7 wall-clock,
 * business-hours across weekends/holidays, and elapsed-business-minute
 * measurement used for pause/resume.
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

use DateInterval;
use DateTimeImmutable;
use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BusinessHoursCalculator.
 */
class BusinessHoursCalculatorTest extends TestCase
{
    /**
     * The calculator under test (real holiday service, bundled NL calendar).
     *
     * @var BusinessHoursCalculator
     */
    private BusinessHoursCalculator $calc;

    /**
     * Set up a calculator wired to the real bundled NL holiday calendar.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return $default;
            }
        );

        $logger   = $this->createMock(LoggerInterface::class);
        $holidays = new HolidayCalendarService($appConfig, $logger);
        $this->calc = new BusinessHoursCalculator($holidays, $appConfig);
    }//end setUp()

    /**
     * 24x7 adds wall-clock time and ignores weekends/holidays.
     *
     * @return void
     */
    public function test24x7AddsWallClock(): void
    {
        $start = new DateTimeImmutable('2026-04-26T18:00:00+00:00');
        $due   = $this->calc->addDuration(
            BusinessHoursCalculator::CALENDAR_24X7,
            $start,
            new DateInterval('P1D'),
            'nl-feestdagen-rijksoverheid'
        );

        $this->assertSame('2026-04-27T18:00:00+00:00', $due->format('Y-m-d\TH:i:sP'));
    }//end test24x7AddsWallClock()

    /**
     * A 4-business-hour target opened Friday 17:00 lands Monday 12:00 (REQ-002).
     *
     * Friday 17:00 is the close of business, so all 4 hours fall on Monday
     * 09:00-13:00.
     *
     * @return void
     */
    public function testBusinessHoursAcrossWeekend(): void
    {
        $start = new DateTimeImmutable('2026-05-15T17:00:00+02:00'); // Friday.
        $due   = $this->calc->addDuration(
            BusinessHoursCalculator::CALENDAR_BUSINESS,
            $start,
            new DateInterval('PT4H'),
            'nl-feestdagen-rijksoverheid'
        );

        $this->assertSame('Mon', $due->format('D'));
        $this->assertSame('2026-05-18T13:00', $due->format('Y-m-d\TH:i'));
    }//end testBusinessHoursAcrossWeekend()

    /**
     * A business-hours deadline skips a Dutch national holiday (Koningsdag).
     *
     * Opening Monday 2026-04-27 (Koningsdag) is itself a holiday, so a 2h
     * acknowledgement consumes Tuesday morning instead.
     *
     * @return void
     */
    public function testBusinessHoursSkipsHoliday(): void
    {
        // 2026-04-27 is Koningsdag (Monday). Start the Friday before at 16:00.
        $start = new DateTimeImmutable('2026-04-24T16:00:00+02:00'); // Friday.
        $due   = $this->calc->addDuration(
            BusinessHoursCalculator::CALENDAR_BUSINESS,
            $start,
            new DateInterval('PT2H'),
            'nl-feestdagen-rijksoverheid'
        );

        // 1h Friday 16:00-17:00, then skip Sat/Sun and Koningsdag Monday,
        // remaining 1h on Tuesday 2026-04-28 09:00-10:00.
        $this->assertSame('2026-04-28T10:00', $due->format('Y-m-d\TH:i'));
    }//end testBusinessHoursSkipsHoliday()

    /**
     * Elapsed business minutes across a weekend count only working time.
     *
     * Friday 16:00 to Monday 10:00: Fri 16:00-17:00 (60m) + Mon 09:00-10:00
     * (60m) = 120 business minutes (REQ-003 partial-pause scenario).
     *
     * @return void
     */
    public function testElapsedBusinessMinutesAcrossWeekend(): void
    {
        $from = new DateTimeImmutable('2026-05-15T16:00:00+02:00'); // Friday.
        $to   = new DateTimeImmutable('2026-05-18T10:00:00+02:00'); // Monday.

        $minutes = $this->calc->elapsedBusinessMinutes(
            BusinessHoursCalculator::CALENDAR_BUSINESS,
            $from,
            $to,
            'nl-feestdagen-rijksoverheid'
        );

        $this->assertSame(120, $minutes);
    }//end testElapsedBusinessMinutesAcrossWeekend()

    /**
     * 24x7 elapsed minutes equal wall-clock minutes.
     *
     * @return void
     */
    public function testElapsed24x7IsWallClock(): void
    {
        $from = new DateTimeImmutable('2026-05-15T12:00:00+00:00');
        $to   = new DateTimeImmutable('2026-05-15T14:30:00+00:00');

        $minutes = $this->calc->elapsedBusinessMinutes(
            BusinessHoursCalculator::CALENDAR_24X7,
            $from,
            $to,
            'none'
        );

        $this->assertSame(150, $minutes);
    }//end testElapsed24x7IsWallClock()

    /**
     * A one-business-day target consumes one working window (8 business hours).
     *
     * With a 09:00-17:00 window, P1D = 480 business minutes. Opening Tuesday
     * 09:00 therefore lands at the close of the SAME day (Tuesday 17:00); the
     * next consumable minute would roll to Wednesday.
     *
     * @return void
     */
    public function testOneBusinessDayDuration(): void
    {
        $start = new DateTimeImmutable('2026-05-12T09:00:00+02:00'); // Tuesday.
        $due   = $this->calc->addDuration(
            BusinessHoursCalculator::CALENDAR_BUSINESS,
            $start,
            new DateInterval('P1D'),
            'nl-feestdagen-rijksoverheid'
        );

        $this->assertSame('2026-05-12T17:00', $due->format('Y-m-d\TH:i'));
    }//end testOneBusinessDayDuration()

    /**
     * Extended hours include Saturday morning (08:00-18:00 Mon-Fri + Sat 09:00-13:00).
     *
     * @return void
     */
    public function testExtendedHoursIncludeSaturdayMorning(): void
    {
        // Saturday 2026-05-16 12:00 is inside the Sat 09:00-13:00 window.
        $sat = new DateTimeImmutable('2026-05-16T12:00:00+02:00');
        $this->assertTrue(
            $this->calc->isWithinWorkingWindow(BusinessHoursCalculator::CALENDAR_EXTENDED, $sat, 'none')
        );

        // Saturday 14:00 is outside the morning window.
        $satPm = new DateTimeImmutable('2026-05-16T14:00:00+02:00');
        $this->assertFalse(
            $this->calc->isWithinWorkingWindow(BusinessHoursCalculator::CALENDAR_EXTENDED, $satPm, 'none')
        );
    }//end testExtendedHoursIncludeSaturdayMorning()
}//end class
