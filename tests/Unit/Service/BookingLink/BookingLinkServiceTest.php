<?php

/**
 * Unit tests for BookingLinkService and OwnCalendar.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\BookingLink
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\BookingLink;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\Service\BookingLink\BookingLinkOwner;
use OCA\Pipelinq\Service\BookingLink\BookingLinkService;
use OCA\Pipelinq\Service\BookingLink\BookingLinkTokens;
use OCA\Pipelinq\Service\BookingLink\OwnCalendar;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarEventBuilder;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICalendarQuery;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A calendar that takes events and says whether it is shared and writable.
 */
interface BookableTestCalendar extends ICreateFromString, ICalendarIsShared, ICalendarIsWritable {
}//end interface

/**
 * The free slots behind a booking link, and a booking landing in the owner's calendar.
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */
#[CoversClass(BookingLinkService::class)]
#[CoversClass(OwnCalendar::class)]
#[CoversClass(BookingLinkTokens::class)]
#[CoversClass(BookingLinkOwner::class)]
class BookingLinkServiceTest extends TestCase {

	/**
	 * Daan's link.
	 *
	 * @var string
	 */
	private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';

	/**
	 * Daan's zone.
	 *
	 * @var string
	 */
	private const ZONE = 'Europe/Amsterdam';

	/**
	 * Events in Daan's calendars, per calendar uri.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $events = [];

	/**
	 * The calendars the last search was limited to.
	 *
	 * @var array<int, string>
	 */
	private array $searched = [];

	/**
	 * What the event builder was given, and where it wrote.
	 *
	 * @var array<string, mixed>
	 */
	private array $built = [];

	/**
	 * Daan's calendars: uri => [shared, writable].
	 *
	 * @var array<string, array{0: bool, 1: bool}>
	 */
	private array $calendars = ['personal' => [false, true], 'team' => [true, true]];

	/**
	 * The current time: Monday 23 November 2026, 08:00 in Amsterdam.
	 *
	 * @var string
	 */
	private string $now = '2026-11-23T08:00:00+01:00';

	/**
	 * A local moment as a calendar search returns it.
	 *
	 * @param string $local `Y-m-d H:i` in Daan's zone.
	 *
	 * @return array<int, array<int, mixed>> The property value.
	 */
	private static function at(string $local): array {
		return [[new DateTimeImmutable($local, new DateTimeZone(self::ZONE)), []]];
	}//end at()

	/**
	 * The service over Daan's link and calendars.
	 *
	 * @return BookingLinkService The service.
	 */
	private function service(): BookingLinkService {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, string $default = ''): string => match ($app . '/' . $key) {
				'core/timezone' => self::ZONE,
				'pipelinq/' . BookingLinkTokens::KEY => self::TOKEN,
				default => $default,
			}
		);
		$config->method('getUsersForUserValue')->willReturnCallback(
			static fn (string $app, string $key, string $value): array => array_values(array_filter(['daan'], static fn (): bool => $value === self::TOKEN))
		);

		$daan = $this->createMock(originalClassName: IUser::class);
		$daan->method('getUID')->willReturn('daan');
		$daan->method('isEnabled')->willReturn(true);
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnMap([['daan', $daan]]);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => (new DateTimeImmutable($this->now))->getTimestamp());

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->method('getUserLanguage')->willReturn('nl');
		$factory->method('get')->willReturn($l10n);

		return new BookingLinkService(
			tokens: new BookingLinkTokens(config: $config, random: $this->createMock(originalClassName: ISecureRandom::class), users: $users),
			calendar: new OwnCalendar(calendars: $this->manager()),
			time: $time,
			owners: new BookingLinkOwner(config: $config, users: $users, l10n: $factory)
		);
	}//end service()

	/**
	 * A calendar manager over Daan's calendars and events.
	 *
	 * @return IManager The manager.
	 */
	private function manager(): IManager {
		$manager = $this->createMock(originalClassName: IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturnCallback(
			function (string $principal): array {
				$this->assertSame(expected: 'principals/users/daan', actual: $principal);
				$list = [];
				foreach ($this->calendars as $uri => [$shared, $writable]) {
					$calendar = $this->createMock(originalClassName: BookableTestCalendar::class);
					$calendar->method('getUri')->willReturn($uri);
					$calendar->method('isShared')->willReturn($shared);
					$calendar->method('isWritable')->willReturn($writable);
					$calendar->method('isDeleted')->willReturn(false);
					$list[] = $calendar;
				}

				return $list;
			}
		);
		$manager->method('newQuery')->willReturnCallback(
			function (): ICalendarQuery {
				$this->searched = [];
				$query = $this->createMock(originalClassName: ICalendarQuery::class);
				$query->method('addSearchCalendar')->willReturnCallback(
					function (string $uri): void {
						$this->searched[] = $uri;
					}
				);
				return $query;
			}
		);
		$manager->method('searchForPrincipal')->willReturnCallback(
			function (): array {
				$results = [];
				foreach ($this->searched as $uri) {
					foreach (($this->events[$uri] ?? []) as $event) {
						$results[] = ['calendar-uri' => $uri, 'objects' => [$event]];
					}
				}

				return $results;
			}
		);
		$manager->method('createEventBuilder')->willReturnCallback(fn (): ICalendarEventBuilder => $this->builder());

		return $manager;
	}//end manager()

	/**
	 * An event builder that records what it is given.
	 *
	 * @return ICalendarEventBuilder The builder.
	 */
	private function builder(): ICalendarEventBuilder {
		$builder = $this->createMock(originalClassName: ICalendarEventBuilder::class);
		$record = function (string $field) use ($builder): \Closure {
			return function (mixed $value) use ($field, $builder): ICalendarEventBuilder {
				$this->built[$field] = $value;
				return $builder;
			};
		};
		$builder->method('setStartDate')->willReturnCallback($record('start'));
		$builder->method('setEndDate')->willReturnCallback($record('end'));
		$builder->method('setSummary')->willReturnCallback($record('summary'));
		$builder->method('setDescription')->willReturnCallback($record('description'));
		$builder->method('createInCalendar')->willReturnCallback(
			function (ICreateFromString $calendar): string {
				$this->built['calendar'] = $calendar->getUri();
				return 'booked.ics';
			}
		);

		return $builder;
	}//end builder()

	/**
	 * Anna books a start through a link.
	 *
	 * @param BookingLinkService $service The service.
	 * @param string             $start   The start she sends.
	 * @param string             $name    The name she gives.
	 * @param string             $token   The link she holds.
	 *
	 * @return string The outcome.
	 */
	private static function anna(BookingLinkService $service, string $start, string $name = 'Anna', string $token = self::TOKEN): string {
		return $service->book(token: $token, start: $start, name: $name, email: 'anna@example.org');
	}//end anna()

	/**
	 * The local start times of the slots of a day.
	 *
	 * @param array<int, array{start: string, end: string}>|null $slots The slots.
	 *
	 * @return array<int, string> `H:i` per slot.
	 */
	private static function starts(?array $slots): array {
		return array_map(
			static fn (array $slot): string => (new DateTimeImmutable($slot['start']))->setTimezone(new DateTimeZone(self::ZONE))->format('H:i'),
			($slots ?? [])
		);
	}//end starts()

	/**
	 * The board's scenario: a meeting on Tuesday at 11:00 is not offered, and 10:00 books into the calendar.
	 *
	 * @return void
	 */
	public function testABusyHourIsNotOfferedAndABookingLandsInTheCalendar(): void {
		$this->events['personal'] = [
			['DTSTART' => self::at(local: '2026-11-24 11:00'), 'DTEND' => self::at(local: '2026-11-24 12:00'), 'SUMMARY' => [['Steering group', []]]],
		];
		$service = $this->service();

		$starts = self::starts(slots: $service->slots(token: self::TOKEN, date: '2026-11-24'));

		$this->assertSame(expected: ['personal'], actual: $this->searched);
		$this->assertCount(expectedCount: 14, haystack: $starts);
		$this->assertSame(expected: '09:00', actual: $starts[0]);
		$this->assertSame(expected: '16:30', actual: $starts[13]);
		$this->assertNotContains(needle: '11:00', haystack: $starts);
		$this->assertNotContains(needle: '11:30', haystack: $starts);
		$this->assertContains(needle: '10:00', haystack: $starts);

		$outcome = $service->book(token: self::TOKEN, start: '2026-11-24T10:00:00+01:00', name: ' Anna de Boer ', email: 'anna@example.org');

		$this->assertSame(expected: BookingLinkService::BOOKED, actual: $outcome);
		$this->assertSame(expected: 'personal', actual: $this->built['calendar']);
		$this->assertSame(expected: 'Meeting with Anna de Boer', actual: $this->built['summary']);
		$this->assertStringContainsString(needle: 'anna@example.org', haystack: $this->built['description']);
		$this->assertInstanceOf(expected: DateTimeInterface::class, actual: $this->built['start']);
		$startUtc = (new DateTimeImmutable('@' . $this->built['start']->getTimestamp()))->format(DATE_ATOM);
		$this->assertSame(expected: '2026-11-24T09:00:00+00:00', actual: $startUtc);
		$this->assertSame(expected: 1800, actual: $this->built['end']->getTimestamp() - $this->built['start']->getTimestamp());
	}//end testABusyHourIsNotOfferedAndABookingLandsInTheCalendar()

	/**
	 * Nextcloud's calendar search gives a property allowed once as one
	 * [value, parameters] pair, not a list of pairs; such an event blocks its
	 * hour too (it used to fail the slots page with a 500).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
	 */
	public function testAnEventInNextcloudsSearchShapeBlocksItsHour(): void {
		$zone = new DateTimeZone(self::ZONE);
		$this->events['personal'] = [[
			'DTSTART' => [new DateTimeImmutable('2026-11-24 11:00', $zone), []],
			'DTEND'   => [new DateTimeImmutable('2026-11-24 12:00', $zone), []],
			'SUMMARY' => [['Overleg team', []]],
		], [
			'DTSTART' => [new DateTimeImmutable('2026-11-24 14:00', $zone), []],
			'DTEND'   => [new DateTimeImmutable('2026-11-24 15:00', $zone), []],
			'STATUS'  => ['CANCELLED', []],
		]];

		$starts = self::starts(slots: $this->service()->slots(token: self::TOKEN, date: '2026-11-24'));

		$this->assertNotContains(needle: '11:00', haystack: $starts);
		$this->assertNotContains(needle: '11:30', haystack: $starts);
		$this->assertContains(needle: '10:00', haystack: $starts);
		$this->assertContains(needle: '14:00', haystack: $starts);
	}//end testAnEventInNextcloudsSearchShapeBlocksItsHour()

	/**
	 * A busy hour cannot be booked, even when the booker sends it.
	 *
	 * @return void
	 */
	public function testABusySlotIsRefused(): void {
		$this->events['personal'] = [['DTSTART' => self::at(local: '2026-11-24 11:00'), 'DTEND' => self::at(local: '2026-11-24 12:00')]];

		$outcome = $this->service()->book(token: self::TOKEN, start: '2026-11-24T11:00:00+01:00', name: 'Anna', email: 'anna@example.org');

		$this->assertSame(expected: BookingLinkService::NOT_FREE, actual: $outcome);
		$this->assertArrayNotHasKey(key: 'calendar', array: $this->built);
	}//end testABusySlotIsRefused()

	/**
	 * A start between two slots, or outside the working day, is not a free slot.
	 *
	 * @return void
	 */
	public function testAStartOffTheSlotGridIsRefused(): void {
		$service = $this->service();

		$this->assertSame(expected: BookingLinkService::NOT_FREE, actual: self::anna(service: $service, start: '2026-11-24T10:10:00+01:00'));
		$this->assertSame(expected: BookingLinkService::NOT_FREE, actual: self::anna(service: $service, start: '2026-11-24T18:00:00+01:00'));
	}//end testAStartOffTheSlotGridIsRefused()

	/**
	 * Events in a shared calendar, events marked free and cancelled events do not block a slot.
	 *
	 * @return void
	 */
	public function testOnlyBusyEventsOfOwnCalendarsBlock(): void {
		$this->events['team'] = [['DTSTART' => self::at(local: '2026-11-24 09:00'), 'DTEND' => self::at(local: '2026-11-24 10:00')]];
		$this->events['personal'] = [
			['DTSTART' => self::at(local: '2026-11-24 13:00'), 'DTEND' => self::at(local: '2026-11-24 14:00'), 'TRANSP' => [['TRANSPARENT', []]]],
			['DTSTART' => self::at(local: '2026-11-24 14:00'), 'DTEND' => self::at(local: '2026-11-24 15:00'), 'STATUS' => [['CANCELLED', []]]],
			['DTSTART' => self::at(local: '2026-11-24 15:00')],
		];

		$starts = self::starts(slots: $this->service()->slots(token: self::TOKEN, date: '2026-11-24'));

		$this->assertContains(needle: '09:00', haystack: $starts);
		$this->assertContains(needle: '13:00', haystack: $starts);
		$this->assertContains(needle: '14:00', haystack: $starts);
		$this->assertNotContains(needle: '15:00', haystack: $starts);
		$this->assertCount(expectedCount: 15, haystack: $starts);
	}//end testOnlyBusyEventsOfOwnCalendarsBlock()

	/**
	 * Today offers only the slots still to come.
	 *
	 * @return void
	 */
	public function testTodayOffersOnlyLaterSlots(): void {
		$this->now = '2026-11-23T12:10:00+01:00';

		$starts = self::starts(slots: $this->service()->slots(token: self::TOKEN, date: '2026-11-23'));

		$this->assertSame(expected: '12:30', actual: $starts[0]);
		$this->assertCount(expectedCount: 9, haystack: $starts);
	}//end testTodayOffersOnlyLaterSlots()

	/**
	 * A weekend day, a past day, a day beyond the horizon and a malformed date have no slots.
	 *
	 * @return void
	 */
	public function testDaysThatCannotBeBookedHaveNoSlots(): void {
		$service = $this->service();

		$this->assertSame(expected: [], actual: $service->slots(token: self::TOKEN, date: '2026-11-28'));
		$this->assertSame(expected: [], actual: $service->slots(token: self::TOKEN, date: '2026-11-20'));
		$this->assertSame(expected: [], actual: $service->slots(token: self::TOKEN, date: '2027-03-01'));
		$this->assertSame(expected: [], actual: $service->slots(token: self::TOKEN, date: '2026-11-31'));
		$this->assertSame(expected: [], actual: $service->slots(token: self::TOKEN, date: 'tomorrow'));
		$this->assertNotSame(expected: [], actual: $service->slots(token: self::TOKEN, date: '2027-01-22'));
	}//end testDaysThatCannotBeBookedHaveNoSlots()

	/**
	 * A link nobody holds lists nothing and books nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownLinkIsRefused(): void {
		$service = $this->service();
		$other = str_repeat('q', 32);

		$this->assertNull(actual: $service->slots(token: $other, date: '2026-11-24'));
		$outcome = self::anna(service: $service, start: '2026-11-24T10:00:00+01:00', token: $other);
		$this->assertSame(expected: BookingLinkService::UNKNOWN_LINK, actual: $outcome);
		$this->assertArrayNotHasKey(key: 'calendar', array: $this->built);
	}//end testAnUnknownLinkIsRefused()

	/**
	 * A booking without a name, with a wrong address or without a moment is refused.
	 *
	 * @return void
	 */
	public function testAnIncompleteBookingIsRefused(): void {
		$service = $this->service();
		$start = '2026-11-24T10:00:00+01:00';

		$this->assertSame(expected: BookingLinkService::INVALID, actual: self::anna(service: $service, start: $start, name: '  '));
		$this->assertSame(expected: BookingLinkService::INVALID, actual: self::anna(service: $service, start: $start, name: str_repeat('a', 121)));
		$wrongAddress = $service->book(token: self::TOKEN, start: $start, name: 'Anna', email: 'not an address');
		$this->assertSame(expected: BookingLinkService::INVALID, actual: $wrongAddress);
		$this->assertSame(expected: BookingLinkService::INVALID, actual: self::anna(service: $service, start: 'Tuesday'));
		$this->assertArrayNotHasKey(key: 'calendar', array: $this->built);
	}//end testAnIncompleteBookingIsRefused()

	/**
	 * Without an own writable calendar nothing is written.
	 *
	 * @return void
	 */
	public function testNoWritableCalendarWritesNothing(): void {
		$this->calendars = ['personal' => [false, false], 'team' => [true, true]];

		$outcome = $this->service()->book(token: self::TOKEN, start: '2026-11-24T10:00:00+01:00', name: 'Anna', email: 'anna@example.org');

		$this->assertSame(expected: BookingLinkService::NO_CALENDAR, actual: $outcome);
		$this->assertArrayNotHasKey(key: 'calendar', array: $this->built);
	}//end testNoWritableCalendarWritesNothing()

	/**
	 * A user with only shared calendars is free all day, and is not searched at all.
	 *
	 * @return void
	 */
	public function testOnlySharedCalendarsMeanNoSearch(): void {
		$this->calendars = ['team' => [true, true]];
		$this->events['team'] = [['DTSTART' => self::at(local: '2026-11-24 09:00'), 'DTEND' => self::at(local: '2026-11-24 17:00')]];

		$this->assertCount(expectedCount: 16, haystack: $this->service()->slots(token: self::TOKEN, date: '2026-11-24'));
		$this->assertSame(expected: [], actual: $this->searched);
	}//end testOnlySharedCalendarsMeanNoSearch()
}//end class
