<?php

/**
 * Pipelinq ReportCalendar.
 *
 * Tells which days the contact centre is open.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\ContactReport
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\ContactReport;

use DateTimeImmutable;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCP\IAppConfig;

/**
 * The open days of the contact centre: Monday to Friday, except the public
 * holidays of the holiday calendar the SLA engine uses by default
 * (setting sla_default_holiday_calendar).
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-forecasts-the-coming-week
 */
class ReportCalendar {
	/**
	 * The calendar used when none is configured.
	 */
	private const DEFAULT_CALENDAR = 'nl-feestdagen-rijksoverheid';

	/**
	 * Constructor.
	 *
	 * @param HolidayCalendarService $holidays  The holiday calendars.
	 * @param IAppConfig             $appConfig The app configuration.
	 */
	public function __construct(
		private readonly HolidayCalendarService $holidays,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether the contact centre is open on a day.
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return bool False on a weekend day or a public holiday.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-forecasts-the-coming-week
	 */
	public function isOpen(DateTimeImmutable $day): bool {
		if ((int)$day->format('N') >= 6) {
			return false;
		}

		return $this->holidays->isHoliday($this->calendarName(), $day) === false;
	}//end isOpen()

	/**
	 * How many open days lie between two days, both included.
	 *
	 * @param DateTimeImmutable $first The first day.
	 * @param DateTimeImmutable $last  The last day.
	 *
	 * @return int The open days; 0 when the last day lies before the first.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-shows-occupancy-per-employee-and-hour
	 */
	public function openDaysBetween(DateTimeImmutable $first, DateTimeImmutable $last): int {
		$open = 0;
		$day = $first->setTime(0, 0);
		$end = $last->setTime(0, 0);
		while ($day <= $end) {
			if ($this->isOpen(day: $day) === true) {
				$open++;
			}

			$day = $day->modify('+1 day');
		}

		return $open;
	}//end openDaysBetween()

	/**
	 * The configured holiday calendar.
	 *
	 * @return string The calendar slug.
	 */
	private function calendarName(): string {
		return $this->appConfig->getValueString('pipelinq', 'sla_default_holiday_calendar', self::DEFAULT_CALENDAR);
	}//end calendarName()
}//end class
