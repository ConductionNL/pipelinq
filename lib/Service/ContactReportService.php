<?php

/**
 * Pipelinq ContactReportService.
 *
 * The contact centre report's top subjects, occupancy, forecast and its
 * Excel and PDF exports.
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
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Kernel\Contracts\ContactReportServiceInterface;
use OCA\Pipelinq\Service\ContactReport\ContactMoments;
use OCA\Pipelinq\Service\ContactReport\ContactReportFilter;
use OCA\Pipelinq\Service\ContactReport\ReportCalendar;
use OCA\Pipelinq\Service\Document\PdfDocument;
use OCA\Pipelinq\Service\Document\XlsxWorkbook;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Builds the contact report's figures from the contact moments, every one
 * narrowed by the same team, channel and employee filter.
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md
 */
class ContactReportService implements ContactReportServiceInterface {
	/**
	 * How many subjects the top list holds.
	 */
	private const TOP_SUBJECTS = 10;

	/**
	 * The hours of the working day the occupancy covers (8:00 up to 18:00).
	 */
	private const WORKING_HOURS = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17];

	/**
	 * The days of history the forecast averages over.
	 */
	private const HISTORY_DAYS = 28;

	/**
	 * The fewest days of history a forecast needs.
	 */
	private const MIN_HISTORY_DAYS = 14;

	/**
	 * How many days the forecast covers.
	 */
	private const FORECAST_DAYS = 7;

	/**
	 * Constructor.
	 *
	 * @param ContactMoments $moments  The contact moments.
	 * @param ReportCalendar $calendar The open days.
	 * @param ITimeFactory   $clock    The clock.
	 * @param XlsxWorkbook   $workbook The workbook writer.
	 * @param PdfDocument    $pdf      The PDF writer.
	 */
	public function __construct(
		private readonly ContactMoments $moments,
		private readonly ReportCalendar $calendar,
		private readonly ITimeFactory $clock,
		private readonly XlsxWorkbook $workbook,
		private readonly PdfDocument $pdf,
	) {
	}//end __construct()

	/**
	 * The subjects with the most contact moments in a period, most first.
	 *
	 * @param string              $from   The first day.
	 * @param string              $to     The last day.
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return array{subjects: array<int, array{subject: string, count: int}>} At most ten subjects.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-lists-the-top-10-subjects
	 */
	public function topSubjects(string $from, string $to, ContactReportFilter $filter): array {
		return ['subjects' => $this->rankedSubjects(moments: $this->moments->between(from: $from, to: $to, filter: $filter))];
	}//end topSubjects()

	/**
	 * Per employee and working hour, the share of that hour spent on contact moments.
	 *
	 * A contact moment's duration is spread over the hours it runs through,
	 * and each hour's busy time is divided by that hour's length on every
	 * open day of the period. An hour without contact moments reads 0.
	 *
	 * @param string              $from   The first day.
	 * @param string              $to     The last day.
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return array{hours: array<int, int>, employees: array<int, array{employee: string, values: array<int, float>}>} The occupancy.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-shows-occupancy-per-employee-and-hour
	 */
	public function occupancy(string $from, string $to, ContactReportFilter $filter): array {
		$busy = [];
		foreach ($this->moments->between(from: $from, to: $to, filter: $filter) as $moment) {
			$busy[$moment['employee']] = self::addBusyTime(hours: ($busy[$moment['employee']] ?? []), moment: $moment);
		}

		ksort($busy);
		$capacity = 3600 * max(1, $this->calendar->openDaysBetween(first: new DateTimeImmutable($from), last: new DateTimeImmutable($to)));
		$rows = [];
		foreach ($busy as $employee => $hours) {
			$rows[] = ['employee' => (string)$employee, 'values' => self::shares(hours: $hours, capacity: $capacity)];
		}

		return ['hours' => self::WORKING_HOURS, 'employees' => $rows];
	}//end occupancy()

	/**
	 * The expected contact moments for each of the next seven days.
	 *
	 * The expectation is the average per open day over the last four weeks
	 * (or the history there is, when that is at least two weeks). A closed
	 * day (weekend or public holiday) reads 0.
	 *
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return array<string, mixed> enoughHistory, and with it dailyAverage, days, total and normalWeekTotal.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-forecasts-the-coming-week
	 */
	public function forecast(ContactReportFilter $filter): array {
		$today = $this->clock->now()->setTime(0, 0);
		$yesterday = $today->modify('-1 day');
		$history = $this->moments->between(from: '', to: $yesterday->format('Y-m-d'), filter: $filter);
		$first = self::firstDay(moments: $history);
		if ($first === null || (int)$first->diff($today)->days < self::MIN_HISTORY_DAYS) {
			return [
				'enoughHistory' => false,
				'message' => 'Too little history for a forecast: it needs at least two weeks of contact moments',
				'days' => [],
			];
		}

		$start = max($first, $today->modify('-'.self::HISTORY_DAYS.' days'));
		return $this->forecastFrom(average: $this->dailyAverage(moments: $history, first: $start, last: $yesterday), today: $today);
	}//end forecast()

	/**
	 * The report as an Excel workbook: a summary, one row per contact, and the team.
	 *
	 * @param string              $from   The first day.
	 * @param string              $to     The last day.
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return string The .xlsx bytes.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
	 */
	public function workbook(string $from, string $to, ContactReportFilter $filter): string {
		$moments = $this->moments->between(from: $from, to: $to, filter: $filter);
		$contacts = [['Date', 'Channel', 'Employee', 'Subject', 'Outcome', 'Duration (seconds)']];
		foreach ($moments as $moment) {
			$contacts[] = [
				$moment['at']->format('Y-m-d H:i'),
				$moment['channel'],
				$moment['employee'],
				$moment['subject'],
				$moment['outcome'],
				($moment['seconds'] ?? ''),
			];
		}

		return $this->workbook->build(
			sheets: [
				'Summary' => array_merge([['Item', 'Value']], $this->summaryRows(from: $from, to: $to, filter: $filter, moments: $moments)),
				'Contacts' => $contacts,
				'Team' => array_merge([['Employee', 'Contacts', 'Resolved first time (%)', 'Average handling time']], self::teamRows(moments: $moments)),
			]
		);
	}//end workbook()

	/**
	 * The report as a formatted PDF document for management.
	 *
	 * @param string              $from   The first day.
	 * @param string              $to     The last day.
	 * @param ContactReportFilter $filter The filter.
	 *
	 * @return string The PDF bytes.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
	 */
	public function document(string $from, string $to, ContactReportFilter $filter): string {
		$moments = $this->moments->between(from: $from, to: $to, filter: $filter);
		$subjects = [];
		foreach ($this->rankedSubjects(moments: $moments) as $index => $subject) {
			$subjects[] = [($index + 1).'. '.$subject['subject'], $subject['count']];
		}

		return $this->pdf->build(
			title: 'Contact report',
			sections: [
				'Summary' => $this->summaryRows(from: $from, to: $to, filter: $filter, moments: $moments),
				'Top 10 subjects' => $subjects,
				'Team' => array_merge([['Employee', 'Contacts', 'Resolved first time (%)', 'Average handling time']], self::teamRows(moments: $moments)),
			]
		);
	}//end document()

	/**
	 * The subjects ranked by their number of contact moments, at most ten.
	 *
	 * Ties are ordered by subject, so the list is the same on every read.
	 *
	 * @param array<int, array<string, mixed>> $moments The moments.
	 *
	 * @return array<int, array{subject: string, count: int}> The subjects.
	 */
	private function rankedSubjects(array $moments): array {
		$counts = [];
		foreach ($moments as $moment) {
			if ($moment['subject'] !== '') {
				$counts[$moment['subject']] = ($counts[$moment['subject']] ?? 0) + 1;
			}
		}

		$subjects = [];
		foreach ($counts as $subject => $count) {
			$subjects[] = ['subject' => (string)$subject, 'count' => $count];
		}

		usort($subjects, static fn (array $a, array $b): int => [$b['count'], $a['subject']] <=> [$a['count'], $b['subject']]);
		return array_slice($subjects, 0, self::TOP_SUBJECTS);
	}//end rankedSubjects()

	/**
	 * Add a moment's duration to the working hours it runs through.
	 *
	 * @param array<int, int>      $hours  Hour => busy seconds so far.
	 * @param array<string, mixed> $moment The moment.
	 *
	 * @return array<int, int> The updated busy seconds.
	 */
	private static function addBusyTime(array $hours, array $moment): array {
		$remaining = (int)($moment['seconds'] ?? 0);
		$hour = (int)$moment['at']->format('G');
		$offset = ((int)$moment['at']->format('i') * 60) + (int)$moment['at']->format('s');
		while ($remaining > 0 && $hour < 24) {
			$spent = min($remaining, 3600 - $offset);
			$hours[$hour] = ($hours[$hour] ?? 0) + $spent;
			$remaining -= $spent;
			$offset = 0;
			$hour++;
		}

		return $hours;
	}//end addBusyTime()

	/**
	 * The busy share of every working hour, 0 where there was none.
	 *
	 * @param array<int, int> $hours    Hour => busy seconds.
	 * @param int             $capacity The seconds one hour holds over the period.
	 *
	 * @return array<int, float> One share per working hour, between 0 and 1.
	 */
	private static function shares(array $hours, int $capacity): array {
		$values = [];
		foreach (self::WORKING_HOURS as $hour) {
			$values[] = round(min(1.0, ($hours[$hour] ?? 0) / $capacity), 2);
		}

		return $values;
	}//end shares()

	/**
	 * The earliest day among the moments.
	 *
	 * @param array<int, array<string, mixed>> $moments The moments.
	 *
	 * @return DateTimeImmutable|null The day, or null without moments.
	 */
	private static function firstDay(array $moments): ?DateTimeImmutable {
		if ($moments === []) {
			return null;
		}

		return new DateTimeImmutable(min(array_column($moments, 'day')));
	}//end firstDay()

	/**
	 * The average contact moments per open day between two days.
	 *
	 * @param array<int, array<string, mixed>> $moments The moments.
	 * @param DateTimeImmutable                $first   The first day.
	 * @param DateTimeImmutable                $last    The last day.
	 *
	 * @return float The average.
	 */
	private function dailyAverage(array $moments, DateTimeImmutable $first, DateTimeImmutable $last): float {
		$openDays = $this->calendar->openDaysBetween(first: $first, last: $last);
		if ($openDays === 0) {
			return 0.0;
		}

		$counted = 0;
		foreach ($moments as $moment) {
			$day = new DateTimeImmutable($moment['day']);
			if ($day >= $first && $this->calendar->isOpen(day: $day) === true) {
				$counted++;
			}
		}

		return $counted / $openDays;
	}//end dailyAverage()

	/**
	 * The forecast of the seven days after today at a daily average.
	 *
	 * @param float             $average The average per open day.
	 * @param DateTimeImmutable $today   Today.
	 *
	 * @return array<string, mixed> The forecast.
	 */
	private function forecastFrom(float $average, DateTimeImmutable $today): array {
		$days = [];
		$total = 0;
		$weekdays = 0;
		for ($i = 1; $i <= self::FORECAST_DAYS; $i++) {
			$day = $today->modify('+'.$i.' days');
			$open = $this->calendar->isOpen(day: $day);
			$expected = 0;
			if ($open === true) {
				$expected = (int)round($average);
			}

			if ((int)$day->format('N') <= 5) {
				$weekdays++;
			}

			$days[] = ['date' => $day->format('Y-m-d'), 'weekday' => $day->format('l'), 'open' => $open, 'expected' => $expected];
			$total += $expected;
		}

		return [
			'enoughHistory' => true,
			'dailyAverage' => round($average, 1),
			'days' => $days,
			'total' => $total,
			'normalWeekTotal' => (int)round($average) * $weekdays,
		];
	}//end forecastFrom()

	/**
	 * The summary rows: the period, the filters and the headline figures.
	 *
	 * @param string                           $from    The first day.
	 * @param string                           $to      The last day.
	 * @param ContactReportFilter              $filter  The filter.
	 * @param array<int, array<string, mixed>> $moments The moments.
	 *
	 * @return array<int, array<int, string|int|float>> Item, value.
	 */
	private function summaryRows(string $from, string $to, ContactReportFilter $filter, array $moments): array {
		$rows = [['Period', substr($from, 0, 10).' - '.substr($to, 0, 10)]];
		foreach ($filter->labels() as $label => $value) {
			$rows[] = [$label, $value];
		}

		$rows[] = ['Contacts', count($moments)];
		$rows[] = ['Resolved first time (%)', self::firstTimeRate(moments: $moments)];
		$rows[] = ['Average handling time', self::averageHandlingTime(moments: $moments)];
		$channels = array_count_values(array_column($moments, 'channel'));
		ksort($channels);
		foreach ($channels as $channel => $count) {
			$rows[] = ['Contacts via '.$channel, $count];
		}

		return $rows;
	}//end summaryRows()

	/**
	 * One row per employee: contacts, first-time resolution and handling time.
	 *
	 * @param array<int, array<string, mixed>> $moments The moments.
	 *
	 * @return array<int, array<int, string|int|float>> The rows, most contacts first.
	 */
	private static function teamRows(array $moments): array {
		$byEmployee = [];
		foreach ($moments as $moment) {
			$byEmployee[$moment['employee']][] = $moment;
		}

		$rows = [];
		foreach ($byEmployee as $employee => $own) {
			$rows[] = [(string)$employee, count($own), self::firstTimeRate(moments: $own), self::averageHandlingTime(moments: $own)];
		}

		usort($rows, static fn (array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);
		return $rows;
	}//end teamRows()

	/**
	 * The share of moments resolved at first contact, in percent.
	 *
	 * @param array<int, array<string, mixed>> $moments The moments.
	 *
	 * @return float The percentage, one decimal; 0 without moments.
	 */
	private static function firstTimeRate(array $moments): float {
		if ($moments === []) {
			return 0.0;
		}

		$resolved = count(array_filter($moments, static fn (array $moment): bool => $moment['outcome'] === 'resolved'));
		return round(($resolved / count($moments)) * 100, 1);
	}//end firstTimeRate()

	/**
	 * The average duration of the moments that have one, as m:ss.
	 *
	 * @param array<int, array<string, mixed>> $moments The moments.
	 *
	 * @return string The average, or 0:00 when none has a duration.
	 */
	private static function averageHandlingTime(array $moments): string {
		$seconds = array_filter(array_column($moments, 'seconds'), static fn ($value): bool => $value !== null);
		if ($seconds === []) {
			return '0:00';
		}

		$average = intdiv((int)array_sum($seconds), count($seconds));
		return intdiv($average, 60).':'.str_pad((string)($average % 60), 2, '0', STR_PAD_LEFT);
	}//end averageHandlingTime()
}//end class
