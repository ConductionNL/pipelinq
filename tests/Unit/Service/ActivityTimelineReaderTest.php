<?php

/**
 * Unit tests for ActivityTimelineReader's queries and linked-item reads.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ActivityTimelineReader;
use OCA\Pipelinq\Service\NotesService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Normalising query rows, and reading linked emails, events and notes.
 *
 * @covers \OCA\Pipelinq\Service\ActivityTimelineReader
 */
class ActivityTimelineReaderTest extends TestCase {

	/**
	 * The email link reader the reader resolves from the container.
	 */
	private const EMAIL_READER = 'OCA\\OpenRegister\\Contract\\EmailLinkReaderInterface';

	/**
	 * The calendar leaf the reader resolves from the container.
	 */
	private const CALENDAR_LEAF = 'OCA\\OpenRegister\\Service\\CalendarLinkService';

	/**
	 * Mock object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Mock notes service.
	 *
	 * @var NotesService&MockObject
	 */
	private NotesService&MockObject $notes;

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->notes = $this->createMock(NotesService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The reader under test.
	 *
	 * @return ActivityTimelineReader
	 */
	private function reader(): ActivityTimelineReader {
		return new ActivityTimelineReader(
			appConfig: $this->createMock(IAppConfig::class),
			objectService: $this->objects,
			notesService: $this->notes,
			container: $this->container,
			logger: $this->logger,
		);
	}//end reader()

	/**
	 * A query scopes to register and schema, caps the page, and normalises every row shape.
	 *
	 * @return void
	 */
	public function testAQueryNormalisesEveryRowShape(): void {
		$entity = new class () {
			/**
			 * The payload.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['title' => 'T'];
			}//end getObject()

			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return 'u-1';
			}//end getUuid()
		};
		$withId = new class () {
			/**
			 * A payload that already has an id.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['id' => 'own'];
			}//end getObject()

			/**
			 * Never used.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return 'ignored';
			}//end getUuid()
		};
		$plain = new class () {
			/**
			 * A public field.
			 *
			 * @var string
			 */
			public string $name = 'plain';
		};

		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'r', 'schema' => 's', 'client' => 'c-1'], 'limit' => 500])
			->willReturn([['id' => 'a'], 'scalar', $entity, $withId, $plain]);

		$this->assertSame(
			[['id' => 'a'], ['title' => 'T', 'id' => 'u-1'], ['id' => 'own'], ['name' => 'plain']],
			$this->reader()->query(registerId: 'r', schemaId: 's', filters: ['client' => 'c-1'])
		);
	}//end testAQueryNormalisesEveryRowShape()

	/**
	 * A failed query is logged with its schema and reads as empty.
	 *
	 * @return void
	 */
	public function testAFailedQueryIsLoggedAndEmpty(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->once())->method('error')
			->with('ActivityTimelineReader: failed to query schema', ['exception' => 'down', 'schemaId' => 's']);

		$this->assertSame([], $this->reader()->query(registerId: 'r', schemaId: 's', filters: []));
	}//end testAFailedQueryIsLoggedAndEmpty()

	/**
	 * Notes are read for the openregister object.
	 *
	 * @return void
	 */
	public function testNotesAreReadForTheObject(): void {
		$this->notes->expects($this->once())->method('getNotes')
			->with('openregister', 'e-1')
			->willReturn([['message' => 'hi']]);

		$this->assertSame([['message' => 'hi']], $this->reader()->notes(entityId: 'e-1'));
	}//end testNotesAreReadForTheObject()

	/**
	 * Without an email reader there are no linked emails.
	 *
	 * @return void
	 */
	public function testNoEmailReaderMeansNoEmails(): void {
		$this->container->method('has')->with(self::EMAIL_READER)->willReturn(false);
		$this->container->expects($this->never())->method('get');

		$this->assertSame([], $this->reader()->linkedEmails(entityId: 'e-1'));
	}//end testNoEmailReaderMeansNoEmails()

	/**
	 * Linked emails keep only array items, reindexed.
	 *
	 * @return void
	 */
	public function testLinkedEmailsKeepOnlyArrays(): void {
		$reader = new class () {
			/**
			 * Arguments of the last call.
			 *
			 * @var array<int, mixed>
			 */
			public array $args = [];

			/**
			 * A page of linked emails.
			 *
			 * @param string $objectUuid The object.
			 * @param int    $limit      The page size.
			 *
			 * @return array<string, mixed>
			 */
			public function getLinkedEmails(string $objectUuid, int $limit): array {
				$this->args = [$objectUuid, $limit];
				return ['items' => ['junk', ['subject' => 'A'], null, ['subject' => 'B']]];
			}//end getLinkedEmails()
		};
		$this->container->method('has')->willReturn(true);
		$this->container->method('get')->with(self::EMAIL_READER)->willReturn($reader);

		$this->assertSame([['subject' => 'A'], ['subject' => 'B']], $this->reader()->linkedEmails(entityId: 'e-1'));
		$this->assertSame(['e-1', 200], $reader->args);
	}//end testLinkedEmailsKeepOnlyArrays()

	/**
	 * A failing email reader is logged and reads as empty.
	 *
	 * @return void
	 */
	public function testAFailingEmailReaderIsLogged(): void {
		$this->container->method('has')->willReturn(true);
		$this->container->method('get')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('error')
			->with('ActivityTimelineReader: failed to read linked emails', ['exception' => 'gone']);

		$this->assertSame([], $this->reader()->linkedEmails(entityId: 'e-1'));
	}//end testAFailingEmailReaderIsLogged()

	/**
	 * A calendar leaf without the method, or answering a non-array, gives no events.
	 *
	 * @return void
	 */
	public function testAnUnusableCalendarLeafGivesNoEvents(): void {
		$this->container->method('has')->with(self::CALENDAR_LEAF)->willReturn(true);
		$this->container->method('get')->willReturnOnConsecutiveCalls(
			new class () {
			},
			new class () {
				/**
				 * Not a list.
				 *
				 * @param string $objectUuid The object.
				 *
				 * @return string
				 */
				public function getLinkedEvents(string $objectUuid): string {
					return 'nope';
				}//end getLinkedEvents()
			}
		);

		$this->assertSame([], $this->reader()->linkedEvents(entityId: 'e-1'));
		$this->assertSame([], $this->reader()->linkedEvents(entityId: 'e-1'));
	}//end testAnUnusableCalendarLeafGivesNoEvents()

	/**
	 * A failing calendar leaf is logged and reads as empty.
	 *
	 * @return void
	 */
	public function testAFailingCalendarLeafIsLogged(): void {
		$this->container->method('has')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')
			->with('ActivityTimelineReader: failed to read linked calendar events', ['exception' => 'boom']);

		$this->assertSame([], $this->reader()->linkedEvents(entityId: 'e-1'));
	}//end testAFailingCalendarLeafIsLogged()
}//end class
