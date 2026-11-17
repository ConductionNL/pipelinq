<?php

/**
 * Unit tests for TicketService.
 *
 * Scope note: this file is new (no prior `TicketServiceTest` existed at HEAD
 * — TicketService was previously exercised only indirectly, via mocks in
 * other services' test suites). `plq-mcp-provider-surgery` adds it to cover
 * the migrated `logContactmoment()` `#[McpTool]` method; it does not attempt
 * a full retrofit of TicketService's pre-existing surface (find/save/
 * sanitizeForSave), which is out of this change's scope.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Mcp\McpTransition;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Tests for TicketService::logContactmoment().
 *
 * Behaviour cases are ported from the deleted `PipelinqToolProviderTest`
 * (pipelinq.logContactmoment section) — behaviour is unchanged, only the
 * call site moved from `PipelinqToolProvider::invokeTool()` dispatch to a
 * direct `#[McpTool]`-attributed method call.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TicketServiceTest extends TestCase {

	/**
	 * Nil UUID used for every client fixture reference.
	 *
	 * @var string
	 */
	private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

	/**
	 * The OpenRegister object service double.
	 *
	 * Injected directly (ADR-083/ADR-084) — TicketService no longer resolves it
	 * through a DI container, so the container mock this file used to hold is
	 * gone with it.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The app config mock (register / ticket_schema resolution).
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return TicketService
	 */
	private function buildService(): TicketService {
		return new TicketService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->objectService,
			mcp: new McpAnswer(new NullLogger()),
		);
	}//end buildService()

	/**
	 * Stub IAppConfig::getValueString so isConfigured() is true.
	 *
	 * @return void
	 */
	private function stubConfigured(): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				$values = ['register' => 'reg-1', 'ticket_schema' => 'sch-ticket'];
				return $values[$key] ?? $default;
			}
		);
	}//end stubConfigured()

	/**
	 * The ObjectService double the service under test is built with.
	 *
	 * The declared return type is the CONTRACT, not OpenRegister's concrete
	 * `Service\ObjectService`: the double is a mock of
	 * `ObjectServiceInterface`, and a helper still declaring the class returns
	 * a TypeError before a single assertion runs (ADR-084).
	 *
	 * @return ObjectServiceInterface&MockObject
	 */
	private function mockObjectService(): ObjectServiceInterface {
		return $this->objectService;
	}//end mockObjectService()

	// =========================================================================
	// #[McpTool] attribute presence
	// =========================================================================

	/**
	 * logContactmoment() carries #[McpTool] with the expected name and description.
	 *
	 * @return void
	 */
	public function testLogContactmomentHasMcpToolAttribute(): void {
		$method = new ReflectionMethod(TicketService::class, 'logContactmoment');
		$attributes = $method->getAttributes(McpTool::class);

		$this->assertCount(expectedCount: 1, haystack: $attributes);

		/** @var McpTool $instance */
		$instance = $attributes[0]->newInstance();
		$this->assertSame(expected: 'logContactmoment', actual: $instance->name);
		$this->assertStringContainsString(needle: 'interaction', haystack: (string)$instance->description);
		$this->assertTrue(condition: $method->isPublic());

		// ADR-063 hints/scope: save() is called with no uuid — always creates
		// a new ticket, never destructive, never idempotent.
		$this->assertFalse(condition: $instance->readOnlyHint);
		$this->assertFalse(condition: $instance->destructiveHint);
		$this->assertFalse(condition: $instance->idempotentHint);
		$this->assertSame(expected: 'create', actual: $instance->scope);
	}//end testLogContactmomentHasMcpToolAttribute()

	// =========================================================================
	// logContactmoment()
	// =========================================================================

	/**
	 * logContactmoment() without a client returns invalid_arguments and writes nothing.
	 *
	 * @return void
	 */
	public function testLogContactmomentMissingClientReturnsInvalidArguments(): void {
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('saveObject');

		$result = $this->buildService()->logContactmoment(
			client: '',
			channel: 'telefoon',
			title: 'Booking change request'
		);

		$this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
	}//end testLogContactmomentMissingClientReturnsInvalidArguments()

	/**
	 * logContactmoment() without a channel returns invalid_arguments and writes nothing.
	 *
	 * @return void
	 */
	public function testLogContactmomentMissingChannelReturnsInvalidArguments(): void {
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('saveObject');

		$result = $this->buildService()->logContactmoment(
			client: self::NIL_UUID,
			channel: '',
			title: 'Booking change request'
		);

		$this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
	}//end testLogContactmomentMissingChannelReturnsInvalidArguments()

	/**
	 * logContactmoment() without a title returns invalid_arguments and writes nothing.
	 *
	 * @return void
	 */
	public function testLogContactmomentMissingTitleReturnsInvalidArguments(): void {
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('saveObject');

		$result = $this->buildService()->logContactmoment(
			client: self::NIL_UUID,
			channel: 'telefoon',
			title: ''
		);

		$this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
	}//end testLogContactmomentMissingTitleReturnsInvalidArguments()

	/**
	 * logContactmoment() returns not_configured when the ticket register/schema
	 * are unset, and writes nothing.
	 *
	 * @return void
	 */
	public function testLogContactmomentWithoutConfigReturnsNotConfigured(): void {
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('saveObject');

		$result = $this->buildService()->logContactmoment(
			client: self::NIL_UUID,
			channel: 'telefoon',
			title: 'Booking change request',
			direction: 'inbound'
		);

		$this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
	}//end testLogContactmomentWithoutConfigReturnsNotConfigured()

	/**
	 * logContactmoment() writes a ticket with ticketType=contactmoment via
	 * ObjectService->saveObject and returns the created ticket UUID.
	 *
	 * @return void
	 */
	public function testLogContactmomentWritesTicketAndReturnsUuid(): void {
		$this->stubConfigured();

		$captured = [];
		$objectService = $this->mockObjectService();
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$captured): ObjectEntityInterface {
				$captured = $object;

				// saveObject() returns an ENTITY, not a plain object (ADR-084);
				// the uuid is what TicketService reads back as the ticket id.
				$entity = new ObjectEntity();
				$entity->setUuid(self::NIL_UUID);
				$entity->setObject($object);

				return $entity;
			}
		);

		$result = $this->buildService()->logContactmoment(
			client: self::NIL_UUID,
			channel: 'telefoon',
			title: 'Booking change request',
			direction: 'inbound',
			outcome: 'handled',
			notes: 'Customer wants to reschedule.'
		);

		$this->assertArrayNotHasKey(key: 'error', array: $result);
		$this->assertSame(expected: TicketService::TYPE_CONTACTMOMENT, actual: $captured['ticketType']);
		$this->assertSame(expected: self::NIL_UUID, actual: $captured['client']);
		$this->assertSame(expected: 'telefoon', actual: $captured['channel']);
		$this->assertSame(expected: 'Booking change request', actual: $captured['title']);
		$this->assertSame(expected: 'handled', actual: $captured['outcome']);
		$this->assertSame(expected: 'Customer wants to reschedule.', actual: $captured['description']);
		$this->assertSame(expected: self::NIL_UUID, actual: $result['ticketId']);
	}//end testLogContactmomentWritesTicketAndReturnsUuid()

	/**
	 * logContactmoment() names each missing or invalid argument, in argument
	 * order, trims what it is given and writes nothing on a refusal.
	 *
	 * @return void
	 */
	public function testLogContactmomentRefusalMessagesInArgumentOrder(): void {
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('saveObject');
		$service = $this->buildService();

		$cases = [
			[['  ', 'telefoon', 'T', 'inbound'], 'Required argument client is missing.'],
			[[self::NIL_UUID, ' ', '', ''], 'Required argument channel is missing.'],
			[[self::NIL_UUID, 'telefoon', "\t", ''], 'Required argument title is missing.'],
			[[self::NIL_UUID, 'telefoon', 'T', ''], 'Required argument direction is missing.'],
			[[self::NIL_UUID, 'telefoon', 'T', '  '], 'Required argument direction is missing.'],
			[[self::NIL_UUID, 'telefoon', 'T', 'sideways'], 'Argument direction must be one of ' . implode(', ', TicketService::DIRECTIONS) . '.'],
			[[self::NIL_UUID, 'telefoon', 'T', 'Inbound'], 'Argument direction must be one of ' . implode(', ', TicketService::DIRECTIONS) . '.'],
		];
		foreach ($cases as [[$client, $channel, $title, $direction], $message]) {
			$result = $service->logContactmoment(client: $client, channel: $channel, title: $title, direction: $direction);
			$this->assertSame(['error' => ['code' => 'invalid_arguments', 'message' => $message]], $result, $message);
		}
	}//end testLogContactmomentRefusalMessagesInArgumentOrder()

	/**
	 * logContactmoment() trims client, channel, title and direction, leaves
	 * empty optional fields out and stamps occurredAt.
	 *
	 * @return void
	 */
	public function testLogContactmomentTrimsAndOmitsEmptyOptionals(): void {
		$this->stubConfigured();

		$captured = [];
		$objectService = $this->mockObjectService();
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$captured): ObjectEntityInterface {
				$captured = $object;
				$entity = new ObjectEntity();
				$entity->setUuid(self::NIL_UUID);
				$entity->setObject($object);
				return $entity;
			}
		);

		$result = $this->buildService()->logContactmoment(
			client: ' ' . self::NIL_UUID . ' ',
			channel: ' email ',
			title: ' Vraag ',
			direction: ' outbound ',
			outcome: '',
			notes: ''
		);

		$this->assertSame(['ticketId' => self::NIL_UUID], $result);
		$this->assertSame(self::NIL_UUID, $captured['client']);
		$this->assertSame('email', $captured['channel']);
		$this->assertSame('Vraag', $captured['title']);
		$this->assertSame('outbound', $captured['direction']);
		$this->assertArrayNotHasKey('outcome', $captured);
		$this->assertArrayNotHasKey('description', $captured);
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $captured['occurredAt']));
	}//end testLogContactmomentTrimsAndOmitsEmptyOptionals()

	/**
	 * logContactmoment() denied by RBAC maps to a forbidden envelope, not a success.
	 *
	 * @return void
	 */
	public function testLogContactmomentDeniedByRbacReturnsForbidden(): void {
		$this->stubConfigured();

		$objectService = $this->mockObjectService();
		$objectService->method('saveObject')->willThrowException(
			new \Exception('User is not authorized to create this object (permission denied)')
		);

		$result = $this->buildService()->logContactmoment(
			client: self::NIL_UUID,
			channel: 'telefoon',
			title: 'Booking change request',
			direction: 'inbound'
		);

		$this->assertSame(expected: 'forbidden', actual: $result['error']['code']);
	}//end testLogContactmomentDeniedByRbacReturnsForbidden()

	/**
	 * detectTypeInText maps the NL/EN words for a subtype onto its constant,
	 * singular and plural alike, and returns null for text naming no subtype.
	 *
	 * @return void
	 */
	public function testDetectTypeInTextRecognisesSubtypeVocabulary(): void {
		$service = $this->buildService();

		$this->assertSame(
			expected: TicketService::TYPE_REQUEST,
			actual: $service->detectTypeInText(text: 'How many requests are open?')
		);
		$this->assertSame(
			expected: TicketService::TYPE_REQUEST,
			actual: $service->detectTypeInText(text: 'Hoeveel verzoeken zijn er?')
		);
		$this->assertSame(
			expected: TicketService::TYPE_CONTACTMOMENT,
			actual: $service->detectTypeInText(text: 'How many contactmomenten were logged?')
		);
		$this->assertNull(actual: $service->detectTypeInText(text: 'How many leads are open?'));
		$this->assertNull(actual: $service->detectTypeInText(text: ''));
	}//end testDetectTypeInTextRecognisesSubtypeVocabulary()

	/**
	 * A melding is a ticket subtype like the others: it is in TYPES, so the
	 * per-type summaries count it, and its words name it.
	 *
	 * @return void
	 */
	public function testMeldingIsATicketSubtype(): void {
		$this->assertContains(needle: TicketService::TYPE_MELDING, haystack: TicketService::TYPES);
		$this->assertSame(
			expected: TicketService::TYPE_MELDING,
			actual: $this->buildService()->detectTypeInText(text: 'Hoeveel meldingen staan er open?')
		);
	}//end testMeldingIsATicketSubtype()
	/**
	 * A contactmoment written without a direction is refused, naming the field.
	 *
	 * OpenRegister validates `required` per schema and `ticket` holds three
	 * facets under one schema, so this guard is the write path's. It is the
	 * only thing standing between a request and a schema-level `required`
	 * that would refuse every request and complaint too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
	 */
	public function testAContactmomentWithoutADirectionIsRefused(): void {
		$this->stubConfigured();
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('saveObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('direction');

		$this->buildService()->save(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			payload: ['title' => 'Gebeld', 'channel' => 'telefoon']
		);
	}//end testAContactmomentWithoutADirectionIsRefused()

	/**
	 * A direction outside the enum is refused, naming what is allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
	 */
	public function testADirectionOutsideTheEnumIsRefused(): void {
		$this->stubConfigured();
		$this->mockObjectService();

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('inbound, outbound, internal');

		$this->buildService()->save(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			payload: ['title' => 'Gebeld', 'channel' => 'telefoon', 'direction' => 'sideways']
		);
	}//end testADirectionOutsideTheEnumIsRefused()

	/**
	 * A request is NOT refused for lacking a direction.
	 *
	 * The control for the two tests above: without it a guard that refused
	 * every ticket would look exactly like a guard that works.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-direction-is-a-first-class-field-req-cmd-001
	 */
	public function testARequestNeedsNoDirection(): void {
		$this->stubConfigured();

		$captured = [];
		$objectService = $this->mockObjectService();
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$captured): ObjectEntityInterface {
				$captured = $object;
				$entity = new ObjectEntity();
				$entity->setUuid(self::NIL_UUID);
				$entity->setObject($object);

				return $entity;
			}
		);

		$this->buildService()->save(
			ticketType: TicketService::TYPE_REQUEST,
			payload: ['title' => 'Aanvraag subsidie']
		);

		$this->assertSame(expected: TicketService::TYPE_REQUEST, actual: $captured['ticketType']);
		$this->assertArrayNotHasKey(key: 'direction', array: $captured);
	}//end testARequestNeedsNoDirection()
	/**
	 * A case set holding the same case twice is refused on every write path.
	 *
	 * Checked in the service rather than only in the filing acts, because an
	 * import, an API call and a flow write through here too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	public function testADuplicateCaseReferenceIsRefused(): void {
		$this->stubConfigured();
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('saveObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('already holds case-a');

		$this->buildService()->save(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			payload: [
				'title' => 'Gebeld',
				'channel' => 'telefoon',
				'direction' => 'inbound',
				'caseReferences' => ['case-a', 'case-a'],
			]
		);
	}//end testADuplicateCaseReferenceIsRefused()

	/**
	 * A primary outside the set is refused, naming it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-one-reference-is-the-primary-one-and-it-is-named-req-cms-002
	 */
	public function testAPrimaryOutsideTheSetIsRefused(): void {
		$this->stubConfigured();
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('saveObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('case-c is not one of the cases');

		$this->buildService()->save(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			payload: [
				'title' => 'Gebeld',
				'channel' => 'telefoon',
				'direction' => 'inbound',
				'caseReferences' => ['case-a', 'case-b'],
				'primaryCaseReference' => 'case-c',
			]
		);
	}//end testAPrimaryOutsideTheSetIsRefused()

	/**
	 * A primary that IS a member is accepted.
	 *
	 * The control for the two refusals above.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-one-reference-is-the-primary-one-and-it-is-named-req-cms-002
	 */
	public function testAPrimaryInsideTheSetIsAccepted(): void {
		$this->stubConfigured();

		$captured = [];
		$objectService = $this->mockObjectService();
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$captured): ObjectEntityInterface {
				$captured = $object;
				$entity = new ObjectEntity();
				$entity->setUuid(self::NIL_UUID);
				$entity->setObject($object);

				return $entity;
			}
		);

		$this->buildService()->save(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			payload: [
				'title' => 'Gebeld',
				'channel' => 'telefoon',
				'direction' => 'inbound',
				'caseReferences' => ['case-a', 'case-b'],
				'primaryCaseReference' => 'case-b',
			]
		);

		$this->assertSame(expected: ['case-a', 'case-b'], actual: $captured['caseReferences']);
		$this->assertSame(expected: 'case-b', actual: $captured['primaryCaseReference']);
	}//end testAPrimaryInsideTheSetIsAccepted()

	/**
	 * An order asked for reaches the store, with the type filter still forced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
	 */
	public function testFindByTypeHandsAnOrderToTheStore(): void {
		$this->stubConfigured();

		$captured = [];
		$this->mockObjectService()->method('findAll')->willReturnCallback(
			static function (array $query) use (&$captured): array {
				$captured = $query;
				return [];
			}
		);

		$this->buildService()->findByType(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			extraFilters: ['assignee' => 'agent-1'],
			limit: 5,
			sort: ['started_at' => 'desc'],
		);

		$this->assertSame(expected: ['started_at' => 'desc'], actual: $captured['sort']);
		$this->assertSame(expected: 5, actual: $captured['limit']);
		$this->assertSame(expected: TicketService::TYPE_CONTACTMOMENT, actual: $captured['filters']['ticketType']);
		$this->assertSame(expected: 'agent-1', actual: $captured['filters']['assignee']);
	}//end testFindByTypeHandsAnOrderToTheStore()

	/**
	 * Without an order the query is the one every earlier caller sent: no sort key.
	 *
	 * @return void
	 */
	public function testFindByTypeWithoutAnOrderSendsNoSortKey(): void {
		$this->stubConfigured();

		$captured = [];
		$this->mockObjectService()->method('findAll')->willReturnCallback(
			static function (array $query) use (&$captured): array {
				$captured = $query;
				return [];
			}
		);

		$this->buildService()->findByType(ticketType: TicketService::TYPE_REQUEST);

		$this->assertSame(expected: ['filters', 'limit'], actual: array_keys($captured));
	}//end testFindByTypeWithoutAnOrderSendsNoSortKey()
	// =========================================================================
	// createTicket / updateTicket / transitions (mcp-full-action-coverage)
	// =========================================================================

	/**
	 * Record what saveObject() receives and answer with a saved entity.
	 *
	 * @param array<string, mixed>|null $captured Receives the object and uuid.
	 *
	 * @return void
	 */
	private function captureSaves(?array &$captured): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use (&$captured): ObjectEntityInterface {
				$captured = ['object' => $object, 'uuid' => $options->uuid, 'schema' => $options->target->schema];
				$entity = new ObjectEntity();
				$entity->setUuid($options->uuid ?? self::NIL_UUID);
				$entity->setObject(['id' => $options->uuid ?? self::NIL_UUID] + $object);
				return $entity;
			}
		);
	}//end captureSaves()

	/**
	 * A request ticket is written through save() with the discriminator forced.
	 *
	 * @return void
	 */
	public function testCreateTicketWritesARequest(): void {
		$this->stubConfigured();
		$captured = null;
		$this->captureSaves(captured: $captured);

		$result = $this->buildService()->createTicket(ticketType: 'request', title: '  Parking permit  ', priority: 'high', description: '');

		$this->assertSame(expected: ['ticketId' => self::NIL_UUID], actual: $result);
		$this->assertSame(expected: 'request', actual: $captured['object']['ticketType']);
		$this->assertSame(expected: 'Parking permit', actual: $captured['object']['title']);
		$this->assertSame(expected: 'high', actual: $captured['object']['priority']);
		$this->assertArrayNotHasKey(key: 'description', array: $captured['object'], message: 'an empty optional is left out');
		$this->assertNull(actual: $captured['uuid']);
		$this->assertSame(expected: 'sch-ticket', actual: $captured['schema']);
	}//end testCreateTicketWritesARequest()

	/**
	 * A contactmoment, or any other subtype, is refused and pointed at logContactmoment.
	 *
	 * @return void
	 */
	public function testCreateTicketRefusesAContactmoment(): void {
		$this->stubConfigured();
		$this->objectService->expects($this->never())->method('saveObject');

		foreach (['contactmoment', 'interaction', 'melding', ''] as $type) {
			$result = $this->buildService()->createTicket(ticketType: $type, title: 'Call');
			$this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code'], message: $type);
			$this->assertStringContainsString(needle: 'logContactmoment', haystack: $result['error']['message']);
		}
	}//end testCreateTicketRefusesAContactmoment()

	/**
	 * A complaint without a title is invalid; an unconfigured app is not_configured.
	 *
	 * @return void
	 */
	public function testCreateTicketWithoutTitleOrConfiguration(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$noTitle = $this->buildService()->createTicket(ticketType: 'complaint', title: ' ');
		$unconfigured = $this->buildService()->createTicket(ticketType: 'complaint', title: 'Noise');

		$this->assertSame(expected: 'Required argument title is missing.', actual: $noTitle['error']['message']);
		$this->assertSame(expected: 'not_configured', actual: $unconfigured['error']['code']);
	}//end testCreateTicketWithoutTitleOrConfiguration()

	/**
	 * A create the RBAC refuses is forbidden.
	 *
	 * @return void
	 */
	public function testCreateTicketDeniedByRbacIsForbidden(): void {
		$this->stubConfigured();
		$this->objectService->method('saveObject')->willThrowException(new \Exception('permission denied'));

		$result = $this->buildService()->createTicket(ticketType: 'complaint', title: 'Noise');

		$this->assertSame(expected: 'forbidden', actual: $result['error']['code']);
	}//end testCreateTicketDeniedByRbacIsForbidden()

	/**
	 * An update merges onto the stored ticket and saves it under its own uuid and subtype.
	 *
	 * @return void
	 */
	public function testUpdateTicketMergesOntoTheStoredTicket(): void {
		$this->stubConfigured();
		$stored = new ObjectEntity();
		$stored->setObject(['ticketType' => 'complaint', 'title' => 'Noise', 'status' => 'new', 'priority' => 'normal']);
		$this->objectService->method('find')->willReturn($stored);
		$captured = null;
		$this->captureSaves(captured: $captured);

		$result = $this->buildService()->updateTicket(id: self::NIL_UUID, priority: 'urgent');

		$this->assertSame(expected: ['ticketId' => self::NIL_UUID], actual: $result);
		$this->assertSame(expected: self::NIL_UUID, actual: $captured['uuid']);
		$this->assertSame(expected: 'complaint', actual: $captured['object']['ticketType']);
		$this->assertSame(expected: 'urgent', actual: $captured['object']['priority']);
		$this->assertSame(expected: 'Noise', actual: $captured['object']['title']);
		$this->assertSame(expected: 'new', actual: $captured['object']['status'], message: 'the state is not touched');
	}//end testUpdateTicketMergesOntoTheStoredTicket()

	/**
	 * A status argument is refused before anything is read, naming the transition tools.
	 *
	 * @return void
	 */
	public function testUpdateTicketRefusesStatus(): void {
		$this->stubConfigured();
		$this->objectService->expects($this->never())->method('find');

		$result = $this->buildService()->updateTicket(id: self::NIL_UUID, title: 'x', status: 'closed');

		$this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
		$this->assertStringContainsString(needle: 'closeTicket', haystack: $result['error']['message']);
	}//end testUpdateTicketRefusesStatus()

	/**
	 * An update of a ticket the caller cannot find is not_found; one without fields is invalid.
	 *
	 * @return void
	 */
	public function testUpdateTicketNotFoundAndNoFields(): void {
		$this->stubConfigured();
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$missing = $this->buildService()->updateTicket(id: self::NIL_UUID, title: 'x');
		$empty = $this->buildService()->updateTicket(id: self::NIL_UUID);

		$this->assertSame(expected: ['error' => ['code' => 'not_found', 'message' => 'No ticket with that id.']], actual: $missing);
		$this->assertSame(expected: 'Give at least one field to change.', actual: $empty['error']['message']);
	}//end testUpdateTicketNotFoundAndNoFields()

	/**
	 * Each ticket transition tool drives its own declared transition.
	 *
	 * @return void
	 */
	public function testEachTransitionToolRunsItsDeclaredTransition(): void {
		$calls = [];
		$engine = new class($calls) {
			/**
			 * Constructor.
			 *
			 * @param list<string> $calls The recorded actions.
			 */
			public function __construct(private array &$calls) {
			}

			/**
			 * The engine's transition().
			 *
			 * @param string $objectId The ticket.
			 * @param string $action   The transition.
			 *
			 * @return ObjectEntity The moved ticket.
			 */
			public function transition(string $objectId, string $action): ObjectEntity {
				$this->calls[] = $objectId . ':' . $action;
				return new ObjectEntity();
			}
		};
		$engines = $this->createMock(originalClassName: ContainerInterface::class);
		$engines->method('get')->willReturn($engine);
		$service = new TicketService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->objectService,
			mcp: new McpAnswer(new NullLogger()),
			transitions: new McpTransition(container: $engines, mcp: new McpAnswer(new NullLogger()), logger: new NullLogger()),
		);

		$tools = ['startTicket' => 'start', 'completeTicket' => 'complete', 'convertTicket' => 'convert',
			'resolveTicket' => 'resolve', 'rejectTicket' => 'reject', 'closeTicket' => 'close'];
		foreach (array_keys($tools) as $tool) {
			$this->assertArrayHasKey(key: 'ticket', array: $service->$tool(id: self::NIL_UUID), message: $tool);
		}

		$this->assertSame(
			expected: array_map(static fn (string $action): string => self::NIL_UUID . ':' . $action, array_values($tools)),
			actual: $calls
		);
	}//end testEachTransitionToolRunsItsDeclaredTransition()

	/**
	 * The new ticket tools declare their scope, reach and hints; a final target is destructive.
	 *
	 * @return void
	 */
	public function testNewTicketToolsDeclareScopeReachAndHints(): void {
		$expected = [
			'createTicket' => ['create', false, false],
			'updateTicket' => ['update', false, true],
			'startTicket' => ['update', false, true],
			'completeTicket' => ['update', true, true],
			'convertTicket' => ['update', true, true],
			'resolveTicket' => ['update', true, true],
			'rejectTicket' => ['update', true, true],
			'closeTicket' => ['update', true, true],
		];
		foreach ($expected as $method => [$scope, $destructive, $idempotent]) {
			$attribute = (new ReflectionMethod(TicketService::class, $method))->getAttributes(McpTool::class)[0]->newInstance();
			$this->assertSame(expected: $method, actual: $attribute->name);
			$this->assertSame(expected: 'ticket', actual: $attribute->subject);
			$this->assertSame(expected: $scope, actual: $attribute->scope, message: $method);
			$this->assertSame(expected: 'instance', actual: $attribute->reach, message: $method);
			$this->assertSame(expected: $destructive, actual: $attribute->destructiveHint, message: $method);
			$this->assertSame(expected: $idempotent, actual: $attribute->idempotentHint, message: $method);
		}
	}//end testNewTicketToolsDeclareScopeReachAndHints()
}//end class
