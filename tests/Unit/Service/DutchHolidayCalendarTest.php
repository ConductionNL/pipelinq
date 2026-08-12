<?php

/**
 * Unit tests for DutchHolidayCalendar — 5-working-day arithmetic across
 * weekends and Dutch statutory holidays.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-fallback-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Service\DutchHolidayCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DutchHolidayCalendar.
 */
class DutchHolidayCalendarTest extends TestCase {
	/**
	 * From a Monday, +5 working days lands on the following Monday (skip Sat+Sun).
	 *
	 * @return void
	 */
	public function testFiveWorkingDaysAcrossWeekend(): void {
		$calendar = new DutchHolidayCalendar();
		// 2026-03-02 is a Monday (no holidays nearby).
		$from = new DateTimeImmutable('2026-03-02 00:00:00', new DateTimeZone('Europe/Amsterdam'));
		$result = $calendar->addWorkingDays($from, 5);
		// Mon +5: Tue, Wed, Thu, Fri, Mon = 2026-03-09.
		$this->assertSame('2026-03-09', $result->format('Y-m-d'));
	}//end testFiveWorkingDaysAcrossWeekend()

	/**
	 * Saturday/Sunday are not working days.
	 *
	 * @return void
	 */
	public function testWeekendsAreNotWorkingDays(): void {
		$calendar = new DutchHolidayCalendar();
		$sat = new DateTimeImmutable('2026-03-07'); // Saturday
		$sun = new DateTimeImmutable('2026-03-08'); // Sunday
		$this->assertFalse($calendar->isWorkingDay($sat));
		$this->assertFalse($calendar->isWorkingDay($sun));
	}//end testWeekendsAreNotWorkingDays()

	/**
	 * Koningsdag (Apr 27) is a holiday.
	 *
	 * @return void
	 */
	public function testKoningsdagIsHoliday(): void {
		$calendar = new DutchHolidayCalendar();
		$koningsdag = new DateTimeImmutable('2026-04-27'); // Monday
		$this->assertTrue($calendar->isHoliday($koningsdag));
		$this->assertFalse($calendar->isWorkingDay($koningsdag));
	}//end testKoningsdagIsHoliday()

	/**
	 * Adding 5 working days that crosses Koningsdag skips it.
	 *
	 * @return void
	 */
	public function testFiveWorkingDaysAcrossKoningsdag(): void {
		$calendar = new DutchHolidayCalendar();
		// 2026-04-23 is a Thursday. +5 working days normally = next Thursday
		// (2026-04-30) but 2026-04-27 (Mon) is Koningsdag → 2026-05-01 (Fri).
		$from = new DateTimeImmutable('2026-04-23');
		$result = $calendar->addWorkingDays($from, 5);
		$this->assertSame('2026-05-01', $result->format('Y-m-d'));
	}//end testFiveWorkingDaysAcrossKoningsdag()

	/**
	 * Bevrijdingsdag (May 5) is a public holiday only in lustrum years.
	 *
	 * @return void
	 */
	public function testBevrijdingsdagOnlyInLustrumYears(): void {
		$calendar = new DutchHolidayCalendar();
		// 2025 is a lustrum year.
		$lustrum = new DateTimeImmutable('2025-05-05');
		$this->assertTrue($calendar->isHoliday($lustrum));
		// 2026 is not a lustrum year.
		$nonLustrum = new DateTimeImmutable('2026-05-05');
		$this->assertFalse($calendar->isHoliday($nonLustrum));
	}//end testBevrijdingsdagOnlyInLustrumYears()

	/**
	 * Christmas (25-26 Dec) are holidays.
	 *
	 * @return void
	 */
	public function testKerstAreHolidays(): void {
		$calendar = new DutchHolidayCalendar();
		$this->assertTrue($calendar->isHoliday(new DateTimeImmutable('2026-12-25')));
		$this->assertTrue($calendar->isHoliday(new DateTimeImmutable('2026-12-26')));
	}//end testKerstAreHolidays()

	/**
	 * Variable Easter-derived holidays compute correctly for a known year.
	 *
	 * @return void
	 */
	public function testVariableHolidays2026(): void {
		$calendar = new DutchHolidayCalendar();
		// 2026 Easter is April 5; Pinksteren May 24.
		$this->assertTrue($calendar->isHoliday(new DateTimeImmutable('2026-04-05')), 'Easter Sunday');
		$this->assertTrue($calendar->isHoliday(new DateTimeImmutable('2026-04-06')), 'Paasmaandag');
		$this->assertTrue($calendar->isHoliday(new DateTimeImmutable('2026-05-24')), 'Pinksteren');
		$this->assertTrue($calendar->isHoliday(new DateTimeImmutable('2026-05-25')), 'Pinkstermaandag');
	}//end testVariableHolidays2026()

	/**
	 * Tenant-supplied extra holidays are honoured.
	 *
	 * @return void
	 */
	public function testExtraHolidays(): void {
		$calendar = new DutchHolidayCalendar(['2026-03-04']);
		$this->assertTrue($calendar->isHoliday(new DateTimeImmutable('2026-03-04')));
	}//end testExtraHolidays()

	/**
	 * Negative days throw.
	 *
	 * @return void
	 */
	public function testNegativeDaysReject(): void {
		$calendar = new DutchHolidayCalendar();
		$this->expectException(\InvalidArgumentException::class);
		$calendar->addWorkingDays(new DateTimeImmutable('2026-03-02'), -1);
	}//end testNegativeDaysReject()
}//end class
