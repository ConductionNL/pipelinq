<?php

/**
 * Unit tests for ContactReportService.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Service\ContactReport\ContactMoments;
use OCA\Pipelinq\Service\ContactReport\ContactReportFilter;
use OCA\Pipelinq\Service\ContactReport\ReportCalendar;
use OCA\Pipelinq\Service\ContactReportService;
use OCA\Pipelinq\Service\Document\PdfDocument;
use OCA\Pipelinq\Service\Document\XlsxWorkbook;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The contact report's top subjects, occupancy, forecast and exports.
 */
#[CoversClass(ContactReportService::class)]
#[UsesClass(ContactMoments::class)]
#[UsesClass(ContactReportFilter::class)]
#[UsesClass(ReportCalendar::class)]
#[UsesClass(XlsxWorkbook::class)]
#[UsesClass(PdfDocument::class)]
final class ContactReportServiceTest extends TestCase {
	/**
	 * The stored contact moments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Public holidays, as Y-m-d.
	 *
	 * @var array<int, string>
	 */
	private array $holidays = [];

	/**
	 * The service under test.
	 *
	 * @var ContactReportService
	 */
	private ContactReportService $service;

	/**
	 * Build the service over in-memory contact moments, groups, holidays and a fixed clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$tickets = $this->createMock(TicketServiceInterface::class);
		$tickets->method('findByType')->willReturnCallback(fn (): array => $this->rows);

		$alice = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$team = $this->createConfiguredMock(IGroup::class, ['getUsers' => [$alice]]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $id): ?IGroup => ($id === 'burgerzaken') ? $team : null);

		$holidays = $this->createMock(HolidayCalendarService::class);
		$holidays->method('isHoliday')->willReturnCallback(
			fn (string $calendar, \DateTimeInterface $day): bool => in_array($day->format('Y-m-d'), $this->holidays, true)
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);

		// Sunday 15 November 2026; the forecast covers Monday 16 to Sunday 22.
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-15 10:00:00'));

		$this->service = new ContactReportService(
			moments: new ContactMoments(tickets: $tickets, groups: $groups),
			calendar: new ReportCalendar(holidays: $holidays, appConfig: $appConfig),
			clock: $clock,
			workbook: new XlsxWorkbook(),
			pdf: new PdfDocument(),
		);
	}//end setUp()

	/**
	 * A stored contact moment.
	 *
	 * @param string      $at       When it happened.
	 * @param string      $subject  Its subject.
	 * @param string      $employee Who handled it.
	 * @param string      $channel  Its channel.
	 * @param string|null $duration Its ISO 8601 duration.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function row(string $at, string $subject = 'Paspoort', string $employee = 'alice', string $channel = 'telefoon', ?string $duration = null): array {
		return ['occurredAt' => $at, 'title' => $subject, 'assignee' => $employee, 'channel' => $channel, 'outcome' => 'resolved', 'duration' => $duration];
	}//end row()

	/**
	 * Twelve subjects in a week: the ten with the most contact moments, most first.
	 *
	 * @return void
	 */
	public function testTopSubjectsHoldsTheTenBusiestInOrder(): void {
		for ($subject = 1; $subject <= 12; $subject++) {
			for ($i = 0; $i < $subject; $i++) {
				$this->rows[] = self::row(at: '2026-11-10T10:00:00+01:00', subject: sprintf('Subject %02d', $subject));
			}
		}

		// Outside the week: does not count.
		$this->rows[] = self::row(at: '2026-11-20T10:00:00+01:00', subject: 'Subject 01');

		$result = $this->service->topSubjects(from: '2026-11-09', to: '2026-11-15', filter: new ContactReportFilter());

		$this->assertCount(10, $result['subjects']);
		$this->assertSame(['subject' => 'Subject 12', 'count' => 12], $result['subjects'][0]);
		$this->assertSame(['subject' => 'Subject 03', 'count' => 3], $result['subjects'][9]);
		$this->assertSame(range(12, 3), array_column($result['subjects'], 'count'));
	}//end testTopSubjectsHoldsTheTenBusiestInOrder()

	/**
	 * Filtered on a team, only that team's contact moments count; channel and employee narrow further.
	 *
	 * @return void
	 */
	public function testTopSubjectsAppliesTheTeamChannelAndEmployeeFilters(): void {
		$this->rows = [
			self::row(at: '2026-11-10T10:00:00+01:00', subject: 'Paspoort', employee: 'alice'),
			self::row(at: '2026-11-10T11:00:00+01:00', subject: 'Paspoort', employee: 'alice', channel: 'email'),
			self::row(at: '2026-11-10T12:00:00+01:00', subject: 'Afval', employee: 'bob'),
			self::row(at: '2026-11-10T12:00:00+01:00', subject: 'Afval', employee: 'bob'),
		];

		$team = $this->service->topSubjects(from: '2026-11-09', to: '2026-11-15', filter: new ContactReportFilter(team: 'burgerzaken'));
		$this->assertSame([['subject' => 'Paspoort', 'count' => 2]], $team['subjects']);

		$channel = $this->service->topSubjects(from: '2026-11-09', to: '2026-11-15', filter: new ContactReportFilter(team: 'burgerzaken', channel: 'email'));
		$this->assertSame([['subject' => 'Paspoort', 'count' => 1]], $channel['subjects']);

		$employee = $this->service->topSubjects(from: '2026-11-09', to: '2026-11-15', filter: new ContactReportFilter(employee: 'bob'));
		$this->assertSame([['subject' => 'Afval', 'count' => 2]], $employee['subjects']);

		$unknown = $this->service->topSubjects(from: '2026-11-09', to: '2026-11-15', filter: new ContactReportFilter(team: 'nobody'));
		$this->assertSame([], $unknown['subjects']);
	}//end testTopSubjectsAppliesTheTeamChannelAndEmployeeFilters()

	/**
	 * Two employees on one day: one row each, with a value for every working hour.
	 *
	 * @return void
	 */
	public function testOccupancyHoldsEveryHourForEveryEmployee(): void {
		$this->rows = [
			// Half of 9:00 and half of 10:00.
			self::row(at: '2026-11-11T09:30:00+01:00', employee: 'alice', duration: 'PT1H'),
			// A quarter of 14:00.
			self::row(at: '2026-11-11T14:00:00+01:00', employee: 'bob', duration: 'PT15M'),
			// No duration: counted as no busy time.
			self::row(at: '2026-11-11T16:00:00+01:00', employee: 'bob'),
		];

		$result = $this->service->occupancy(from: '2026-11-11', to: '2026-11-11', filter: new ContactReportFilter());

		$this->assertSame([8, 9, 10, 11, 12, 13, 14, 15, 16, 17], $result['hours']);
		$this->assertSame(['alice', 'bob'], array_column($result['employees'], 'employee'));
		$this->assertSame([0.0, 0.5, 0.5, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $result['employees'][0]['values']);
		$this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.25, 0.0, 0.0, 0.0], $result['employees'][1]['values']);
	}//end testOccupancyHoldsEveryHourForEveryEmployee()

	/**
	 * Over several open days an hour's busy time is shared out over all of them.
	 *
	 * @return void
	 */
	public function testOccupancyDividesByTheOpenDaysOfThePeriod(): void {
		$this->rows = [self::row(at: '2026-11-10T08:00:00+01:00', employee: 'alice', duration: 'PT1H')];

		// Monday 9 to Sunday 15 November: five open days.
		$result = $this->service->occupancy(from: '2026-11-09', to: '2026-11-15', filter: new ContactReportFilter(employee: 'alice'));

		$this->assertSame(0.2, $result['employees'][0]['values'][0]);
	}//end testOccupancyDividesByTheOpenDaysOfThePeriod()

	/**
	 * 250 contacts an open day and a public holiday on Monday: about 1,000 against 1,250.
	 *
	 * @return void
	 */
	public function testForecastReadsZeroOnClosedDays(): void {
		$this->holidays = ['2026-11-16'];
		$day = new DateTimeImmutable('2026-10-18');
		while ($day < new DateTimeImmutable('2026-11-15')) {
			if ((int)$day->format('N') <= 5) {
				for ($i = 0; $i < 250; $i++) {
					$this->rows[] = self::row(at: $day->format('Y-m-d').'T10:00:00+01:00');
				}
			}

			$day = $day->modify('+1 day');
		}

		$result = $this->service->forecast(filter: new ContactReportFilter());

		$this->assertTrue($result['enoughHistory']);
		$this->assertSame(250.0, $result['dailyAverage']);
		$this->assertSame(
			['2026-11-16' => 0, '2026-11-17' => 250, '2026-11-18' => 250, '2026-11-19' => 250, '2026-11-20' => 250, '2026-11-21' => 0, '2026-11-22' => 0],
			array_combine(array_column($result['days'], 'date'), array_column($result['days'], 'expected'))
		);
		$this->assertFalse($result['days'][0]['open']);
		$this->assertSame(1000, $result['total']);
		$this->assertSame(1250, $result['normalWeekTotal']);
	}//end testForecastReadsZeroOnClosedDays()

	/**
	 * Fewer than two weeks of contact moments: the forecast says so and holds no figures.
	 *
	 * @return void
	 */
	public function testForecastRefusesWithTooLittleHistory(): void {
		$this->rows = [self::row(at: '2026-11-05T10:00:00+01:00'), self::row(at: '2026-11-13T10:00:00+01:00')];

		$result = $this->service->forecast(filter: new ContactReportFilter());

		$this->assertFalse($result['enoughHistory']);
		$this->assertSame([], $result['days']);
		$this->assertArrayNotHasKey('total', $result);
		$this->assertStringContainsString('two weeks', $result['message']);

		$this->rows = [];
		$this->assertFalse($this->service->forecast(filter: new ContactReportFilter())['enoughHistory']);
	}//end testForecastRefusesWithTooLittleHistory()

	/**
	 * The workbook holds a summary, one row per contact and a team sheet, for the filtered team only.
	 *
	 * @return void
	 */
	public function testWorkbookHoldsTheThreeSheetsForTheFilteredTeam(): void {
		$this->rows = [
			self::row(at: '2026-11-10T10:00:00+01:00', subject: 'Paspoort', employee: 'alice', duration: 'PT2M'),
			self::row(at: '2026-11-11T10:00:00+01:00', subject: 'Rijbewijs', employee: 'alice', duration: 'PT4M'),
			self::row(at: '2026-11-11T11:00:00+01:00', subject: 'Afval', employee: 'bob'),
			// Another week.
			self::row(at: '2026-11-18T10:00:00+01:00', subject: 'Paspoort', employee: 'alice'),
		];

		$parts = self::unzip(bytes: $this->service->workbook(from: '2026-11-09', to: '2026-11-15', filter: new ContactReportFilter(team: 'burgerzaken')));

		$this->assertMatchesRegularExpression('/name="Summary".*name="Contacts".*name="Team"/s', $parts['xl/workbook.xml']);
		$this->assertStringContainsString('<t xml:space="preserve">burgerzaken</t>', $parts['xl/worksheets/sheet1.xml']);
		$this->assertStringContainsString('<t xml:space="preserve">Contacts</t></is></c><c r="B6"><v>2</v>', $parts['xl/worksheets/sheet1.xml']);
		$this->assertSame(3, substr_count($parts['xl/worksheets/sheet2.xml'], '<row '));
		$this->assertStringContainsString('Rijbewijs', $parts['xl/worksheets/sheet2.xml']);
		$this->assertStringNotContainsString('Afval', $parts['xl/worksheets/sheet2.xml']);
		$this->assertStringNotContainsString('bob', $parts['xl/worksheets/sheet3.xml']);
		// Alice: two contacts, average of 2 and 4 minutes.
		$this->assertStringContainsString('<t xml:space="preserve">3:00</t>', $parts['xl/worksheets/sheet3.xml']);
	}//end testWorkbookHoldsTheThreeSheetsForTheFilteredTeam()

	/**
	 * The PDF holds the figures the report shows.
	 *
	 * @return void
	 */
	public function testDocumentHoldsTheReportFigures(): void {
		$this->rows = [
			self::row(at: '2026-11-10T10:00:00+01:00', subject: 'Paspoort', employee: 'alice'),
			self::row(at: '2026-11-10T11:00:00+01:00', subject: 'Paspoort', employee: 'bob'),
			self::row(at: '2026-11-10T12:00:00+01:00', subject: 'Afval', employee: 'bob', channel: 'email'),
		];

		$pdf = $this->service->document(from: '2026-11-09', to: '2026-11-15', filter: new ContactReportFilter());

		$this->assertStringStartsWith('%PDF-1.4', $pdf);
		$this->assertStringContainsString('(Contact report) Tj', $pdf);
		$this->assertStringContainsString('(2026-11-09 - 2026-11-15) Tj', $pdf);
		$this->assertMatchesRegularExpression('/\(Contacts\) Tj ET\n[^\n]*\(3\) Tj/', $pdf);
		$this->assertMatchesRegularExpression('/\(1\. Paspoort\) Tj ET\n[^\n]*\(2\) Tj/', $pdf);
		$this->assertStringContainsString('(Contacts via email) Tj', $pdf);
	}//end testDocumentHoldsTheReportFigures()

	/**
	 * The parts of a zip archive.
	 *
	 * @param string $bytes The archive.
	 *
	 * @return array<string, string> Part name => contents.
	 */
	private static function unzip(string $bytes): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'plq-test-');
		file_put_contents($path, $bytes);
		$zip = new ZipArchive();
		$zip->open($path);
		$parts = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$parts[$name] = (string)$zip->getFromName($name);
		}

		$zip->close();
		unlink($path);
		return $parts;
	}//end unzip()
}//end class
