<?php

/**
 * NotesController: creating a note, the failure answers, and the scoped
 * existence check that guards every note action.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\NotesController;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\NoteEventService;
use OCA\Pipelinq\Service\NotesService;
use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * @covers \OCA\Pipelinq\Controller\NotesController
 * @uses   \OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck
 */
class NotesControllerCoverageTest extends TestCase {

	/**
	 * Notes service double.
	 *
	 * @var NotesService&MockObject
	 */
	private NotesService&MockObject $notes;

	/**
	 * Note event service double.
	 *
	 * @var NoteEventService&MockObject
	 */
	private NoteEventService&MockObject $events;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * What the object service's find() does: returns this, or throws it.
	 *
	 * @var mixed
	 */
	private mixed $found = ['ticketType' => 'request'];

	/**
	 * The scope of every find() call.
	 *
	 * @var array<int, array{id: string, register: string, schema: string}>
	 */
	private array $finds = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->notes = $this->createMock(NotesService::class);
		$this->events = $this->createMock(NoteEventService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * The controller for a signed-in, privileged caller unless told otherwise.
	 *
	 * @param array<string, string> $settings   The app settings (register and schema ids).
	 * @param string|null           $uid        The session user, or null for none.
	 * @param bool                  $privileged Whether that user holds CRM rights.
	 *
	 * @return NotesController
	 */
	private function controller(array $settings = ['register' => 'reg-1', 'client_schema' => 'schema-client'], ?string $uid = 'alice', bool $privileged = true): NotesController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn($settings);
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('getRegisterId')->willReturn('reg-1');
		$tickets->method('getSchemaId')->willReturn('schema-ticket');

		$objects = new class($this) {
			/**
			 * @param NotesControllerCoverageTest $test The test, which holds the answer.
			 */
			public function __construct(private NotesControllerCoverageTest $test) {
			}

			/**
			 * @param string      $id       The object id.
			 * @param string|null $register The register.
			 * @param string|null $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$register = $options?->register;
				$schema = $options?->schema;
				return $this->test->answerFind(id: $id, register: (string)$register, schema: (string)$schema);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		return new NotesController(
			$this->request,
			$this->notes,
			$this->events,
			new CrmPrivilegeCheck($session, $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $privileged])),
			$l10n,
			$this->logger,
			$container,
			$this->createConfiguredMock(IGroupManager::class, ['isAdmin' => true]),
			$settingsService,
			$tickets,
		);
	}//end controller()

	/**
	 * The object service's answer to one find(), recorded.
	 *
	 * @param string $id       The object id.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return mixed
	 */
	public function answerFind(string $id, string $register, string $schema): mixed {
		$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema];
		if ($this->found instanceof \Throwable) {
			throw $this->found;
		}

		return $this->found;
	}//end answerFind()

	/**
	 * A message is required: an empty or blank one adds nothing.
	 *
	 * @return void
	 */
	public function testCreateRefusesABlankMessage(): void {
		$this->request->method('getParam')->with('message', '')->willReturn("  \n ");
		$this->notes->expects($this->never())->method('addNote');

		$response = $this->controller()->create('pipelinq_client', 'c-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Message is required'], $response->getData());
	}//end testCreateRefusesABlankMessage()

	/**
	 * A note is added to the object it was asked for, and its events fire.
	 *
	 * @return void
	 */
	public function testCreateAddsTheNoteAndTriggersItsEvents(): void {
		$this->request->method('getParam')->with('message', '')->willReturn('Called back');
		$this->notes->expects($this->once())->method('addNote')
			->with(objectType: 'pipelinq_client', objectId: 'c-1', message: 'Called back')
			->willReturn(['id' => 7, 'message' => 'Called back']);
		$this->events->expects($this->once())->method('triggerNoteEvents')->with(objectType: 'pipelinq_client', objectId: 'c-1');

		$response = $this->controller()->create('pipelinq_client', 'c-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['note' => ['id' => 7, 'message' => 'Called back']], $response->getData());
		$this->assertSame([['id' => 'c-1', 'register' => 'reg-1', 'schema' => 'schema-client']], $this->finds);
	}//end testCreateAddsTheNoteAndTriggersItsEvents()

	/**
	 * A failing add answers 500 and fires no events.
	 *
	 * @return void
	 */
	public function testCreateAnswers500WhenTheNoteCannotBeAdded(): void {
		$this->request->method('getParam')->willReturn('Called back');
		$this->notes->method('addNote')->willThrowException(new RuntimeException('db down'));
		$this->events->expects($this->never())->method('triggerNoteEvents');
		$this->logger->expects($this->once())->method('error')->with('NotesController: create failed');

		$response = $this->controller()->create('pipelinq_client', 'c-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'An unexpected error occurred'], $response->getData());
	}//end testCreateAnswers500WhenTheNoteCannotBeAdded()

	/**
	 * An unknown object type is refused before any lookup.
	 *
	 * @return void
	 */
	public function testCreateRefusesAnUnknownType(): void {
		$response = $this->controller()->create('pipelinq_invoice', 'x');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([], $this->finds);
	}//end testCreateRefusesAnUnknownType()

	/**
	 * Without a session the caller is unauthenticated; without CRM rights forbidden.
	 *
	 * @return void
	 */
	public function testTheCrmRightIsCheckedFirst(): void {
		$this->notes->expects($this->never())->method('getNotes');

		$this->assertSame(401, $this->controller(uid: null)->list('pipelinq_client', 'c-1')->getStatus());
		$this->assertSame(403, $this->controller(privileged: false)->create('pipelinq_client', 'c-1')->getStatus());
		$this->assertSame(403, $this->controller(privileged: false)->deleteSingle(3)->getStatus());
		$this->assertSame([], $this->finds);
	}//end testTheCrmRightIsCheckedFirst()

	/**
	 * A failing read answers 500.
	 *
	 * @return void
	 */
	public function testListAnswers500WhenTheNotesCannotBeRead(): void {
		$this->notes->method('getNotes')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('NotesController: list failed');

		$response = $this->controller()->list('pipelinq_client', 'c-1');

		$this->assertSame(500, $response->getStatus());
	}//end testListAnswers500WhenTheNotesCannotBeRead()

	/**
	 * Unconfigured settings fail closed without asking OpenRegister.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaReadsAsNotFound(): void {
		$this->logger->expects($this->once())->method('warning')->with('NotesController: register or schema not configured', ['objectType' => 'pipelinq_contact']);

		$response = $this->controller()->list('pipelinq_contact', 'p-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Object not found'], $response->getData());
		$this->assertSame([], $this->finds);
	}//end testAnUnconfiguredSchemaReadsAsNotFound()

	/**
	 * An object OpenRegister does not find, or reports as missing, is not found, quietly.
	 *
	 * @return void
	 */
	public function testAMissingObjectReadsAsNotFound(): void {
		$this->logger->expects($this->never())->method('error');
		$this->notes->expects($this->never())->method('getNotes');

		$this->found = null;
		$this->assertSame(404, $this->controller()->list('pipelinq_client', 'c-1')->getStatus());

		$this->found = new DoesNotExistException('gone');
		$this->assertSame(404, $this->controller()->list('pipelinq_client', 'c-1')->getStatus());
	}//end testAMissingObjectReadsAsNotFound()

	/**
	 * An unexpected lookup failure fails closed, and is logged.
	 *
	 * @return void
	 */
	public function testAFailingLookupFailsClosedAndIsLogged(): void {
		$this->found = new RuntimeException('connection refused');
		$this->logger->expects($this->once())->method('error')
			->with('NotesController: objectExists check failed', ['objectId' => 'c-1', 'exception' => 'connection refused']);
		$this->notes->expects($this->never())->method('addNote');

		$this->assertSame(404, $this->controller()->create('pipelinq_client', 'c-1')->getStatus());
	}//end testAFailingLookupFailsClosedAndIsLogged()

	/**
	 * A request is a ticket of the request subtype; an array payload counts.
	 *
	 * @return void
	 */
	public function testARequestIsReadFromAnArrayTicketOfThatSubtype(): void {
		$this->notes->method('getNotes')->with(objectType: 'pipelinq_request', objectId: 'r-1')->willReturn([]);

		$this->found = ['ticketType' => 'request'];
		$this->assertSame(200, $this->controller()->list('pipelinq_request', 'r-1')->getStatus());
		$this->assertSame(['id' => 'r-1', 'register' => 'reg-1', 'schema' => 'schema-ticket'], $this->finds[0]);

		$this->found = ['ticketType' => 'complaint'];
		$this->assertSame(404, $this->controller()->list('pipelinq_request', 'r-1')->getStatus());
	}//end testARequestIsReadFromAnArrayTicketOfThatSubtype()

	/**
	 * A ticket whose payload cannot be read fails closed, with a warning.
	 *
	 * @return void
	 */
	public function testAnUnreadableTicketPayloadFailsClosed(): void {
		$this->found = 'not an object';
		$this->logger->expects($this->once())->method('warning')->with('NotesController: ticket payload unreadable', ['ticketType' => 'request']);

		$this->assertSame(404, $this->controller()->list('pipelinq_request', 'r-1')->getStatus());
	}//end testAnUnreadableTicketPayloadFailsClosed()

	/**
	 * Deleting every note of an object: done, or 500 when it fails.
	 *
	 * @return void
	 */
	public function testDeleteAllDeletesOrAnswers500(): void {
		$calls = 0;
		$this->notes->expects($this->exactly(2))->method('deleteAllNotes')->with(objectType: 'pipelinq_client', objectId: 'c-1')
			->willReturnCallback(
				static function () use (&$calls): void {
					$calls++;
					if ($calls === 2) {
						throw new RuntimeException('db down');
					}
				}
			);

		$this->assertSame(['success' => true], $this->controller()->deleteAll('pipelinq_client', 'c-1')->getData());
		$this->assertSame(500, $this->controller()->deleteAll('pipelinq_client', 'c-1')->getStatus());
	}//end testDeleteAllDeletesOrAnswers500()

	/**
	 * Deleting all notes of an object that is not in scope deletes nothing.
	 *
	 * @return void
	 */
	public function testDeleteAllOfAMissingObjectDeletesNothing(): void {
		$this->found = null;
		$this->notes->expects($this->never())->method('deleteAllNotes');

		$this->assertSame(404, $this->controller()->deleteAll('pipelinq_client', 'c-1')->getStatus());
		$this->assertSame(400, $this->controller()->deleteAll('pipelinq_invoice', 'c-1')->getStatus());
	}//end testDeleteAllOfAMissingObjectDeletesNothing()
}//end class
