<?php

/**
 * Unit tests for ReportCalendar.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\ContactReport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\ContactReport;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Service\ContactReport\ReportCalendar;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The contact centre's open days: weekdays that are not a holiday of the configured calendar.
 */
#[CoversClass(ReportCalendar::class)]
final class ReportCalendarTest extends TestCase {

	/**
	 * The holiday calendars.
	 *
	 * @var HolidayCalendarService&MockObject
	 */
	private HolidayCalendarService&MockObject $holidays;

	/**
	 * The calendars asked, one per isHoliday call.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Set up a calendar whose only holiday is Monday 6 April 2026.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->asked    = [];
		$this->holidays = $this->createMock(HolidayCalendarService::class);
		$this->holidays->method('isHoliday')->willReturnCallback(
			function (string $calendar, DateTimeInterface $date): bool {
				$this->asked[] = $calendar;
				return $date->format('Y-m-d') === '2026-04-06';
			}
		);
	}//end setUp()

	/**
	 * Weekends are closed without asking the holiday calendar.
	 *
	 * @return void
	 */
	public function testWeekendsAreClosed(): void {
		$calendar = $this->calendar(configured: null);

		$this->assertFalse($calendar->isOpen(day: new DateTimeImmutable('2026-04-04')));
		$this->assertFalse($calendar->isOpen(day: new DateTimeImmutable('2026-04-05')));
		$this->assertSame([], $this->asked);
	}//end testWeekendsAreClosed()

	/**
	 * A weekday is open unless it is a holiday; the default calendar is the national one.
	 *
	 * @return void
	 */
	public function testWeekdaysAreOpenExceptHolidaysOfTheDefaultCalendar(): void {
		$calendar = $this->calendar(configured: null);

		$this->assertFalse($calendar->isOpen(day: new DateTimeImmutable('2026-04-06')));
		$this->assertTrue($calendar->isOpen(day: new DateTimeImmutable('2026-04-07')));
		$this->assertTrue($calendar->isOpen(day: new DateTimeImmutable('2026-04-10')));
		$this->assertSame(['nl-feestdagen-rijksoverheid', 'nl-feestdagen-rijksoverheid', 'nl-feestdagen-rijksoverheid'], $this->asked);
	}//end testWeekdaysAreOpenExceptHolidaysOfTheDefaultCalendar()

	/**
	 * The SLA engine's configured default calendar is the one consulted.
	 *
	 * @return void
	 */
	public function testTheConfiguredCalendarIsUsed(): void {
		$this->calendar(configured: 'gemeente-utrecht')->isOpen(day: new DateTimeImmutable('2026-04-08'));

		$this->assertSame(['gemeente-utrecht'], $this->asked);
	}//end testTheConfiguredCalendarIsUsed()

	/**
	 * Open days are counted with both days included, ignoring the time of day.
	 *
	 * @return void
	 */
	public function testOpenDaysBetweenCountsBothDays(): void {
		$calendar = $this->calendar(configured: null);

		// Mon 6 Apr (holiday) to Sun 12 Apr: Tue to Fri are open.
		$this->assertSame(4, $calendar->openDaysBetween(first: new DateTimeImmutable('2026-04-06 17:30'), last: new DateTimeImmutable('2026-04-12 08:00')));
		// Fri 10 Apr 23:00 to Fri 10 Apr 01:00 is the same day.
		$this->assertSame(1, $calendar->openDaysBetween(first: new DateTimeImmutable('2026-04-10 23:00'), last: new DateTimeImmutable('2026-04-10 01:00')));
		// Two full weeks with one holiday.
		$this->assertSame(9, $calendar->openDaysBetween(first: new DateTimeImmutable('2026-04-06'), last: new DateTimeImmutable('2026-04-17')));
	}//end testOpenDaysBetweenCountsBothDays()

	/**
	 * A last day before the first gives no open days.
	 *
	 * @return void
	 */
	public function testABackwardsRangeHasNoOpenDays(): void {
		$calendar = $this->calendar(configured: null);

		$this->assertSame(0, $calendar->openDaysBetween(first: new DateTimeImmutable('2026-04-08'), last: new DateTimeImmutable('2026-04-07')));
		$this->assertSame([], $this->asked);
	}//end testABackwardsRangeHasNoOpenDays()

	/**
	 * The subject, with a configured calendar or the default.
	 *
	 * @param string|null $configured The configured calendar, or null for none.
	 *
	 * @return ReportCalendar The subject.
	 */
	private function calendar(?string $configured): ReportCalendar {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ([$app, $key]) {
				['pipelinq', 'sla_default_holiday_calendar'] => ($configured ?? $default),
				default => $default,
			}
		);

		return new ReportCalendar(holidays: $this->holidays, appConfig: $appConfig);
	}//end calendar()
}//end class
