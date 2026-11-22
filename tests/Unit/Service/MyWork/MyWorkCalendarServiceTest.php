<?php

/**
 * Unit tests for MyWorkCalendarService and MeetingRecordLink.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\MyWork
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\MyWork;

use DateTimeImmutable;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Service\MyWork\MeetingRecordLink;
use OCA\Pipelinq\Service\MyWork\MyWorkCalendarService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarQuery;
use OCP\Calendar\IManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A calendar that says whether it is shared with the user.
 */
interface SharedAwareCalendar extends ICalendar, ICalendarIsShared {
}//end interface

/**
 * The linked meetings of the next seven days, from the user's own calendars.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */
#[CoversClass(MyWorkCalendarService::class)]
#[CoversClass(MeetingRecordLink::class)]
class MyWorkCalendarServiceTest extends TestCase {

	/**
	 * 2026-11-21T09:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1795251600;

	/**
	 * A client's id.
	 *
	 * @var string
	 */
	private const CLIENT = '0b6a2c1e-5d1f-4c8e-9a52-6f3e1d2c4b7a';

	/**
	 * What the query was asked: the calendars, pattern, properties, range.
	 *
	 * @var array<string, mixed>
	 */
	private array $asked = ['calendars' => [], 'properties' => []];

	/**
	 * A service over the given calendars, search results and readable records.
	 *
	 * @param array<int, array{0: string, 1: bool}> $calendars Per calendar: uri and whether it is shared.
	 * @param array<int, array<string, mixed>>      $results   What the calendar search answers.
	 * @param array<string, array<string, mixed>>   $records   Record id to its data; others cannot be read.
	 *
	 * @return MyWorkCalendarService The service.
	 */
	private function service(array $calendars, array $results, array $records = []): MyWorkCalendarService {
		$query = $this->createMock(originalClassName: ICalendarQuery::class);
		$query->method('addSearchCalendar')->willReturnCallback(function (string $uri): void {
			$this->asked['calendars'][] = $uri;
		});
		$query->method('setSearchPattern')->willReturnCallback(function (string $pattern): void {
			$this->asked['pattern'] = $pattern;
		});
		$query->method('addSearchProperty')->willReturnCallback(function (string $property): void {
			$this->asked['properties'][] = $property;
		});
		$query->method('setTimerangeStart')->willReturnCallback(function (DateTimeImmutable $start): void {
			$this->asked['start'] = $start->format('c');
		});
		$query->method('setTimerangeEnd')->willReturnCallback(function (DateTimeImmutable $end): void {
			$this->asked['end'] = $end->format('c');
		});

		$owned = [];
		foreach ($calendars as [$uri, $shared]) {
			$owned[] = $this->createConfiguredMock(
				originalClassName: SharedAwareCalendar::class,
				configuration: ['getUri' => $uri, 'isShared' => $shared]
			);
		}

		$manager = $this->createMock(originalClassName: IManager::class);
		$manager->method('getCalendarsForPrincipal')->with('principals/users/daan')->willReturn($owned);
		$manager->method('newQuery')->with('principals/users/daan')->willReturn($query);
		$manager->method('searchForPrincipal')->willReturn($results);

		$reader = $this->createMock(originalClassName: CallerObjectReaderInterface::class);
		$reader->method('read')->willReturnCallback(static fn (string $id): ?array => $records[$id] ?? null);

		return new MyWorkCalendarService(
			calendars: $manager,
			time: $this->createConfiguredMock(originalClassName: ITimeFactory::class, configuration: ['getTime' => self::NOW]),
			records: $reader,
			links: new MeetingRecordLink(),
		);
	}//end service()

	/**
	 * One calendar search result as Nextcloud's calendar manager shapes it.
	 *
	 * @param string $uid         The event uid.
	 * @param string $summary     The title.
	 * @param string $start       The start, ISO-8601.
	 * @param string $description The description.
	 * @param string $location    The location.
	 *
	 * @return array<string, mixed> The result.
	 */
	private static function event(string $uid, string $summary, string $start, string $description = '', string $location = ''): array {
		return [
			'objects' => [
				[
					'UID' => [[$uid, []]],
					'SUMMARY' => [[$summary, []]],
					'DTSTART' => [[new DateTimeImmutable($start), ['TZID' => 'Europe/Amsterdam']]],
					'DTEND' => [[new DateTimeImmutable($start . ' +1 hour'), []]],
					'DESCRIPTION' => [[$description, []]],
					'LOCATION' => [[$location, []]],
				],
			],
			'calendar-key' => '1',
			'calendar-uri' => 'personal',
		];
	}//end event()

	/**
	 * A meeting with a client this week is listed with its time and the client's name.
	 *
	 * @return void
	 */
	public function testAMeetingWithAClientThisWeek(): void {
		$service = $this->service(
			calendars: [['personal', false]],
			results: [
				self::event(
					uid: 'e1',
					summary: 'Kennismaking',
					start: '2026-11-26T14:00:00+01:00',
					description: 'Zie https://nc.example/apps/pipelinq/clients/' . strtoupper(self::CLIENT)
				),
			],
			records: [self::CLIENT => ['name' => 'Bakkerij De Jong']]
		);

		$this->assertSame(
			expected: [
				[
					'id' => 'e1',
					'title' => 'Kennismaking',
					'startsAt' => '2026-11-26T13:00:00+00:00',
					'endsAt' => '2026-11-26T14:00:00+00:00',
					'recordKind' => 'client',
					'recordId' => self::CLIENT,
					'recordName' => 'Bakkerij De Jong',
					'routeName' => 'ClientDetail',
				],
			],
			actual: $service->upcoming(userId: 'daan')
		);
		$this->assertSame(expected: ['personal'], actual: $this->asked['calendars']);
		$this->assertSame(expected: '/apps/pipelinq/', actual: $this->asked['pattern']);
		$this->assertSame(expected: ['DESCRIPTION', 'LOCATION'], actual: $this->asked['properties']);
		$this->assertSame(expected: '2026-11-21T09:00:00+00:00', actual: $this->asked['start']);
		$this->assertSame(expected: '2026-11-28T09:00:00+00:00', actual: $this->asked['end']);
	}//end testAMeetingWithAClientThisWeek()

	/**
	 * A calendar a colleague shares is not searched; without an own calendar nothing is.
	 *
	 * @return void
	 */
	public function testAnotherUsersCalendarStaysOut(): void {
		$this->service(calendars: [['personal', false], ['shared-by-sanne', true]], results: [])->upcoming(userId: 'daan');
		$this->assertSame(expected: ['personal'], actual: $this->asked['calendars']);

		$this->asked = ['calendars' => [], 'properties' => []];
		$only = $this->service(calendars: [['shared-by-sanne', true]], results: [self::event(uid: 'e1', summary: 'x', start: '2026-11-26T14:00:00Z')]);
		$this->assertSame(expected: [], actual: $only->upcoming(userId: 'daan'));
		$this->assertArrayNotHasKey(key: 'pattern', array: $this->asked);
	}//end testAnotherUsersCalendarStaysOut()

	/**
	 * Without calendars (no Calendar app) the list is empty, not an error.
	 *
	 * @return void
	 */
	public function testNoCalendarAppGivesNoMeetings(): void {
		$this->assertSame(expected: [], actual: $this->service(calendars: [], results: [])->upcoming(userId: 'daan'));
	}//end testNoCalendarAppGivesNoMeetings()

	/**
	 * An event without a link, or linking a record that cannot be read, is left out; the rest come soonest first.
	 *
	 * @return void
	 */
	public function testOnlyReadableLinkedEventsSoonestFirst(): void {
		$lead = '5f0c7a1b-2d3e-4f5a-8b6c-7d8e9f0a1b2c';
		$hidden = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
		$service = $this->service(
			calendars: [['personal', false]],
			results: [
				self::event(uid: 'late', summary: 'Offerte', start: '2026-11-27T10:00:00Z', location: '/index.php/apps/pipelinq/leads/' . $lead),
				self::event(uid: 'plain', summary: 'Tandarts', start: '2026-11-22T10:00:00Z', description: 'no link'),
				self::event(uid: 'secret', summary: 'Gesprek', start: '2026-11-23T10:00:00Z', description: '/apps/pipelinq/contacts/' . $hidden),
				self::event(uid: 'soon', summary: 'Bellen', start: '2026-11-24T10:00:00Z', description: '/apps/pipelinq/clients/' . self::CLIENT),
			],
			records: [self::CLIENT => ['name' => ' Bakkerij De Jong '], $lead => ['title' => 'Zonnepanelen dak']]
		);

		$meetings = $service->upcoming(userId: 'daan');

		$this->assertSame(expected: ['soon', 'late'], actual: array_column($meetings, 'id'));
		$this->assertSame(expected: 'Bakkerij De Jong', actual: $meetings[0]['recordName']);
		$this->assertSame(
			expected: ['lead', 'LeadDetail', 'Zonnepanelen dak'],
			actual: [$meetings[1]['recordKind'], $meetings[1]['routeName'], $meetings[1]['recordName']]
		);
	}//end testOnlyReadableLinkedEventsSoonestFirst()

	/**
	 * The link reader finds the first record page in the description, then the location.
	 *
	 * @return void
	 */
	public function testTheLinkReader(): void {
		$links = new MeetingRecordLink();

		$this->assertNull(actual: $links->recordIn(texts: ['', 'Room 3']));
		$this->assertNull(actual: $links->recordIn(texts: ['/apps/pipelinq/clients/not-a-uuid']));
		$this->assertSame(
			expected: ['kind' => 'contact', 'schemaKey' => 'contact_schema', 'route' => 'ContactDetail', 'id' => self::CLIENT],
			actual: $links->recordIn(texts: ['Agenda', 'https://nc.example/apps/pipelinq/contacts/' . self::CLIENT . '?tab=notes'])
		);
	}//end testTheLinkReader()
}//end class
