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
 * @spec openspec/changes/client-activity-card/specs/activity-timeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCA\Pipelinq\Service\NotesService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
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
	 * Build the service over fakes.
	 *
	 * @param array<int,array<string,mixed>> $notes The notes the notes service answers.
	 *
	 * @return ActivityTimelineService
	 */
	private function makeService(array $notes=[]): ActivityTimelineService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default='') => match ($key) {
				'register' => 'reg-1',
				'task_schema' => 'task-s',
				'contact_schema' => 'contact-s',
				'message_schema' => 'message-s',
				default => $default,
			}
		);

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('getSchemaId')->willReturn('ticket-s');

		$objects = $this->createMock(ObjectServiceInterface::class);
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

		$notesService = $this->createMock(NotesService::class);
		$notesService->method('getNotes')->willReturnCallback(
			static fn (string $type, string $id) => ($type === 'openregister' && $id === 'client-1') ? $notes : []
		);

		$fatma = $this->createMock(IUser::class);
		$fatma->method('getDisplayName')->willReturn('Fatma Yildiz');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid) => $uid === 'fatma' ? $fatma : null);

		return new ActivityTimelineService(
			appConfig: $config,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
			ticketService: $tickets,
			objectService: $objects,
			notesService: $notesService,
			userManager: $users,
		);
	}//end makeService()

	/**
	 * A client's notes are activities of kind note, newest first with the rest.
	 *
	 * @return void
	 */
	public function testClientNotesAreNoteActivities(): void {
		$this->rows['ticket-s'] = [
			['id' => 't-1', 'ticketType' => 'interaction', 'client' => 'client-1', 'title' => 'Called', 'occurredAt' => '2026-10-02T09:30:00+00:00', 'assignee' => 'fatma'],
		];
		$service = $this->makeService(
			notes: [
				['id' => 7, 'message' => '@pieter the street plan is under Documents.', 'authorId' => 'fatma', 'authorName' => 'Fatma Yildiz', 'timestamp' => '2026-10-04T10:40:00+00:00'],
			]
		);

		$result = $service->getTimeline(entityType: 'client', entityId: 'client-1', params: []);

		$this->assertSame(['note', 'interaction'], array_column($result['items'], 'type'));
		$note = $result['items'][0];
		$this->assertSame('7', $note['id']);
		$this->assertSame('@pieter the street plan is under Documents.', $note['title']);
		$this->assertSame('2026-10-04T10:40:00+00:00', $note['date']);
		$this->assertSame('fatma', $note['user']);
		$this->assertSame('Fatma Yildiz', $note['userName']);
	}//end testClientNotesAreNoteActivities()

	/**
	 * A contact moment names its handler by display name.
	 *
	 * @return void
	 */
	public function testAuthorsAreNamedByDisplayName(): void {
		$this->rows['ticket-s'] = [
			['id' => 't-1', 'ticketType' => 'interaction', 'client' => 'client-1', 'title' => 'Called', 'occurredAt' => '2026-10-02T09:30:00+00:00', 'assignee' => 'fatma'],
			['id' => 't-2', 'ticketType' => 'interaction', 'client' => 'client-1', 'title' => 'Mailed', 'occurredAt' => '2026-10-01T09:30:00+00:00', 'assignee' => 'gone'],
		];

		$result = $this->makeService()->getTimeline(entityType: 'client', entityId: 'client-1', params: []);

		$this->assertSame(['Fatma Yildiz', 'gone'], array_column($result['items'], 'userName'));
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
			['id' => 'm-1', 'contactId' => 'p-1', 'channel' => 'whatsapp', 'direction' => 'outbound', 'body' => 'Your appointment is on 8 October, 14:00.', 'deliveryStatus' => 'delivered', 'sentAt' => '2026-10-05T08:00:00+00:00'],
			['id' => 'm-2', 'contactId' => 'p-9', 'channel' => 'sms', 'body' => 'Not this client', 'sentAt' => '2026-10-06T08:00:00+00:00'],
		];

		$result = $this->makeService()->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => ['message']]);

		$this->assertSame(1, $result['total']);
		$message = $result['items'][0];
		$this->assertSame('message', $message['type']);
		$this->assertSame('m-1', $message['id']);
		$this->assertSame('Your appointment is on 8 October, 14:00.', $message['title']);
		$this->assertSame('2026-10-05T08:00:00+00:00', $message['date']);
		$this->assertSame('whatsapp', $message['metadata']['channel']);
		$this->assertSame('outbound', $message['metadata']['direction']);
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

		$this->assertSame(['m-1'], array_column($result['items'], 'id'));
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
			notes: [['id' => 7, 'message' => 'Note', 'authorId' => 'fatma', 'authorName' => 'Fatma Yildiz', 'timestamp' => '2026-10-04T10:40:00+00:00']]
		);

		$result = $service->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => ['note']]);

		$this->assertSame(['note'], array_column($result['items'], 'type'));
		$this->assertSame([], $this->queries);
	}//end testNotesChipReadsNotesAlone()
}//end class
