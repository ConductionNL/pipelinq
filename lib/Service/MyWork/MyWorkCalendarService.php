<?php

/**
 * Pipelinq MyWorkCalendarService.
 *
 * The user's meetings of the next seven days that are about a pipelinq
 * client, contact or lead, for My work. Read through Nextcloud's calendar
 * manager from the user's own calendars only: a calendar a colleague shares
 * stays out. An event counts when its description or location links the
 * record's page. The record's name is read through OpenRegister as the user;
 * a link to a record that cannot be read lists nothing.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\MyWork
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

namespace OCA\Pipelinq\Service\MyWork;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\IManager;

/**
 * Upcoming meetings linked to a pipelinq record.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */
class MyWorkCalendarService {

	/**
	 * How far ahead My work looks.
	 *
	 * @var string
	 */
	private const WINDOW = 'P7D';

	/**
	 * The most events read from the calendars in one go.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param IManager                    $calendars The calendar manager.
	 * @param ITimeFactory                $time      The clock.
	 * @param CallerObjectReaderInterface $records   Reads the linked record with the user's rights.
	 * @param MeetingRecordLink           $links     Finds the record an event links.
	 */
	public function __construct(
		private readonly IManager $calendars,
		private readonly ITimeFactory $time,
		private readonly CallerObjectReaderInterface $records,
		private readonly MeetingRecordLink $links,
	) {
	}//end __construct()

	/**
	 * The user's linked meetings of the next seven days, soonest first.
	 *
	 * Empty when the user has no calendar of their own, or no event in the
	 * window links a record they may open.
	 *
	 * @param string $userId The user.
	 *
	 * @return array<int, array<string, string>> Per meeting: id, title, startsAt, endsAt, recordKind, recordId, recordName, routeName.
	 *
	 * @spec openspec/changes/my-work-roadmap/tasks.md#task-5.1
	 */
	public function upcoming(string $userId): array {
		$principal = 'principals/users/' . $userId;
		$own = $this->ownCalendarUris(principal: $principal);
		if ($own === []) {
			return [];
		}

		$from = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
		$query = $this->calendars->newQuery($principal);
		foreach ($own as $uri) {
			$query->addSearchCalendar($uri);
		}

		$query->setSearchPattern('/apps/pipelinq/');
		$query->addSearchProperty('DESCRIPTION');
		$query->addSearchProperty('LOCATION');
		$query->addType('VEVENT');
		$query->setTimerangeStart($from);
		$query->setTimerangeEnd($from->add(new DateInterval(self::WINDOW)));
		$query->setLimit(self::READ_LIMIT);

		$meetings = [];
		foreach ($this->calendars->searchForPrincipal($query) as $result) {
			$meeting = $this->meeting(event: (array)(($result['objects'] ?? [])[0] ?? []));
			if ($meeting !== null) {
				$meetings[] = $meeting;
			}
		}

		usort($meetings, static fn (array $left, array $right): int => strcmp($left['startsAt'], $right['startsAt']));
		return $meetings;
	}//end upcoming()

	/**
	 * The uris of the principal's own calendars: shared calendars are left out.
	 *
	 * @param string $principal The principal uri.
	 *
	 * @return array<int, string> The uris.
	 */
	private function ownCalendarUris(string $principal): array {
		$uris = [];
		foreach ($this->calendars->getCalendarsForPrincipal($principal) as $calendar) {
			if ($calendar instanceof ICalendarIsShared && $calendar->isShared() === true) {
				continue;
			}

			$uris[] = $calendar->getUri();
		}

		return $uris;
	}//end ownCalendarUris()

	/**
	 * One meeting as My work lists it, or null when it links no record the user may open.
	 *
	 * @param array<string, mixed> $event The event's properties, as the calendar search returns them.
	 *
	 * @return array<string, string>|null The meeting.
	 */
	private function meeting(array $event): ?array {
		$record = $this->links->in(texts: [self::text(event: $event, name: 'DESCRIPTION'), self::text(event: $event, name: 'LOCATION')]);
		if ($record === null) {
			return null;
		}

		$data = $this->records->read(id: $record['id'], schemaKey: $record['schemaKey']);
		if ($data === null) {
			return null;
		}

		return [
			'id' => self::text(event: $event, name: 'UID'),
			'title' => self::text(event: $event, name: 'SUMMARY'),
			'startsAt' => self::moment(event: $event, name: 'DTSTART'),
			'endsAt' => self::moment(event: $event, name: 'DTEND'),
			'recordKind' => $record['kind'],
			'recordId' => $record['id'],
			'recordName' => trim((string)($data['name'] ?? ($data['title'] ?? ''))),
			'routeName' => $record['route'],
		];
	}//end meeting()

	/**
	 * The first value of an event property as text.
	 *
	 * @param array<string, mixed> $event The event's properties.
	 * @param string               $name  The property.
	 *
	 * @return string The value, or ''.
	 */
	private static function text(array $event, string $name): string {
		$value = (($event[$name] ?? [])[0] ?? [])[0] ?? '';
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end text()

	/**
	 * The first value of a date-time event property, in UTC as ISO-8601.
	 *
	 * @param array<string, mixed> $event The event's properties.
	 * @param string               $name  The property.
	 *
	 * @return string The moment, or '' when the event has none.
	 */
	private static function moment(array $event, string $name): string {
		$value = (($event[$name] ?? [])[0] ?? [])[0] ?? null;
		if ($value instanceof DateTimeInterface) {
			return (new DateTimeImmutable('@' . $value->getTimestamp()))->setTimezone(new DateTimeZone('UTC'))->format('c');
		}

		return self::text(event: $event, name: $name);
	}//end moment()
}//end class
