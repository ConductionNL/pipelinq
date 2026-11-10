<?php

/**
 * Unit tests for the notes and messages kinds of ActivityTimelineService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/activity-timeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ActivityItemNormalizer;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\Pipelinq\Service\ActivityTimelineReader;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCA\Pipelinq\Service\NotesService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\IDateTimeFormatter;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The client's activity also lists its notes and its WhatsApp/SMS messages,
 * and names every author by display name.
 */
class ActivityTimelineServiceKindsTest extends TestCase {

	/**
	 * Objects the fake object service answers, keyed by schema id.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * The filters every findAll call received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * OpenRegister's email link reader as the container answers it, or null
	 * for an OpenRegister without one.
	 *
	 * @var object|null
	 */
	private ?object $emailReader = null;

	/**
	 * The configured register id.
	 *
	 * @var string
	 */
	private string $registerId = 'reg-1';

	/**
	 * Build the service over fakes.
	 *
	 * @param array<int,array<string,mixed>> $notes The notes the notes service answers.
	 *
	 * @return ActivityTimelineService
	 */
	private function makeService(array $notes=[]): ActivityTimelineService {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default='') => match ($key) {
				'register' => $this->registerId,
				'task_schema' => 'task-s',
				'contact_schema' => 'contact-s',
				'message_schema' => 'message-s',
				'booking_schema' => 'booking-s',
				'service_schema' => 'service-s',
				default => $default,
			}
		);

		$tickets = $this->createMock(originalClassName: TicketService::class);
		$tickets->method('getSchemaId')->willReturn('ticket-s');

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $params): array {
				$filters = $params['filters'];
				$this->queries[] = $filters;
				$rows = $this->rows[$filters['schema']] ?? [];
				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if ($key === 'register' || $key === 'schema') {
									continue;
								}

								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);

		$service = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$service->method('getObject')->willReturn(['name' => 'Locatiebezoek']);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($service): ?ObjectEntityInterface {
				if ($id === 'svc-1') {
					return $service;
				}

				return null;
			}
		);

		$notesService = $this->createMock(originalClassName: NotesService::class);
		$notesService->method('getNotes')->willReturnCallback(
			static function (string $type, string $id) use ($notes): array {
				if ($type === 'openregister' && $id === 'client-1') {
					return $notes;
				}

				return [];
			}
		);

		$fatma = $this->createMock(originalClassName: IUser::class);
		$fatma->method('getDisplayName')->willReturn('Fatma Yildiz');
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnMap([['fatma', $fatma], ['gone', null]]);

		$reader = $this->emailReader;
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			static fn (string $id): bool => $reader !== null && $id === 'OCA\\OpenRegister\\Contract\\EmailLinkReaderInterface'
		);
		$container->method('get')->willReturnCallback(static fn (): ?object => $reader);

		$formatter = $this->createMock(originalClassName: IDateTimeFormatter::class);
		$formatter->method('formatDateTime')->willReturn('8 oktober 2026 om 14:00');

		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new ActivityTimelineService(
			appConfig: $config,
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $logger,
			ticketService: $tickets,
			reader: new ActivityTimelineReader(
				appConfig: $config,
				objectService: $objects,
				notesService: $notesService,
				container: $container,
				logger: $logger,
			),
			itemNormalizer: new ActivityItemNormalizer(userManager: $users, dateTimeFormatter: $formatter),
		);
	}//end makeService()

	/**
	 * A client's notes are activities of kind note, newest first with the rest.
	 *
	 * @return void
	 */
	public function testClientNotesAreNoteActivities(): void {
		$this->rows['ticket-s'] = [
			[
				'id' => 't-1',
				'ticketType' => 'interaction',
				'client' => 'client-1',
				'title' => 'Called',
				'occurredAt' => '2026-10-02T09:30:00+00:00',
				'assignee' => 'fatma',
			],
		];
		$service = $this->makeService(
			notes: [
				[
					'id' => 7,
					'message' => '@pieter the street plan is under Documents.',
					'authorId' => 'fatma',
					'authorName' => 'Fatma Yildiz',
					'timestamp' => '2026-10-04T10:40:00+00:00',
				],
			]
		);

		$result = $service->getTimeline(entityType: 'client', entityId: 'client-1', params: []);

		$this->assertSame(expected: ['note', 'interaction'], actual: array_column($result['items'], 'type'));
		$note = $result['items'][0];
		$this->assertSame(expected: '7', actual: $note['id']);
		$this->assertSame(expected: '@pieter the street plan is under Documents.', actual: $note['title']);
		$this->assertSame(expected: '2026-10-04T10:40:00+00:00', actual: $note['date']);
		$this->assertSame(expected: 'fatma', actual: $note['user']);
		$this->assertSame(expected: 'Fatma Yildiz', actual: $note['userName']);
	}//end testClientNotesAreNoteActivities()

	/**
	 * A contact moment names its handler by display name.
	 *
	 * @return void
	 */
	public function testAuthorsAreNamedByDisplayName(): void {
		$this->rows['ticket-s'] = [
			[
				'id' => 't-1',
				'ticketType' => 'interaction',
				'client' => 'client-1',
				'title' => 'Called',
				'occurredAt' => '2026-10-02T09:30:00+00:00',
				'assignee' => 'fatma',
			],
			[
				'id' => 't-2',
				'ticketType' => 'interaction',
				'client' => 'client-1',
				'title' => 'Mailed',
				'occurredAt' => '2026-10-01T09:30:00+00:00',
				'assignee' => 'gone',
			],
		];

		$result = $this->makeService()->getTimeline(entityType: 'client', entityId: 'client-1', params: []);

		$this->assertSame(expected: ['Fatma Yildiz', 'gone'], actual: array_column($result['items'], 'userName'));
	}//end testAuthorsAreNamedByDisplayName()

	/**
	 * A client's messages are those of its contact persons.
	 *
	 * @return void
	 */
	public function testClientMessagesComeFromItsContacts(): void {
		$this->rows['contact-s'] = [
			['id' => 'p-1', 'client' => 'client-1'],
			['id' => 'p-9', 'client' => 'client-9'],
		];
		$this->rows['message-s'] = [
			[
				'id' => 'm-1',
				'contactId' => 'p-1',
				'channel' => 'whatsapp',
				'direction' => 'outbound',
				'body' => 'Your appointment is on 8 October, 14:00.',
				'deliveryStatus' => 'delivered',
				'sentAt' => '2026-10-05T08:00:00+00:00',
			],
			['id' => 'm-2', 'contactId' => 'p-9', 'channel' => 'sms', 'body' => 'Not this client', 'sentAt' => '2026-10-06T08:00:00+00:00'],
		];

		$result = $this->makeService()->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => ['message']]);

		$this->assertSame(expected: 1, actual: $result['total']);
		$message = $result['items'][0];
		$this->assertSame(expected: 'message', actual: $message['type']);
		$this->assertSame(expected: 'm-1', actual: $message['id']);
		$this->assertSame(expected: 'Your appointment is on 8 October, 14:00.', actual: $message['title']);
		$this->assertSame(expected: '2026-10-05T08:00:00+00:00', actual: $message['date']);
		$this->assertSame(expected: 'whatsapp', actual: $message['metadata']['channel']);
		$this->assertSame(expected: 'outbound', actual: $message['metadata']['direction']);
	}//end testClientMessagesComeFromItsContacts()

	/**
	 * A contact's messages are its own.
	 *
	 * @return void
	 */
	public function testContactMessagesAreItsOwn(): void {
		$this->rows['message-s'] = [
			['id' => 'm-1', 'contactId' => 'p-1', 'channel' => 'sms', 'body' => 'Hello', 'sentAt' => '2026-10-05T08:00:00+00:00'],
		];

		$result = $this->makeService()->getTimeline(entityType: 'contact', entityId: 'p-1', params: ['types' => ['message']]);

		$this->assertSame(expected: ['m-1'], actual: array_column($result['items'], 'id'));
	}//end testContactMessagesAreItsOwn()

	/**
	 * The notes chip reads the notes alone, and no schema is queried for them.
	 *
	 * @return void
	 */
	public function testNotesChipReadsNotesAlone(): void {
		$this->rows['ticket-s'] = [
			['id' => 't-1', 'ticketType' => 'interaction', 'client' => 'client-1', 'title' => 'Called', 'occurredAt' => '2026-10-02T09:30:00+00:00'],
		];
		$service = $this->makeService(
			notes: [
				['id' => 7, 'message' => 'Note', 'authorId' => 'fatma', 'authorName' => 'Fatma Yildiz', 'timestamp' => '2026-10-04T10:40:00+00:00'],
			]
		);

		$result = $service->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => ['note']]);

		$this->assertSame(expected: ['note'], actual: array_column($result['items'], 'type'));
		$this->assertSame(expected: [], actual: $this->queries);
	}//end testNotesChipReadsNotesAlone()

	/**
	 * A client's bookings are calendar activities, dated the day they were
	 * booked, titled with the service and the appointment's time.
	 *
	 * @return void
	 */
	public function testClientBookingsAreCalendarActivities(): void {
		$this->rows['booking-s'] = [
			[
				'id' => 'b-1',
				'customerId' => 'client-1',
				'serviceId' => 'svc-1',
				'startAt' => '2026-10-08T14:00:00+02:00',
				'statusHistory' => [['status' => 'confirmed', 'changedAt' => '2026-10-05T11:18:00+02:00']],
			],
			[
				'id' => 'b-9',
				'customerId' => 'client-9',
				'serviceId' => 'svc-1',
				'startAt' => '2026-10-09T10:00:00+02:00',
			],
		];

		$result = $this->makeService()->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => ['calendar']]);

		$this->assertSame(expected: ['b-1'], actual: array_column($result['items'], 'id'));
		$this->assertSame(expected: 'calendar', actual: $result['items'][0]['type']);
		$this->assertSame(expected: 'Locatiebezoek, 8 oktober 2026 om 14:00', actual: $result['items'][0]['title']);
		$this->assertSame(expected: '2026-10-05', actual: $result['items'][0]['date']);
	}//end testClientBookingsAreCalendarActivities()

	/**
	 * A booking's own subject goes before its service's name.
	 *
	 * @return void
	 */
	public function testBookingSubjectGoesFirst(): void {
		$this->rows['booking-s'] = [
			[
				'id' => 'b-1',
				'customerId' => 'client-1',
				'serviceId' => 'svc-1',
				'subject' => 'Locatiebezoek Parkstraat',
				'startAt' => '2026-10-08T14:00:00+02:00',
			],
		];

		$result = $this->makeService()->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => ['calendar']]);

		$this->assertSame(expected: 'Locatiebezoek Parkstraat, 8 oktober 2026 om 14:00', actual: $result['items'][0]['title']);
	}//end testBookingSubjectGoesFirst()

	/**
	 * The emails linked to the client come from OpenRegister's published reader.
	 *
	 * @return void
	 */
	public function testLinkedEmailsComeFromOpenRegister(): void {
		$this->emailReader = new class {

			/**
			 * The linked emails of an object.
			 *
			 * @param string      $objectUuid The object's uuid.
			 * @param string|null $cursor     The page cursor.
			 * @param integer     $limit      The page size.
			 *
			 * @return array The page.
			 */
			public function getLinkedEmails(string $objectUuid, ?string $cursor=null, int $limit=50): array {
				$items = [];
				if ($objectUuid === 'client-1') {
					$items[] = [
						'id' => 4,
						'subject' => 'Subsidieformulier en de lijst met documenten',
						'sender' => 'fatma@zuiddrecht.nl',
						'mailDate' => '2026-10-06T16:05:00+00:00',
						'linkedBy' => 'fatma',
					];
				}

				return ['items' => $items, 'total' => count($items), 'nextCursor' => null];
			}//end getLinkedEmails()
		};

		$result = $this->makeService()->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => ['email']]);

		$this->assertSame(expected: 1, actual: $result['total']);
		$this->assertSame(expected: 'email', actual: $result['items'][0]['type']);
		$this->assertSame(expected: 'Subsidieformulier en de lijst met documenten', actual: $result['items'][0]['title']);
		$this->assertSame(expected: '2026-10-06T16:05:00+00:00', actual: $result['items'][0]['date']);
		$this->assertSame(expected: 'Fatma Yildiz', actual: $result['items'][0]['userName']);
	}//end testLinkedEmailsComeFromOpenRegister()

	/**
	 * Without OpenRegister's reader the mail kind is empty, not an error.
	 *
	 * @return void
	 */
	public function testNoReaderNoMail(): void {
		$result = $this->makeService()->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => ['email']]);

		$this->assertSame(expected: 0, actual: $result['total']);
	}//end testNoReaderNoMail()

	/**
	 * Contact moments of the client, one per day of October 2026.
	 *
	 * @param int $count How many.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function moments(int $count): array {
		$rows = [];
		for ($day = 1; $day <= $count; $day++) {
			$rows[] = [
				'id' => 't-' . $day,
				'ticketType' => 'interaction',
				'client' => 'client-1',
				'channel' => 'worklog',
				'title' => 'Moment ' . $day,
				'occurredAt' => sprintf('2026-10-%02dT09:00:00+00:00', $day),
				'duration' => 'PT' . $day . 'M',
			];
		}

		return $rows;
	}//end moments()

	/**
	 * A types filter skips the sources of other kinds (unknown and repeated
	 * types ignored), the date bounds are inclusive (a bare end date covers its
	 * whole day) and drop undated items,
	 * and each source is queried in order.
	 *
	 * @return void
	 */
	public function testTypesAndDatesFilterTheTimeline(): void {
		$this->rows['ticket-s'] = $this->moments(count: 6);
		$this->rows['ticket-s'][] = ['id' => 't-x', 'ticketType' => 'interaction', 'client' => 'client-1', 'title' => 'Undated'];
		$this->rows['task-s'] = [['id' => 'k-1', 'clientId' => 'client-1', 'title' => 'Task', 'deadline' => '2026-10-03T00:00:00+00:00']];
		$service = $this->makeService(
			notes: [
				['id' => 1, 'message' => 'early', 'authorId' => 'fatma', 'timestamp' => '2026-09-30T10:00:00+00:00'],
				['id' => 2, 'message' => 'inside', 'authorId' => 'fatma', 'timestamp' => '2026-10-04T10:00:00+00:00'],
			]
		);

		$result = $service->getTimeline(
			entityType: 'client',
			entityId: 'client-1',
			params: ['types' => 'note, Interaction ,bogus,note', 'from' => '2026-10-02T09:00:00+00:00', 'to' => '2026-10-05']
		);

		$this->assertSame(['t-5', 't-4', '2', 't-3', 't-2'], array_column($result['items'], 'id'));
		$this->assertSame(5, $result['total']);
		$this->assertSame(['ticket-s'], array_column($this->queries, 'schema'));

		$this->queries = [];
		$result = $service->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => ['bogus'], 'from' => '', 'to' => '']);
		$this->assertSame(9, $result['total']);
		$this->assertSame(['ticket-s', 'task-s', 'message-s', 'contact-s', 'booking-s'], array_column($this->queries, 'schema'));
	}//end testTypesAndDatesFilterTheTimeline()

	/**
	 * Paging clamps the page size and the page number; a page past the end
	 * is reported as the last page but slices from where it was asked.
	 *
	 * @return void
	 */
	public function testTimelinePaging(): void {
		$this->rows['ticket-s'] = array_map(static fn (array $row): array => $row + ['parentTicket' => 'req-1'], $this->moments(count: 5));
		$service = $this->makeService();
		$page = static fn (array $params): array => $service->getTimeline(entityType: 'request', entityId: 'req-1', params: $params);

		$third = $page(['_limit' => 2, '_page' => 3]);
		$this->assertSame([['t-1'], 5, 3, 3], [array_column($third['items'], 'id'), $third['total'], $third['page'], $third['pages']]);

		$past = $page(['_limit' => 2, '_page' => 9]);
		$this->assertSame([[], 5, 3, 3], [array_column($past['items'], 'id'), $past['total'], $past['page'], $past['pages']]);

		$first = $page(['_limit' => 0, '_page' => -4]);
		$this->assertSame([5, 1, 1], [count($first['items']), $first['page'], $first['pages']]);

		$this->assertSame(['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1], $page(['_limit' => 999, 'types' => 'task']));
	}//end testTimelinePaging()

	/**
	 * Without a register the timeline and the worklog are empty and nothing
	 * is queried.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterGivesEmptyResults(): void {
		$this->registerId = '';
		$service = $this->makeService();

		$this->assertSame(['items' => [], 'total' => 0, 'page' => 4, 'pages' => 1], $service->getTimeline(entityType: 'client', entityId: 'client-1', params: ['_page' => 4]));
		$this->assertSame(
			['items' => [], 'total' => 0, 'page' => 2, 'pages' => 1, 'totalDuration' => 'PT0S'],
			$service->getWorklog(entityType: 'client', entityId: 'client-1', params: ['_page' => 2])
		);
		$this->assertSame([], $this->queries);
	}//end testUnconfiguredRegisterGivesEmptyResults()

	/**
	 * The worklog of a client or a request: newest first, paged from the
	 * clamped page, with the total duration of all its entries.
	 *
	 * @return void
	 */
	public function testWorklogPagesAndTotals(): void {
		$this->rows['ticket-s'] = $this->moments(count: 3);
		$service = $this->makeService();

		$worklog = $service->getWorklog(entityType: 'client', entityId: 'client-1', params: ['_limit' => 2, '_page' => 7]);
		$this->assertSame(['t-1'], array_column($worklog['items'], 'id'));
		$this->assertSame([3, 2, 2, 'PT6M'], [$worklog['total'], $worklog['page'], $worklog['pages'], $worklog['totalDuration']]);
		$this->assertSame(
			[['register' => 'reg-1', 'schema' => 'ticket-s', 'ticketType' => 'interaction', 'channel' => 'worklog', 'client' => 'client-1']],
			$this->queries
		);

		$this->queries = [];
		$worklog = $service->getWorklog(entityType: 'request', entityId: 'req-1', params: []);
		$this->assertSame([0, 1, 1, 'PT0S'], [$worklog['total'], $worklog['page'], $worklog['pages'], $worklog['totalDuration']]);
		$this->assertSame('req-1', $this->queries[0]['parentTicket']);

		$this->queries = [];
		$service->getWorklog(entityType: 'lead', entityId: 'l-1', params: []);
		$this->assertSame([['register' => 'reg-1', 'schema' => 'ticket-s', 'ticketType' => 'interaction', 'channel' => 'worklog']], $this->queries);
	}//end testWorklogPagesAndTotals()
}//end class
