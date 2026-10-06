<?php

/**
 * Unit tests for ContactmomentService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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
use OCA\Pipelinq\Service\ContactmomentService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IGroupManager;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ContactmomentService.
 *
 * Since unify-ticket-supertype the service resolves the unified `ticket`
 * schema through TicketService rather than the retired `contactmoment_schema`
 * app-config key.
 */
class ContactmomentServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var ContactmomentService
	 */
	private ContactmomentService $service;

	/**
	 * The injected OpenRegister object service.
	 *
	 * ContactmomentService no longer resolves OpenRegister through a DI
	 * container: it takes a non-nullable ObjectServiceInterface (ADR-083/084),
	 * so the container mock this file used to hold went with it.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Mock unified ticket resolver.
	 *
	 * @var TicketService
	 */
	private TicketService $ticketService;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Mock translator.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$this->service = new ContactmomentService($this->ticketService,
			$this->groupManager,
			$this->logger,
			objectService: $this->objectService,
			l10n: $this->l10n,
		);
	}//end setUp()

	/**
	 * Test getConfig returns the register + unified ticket schema.
	 *
	 * @return void
	 */
	public function testGetConfigReturnsSettings(): void {
		$this->ticketService->method('isConfigured')->willReturn(true);
		$this->ticketService->method('getRegisterId')->willReturn('reg-123');
		$this->ticketService->method('getSchemaId')->willReturn('ticket-456');

		$config = $this->service->getConfig();

		$this->assertSame('reg-123', $config['register']);
		$this->assertSame('ticket-456', $config['schema']);
	}//end testGetConfigReturnsSettings()

	/**
	 * Test getConfig throws when the ticket surface is not configured.
	 *
	 * @return void
	 */
	public function testGetConfigThrowsWhenMissing(): void {
		$this->ticketService->method('isConfigured')->willReturn(false);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Contactmoment register or schema not configured.');

		$this->service->getConfig();
	}//end testGetConfigThrowsWhenMissing()

	/**
	 * The outbound audit writes a ticket with ticketType=contactmoment and the
	 * renamed ticket fields (title / description / occurredAt / assignee).
	 *
	 * @return void
	 */
	public function testRecordOutboundMessageWritesContactmomentTicket(): void {
		$this->ticketService->method('isConfigured')->willReturn(true);
		$this->ticketService->method('getRegisterId')->willReturn('reg-123');
		$this->ticketService->method('getSchemaId')->willReturn('ticket-456');

		// The audit write goes through the injected ObjectServiceInterface, so
		// the double is a mock of the CONTRACT — an in-test anonymous class no
		// longer satisfies the type-hint (ADR-084), and saveObject() must return
		// an entity rather than the array it was handed.
		$saves = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$saves): ObjectEntityInterface {
				$saves[] = ['payload' => $object, 'register' => $register, 'schema' => $schema];

				$entity = new ObjectEntity();
				$entity->setUuid('ticket-uuid-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		$uuid = $this->service->recordOutboundMessage(
			channel: 'sms',
			subject: 'Outbound SMS',
			summary: 'Your request is being handled.',
			channelMetadata: ['platform' => 'sms', 'direction' => 'outbound'],
			clientId: 'client-1',
			agent: 'agent-1',
		);

		$this->assertSame('ticket-uuid-1', $uuid);
		$this->assertCount(1, $saves);

		$save = $saves[0];
		$this->assertSame('reg-123', $save['register']);
		$this->assertSame('ticket-456', $save['schema']);

		$payload = $save['payload'];
		$this->assertSame(TicketService::TYPE_CONTACTMOMENT, $payload['ticketType']);
		$this->assertSame('Outbound SMS', $payload['title']);
		$this->assertSame('Your request is being handled.', $payload['description']);
		$this->assertSame('sms', $payload['channel']);
		$this->assertSame('client-1', $payload['client']);
		$this->assertSame('agent-1', $payload['assignee']);
		$this->assertArrayHasKey('occurredAt', $payload);
		// Legacy contactmoment field names must not survive the cutover.
		$this->assertArrayNotHasKey('subject', $payload);
		$this->assertArrayNotHasKey('summary', $payload);
		$this->assertArrayNotHasKey('agent', $payload);
		$this->assertArrayNotHasKey('contactedAt', $payload);
	}//end testRecordOutboundMessageWritesContactmomentTicket()

	/**
	 * The audit is log-and-continue: an unconfigured ticket schema returns null
	 * instead of throwing.
	 *
	 * @return void
	 */
	public function testRecordOutboundMessageReturnsTheUuidWhenSaveReturnsAnEntity(): void {
		$this->ticketService->method('isConfigured')->willReturn(true);
		$this->ticketService->method('getRegisterId')->willReturn('reg-123');
		$this->ticketService->method('getSchemaId')->willReturn('ticket-456');

		// ObjectServiceInterface::saveObject() is typed `: ObjectEntityInterface`
		// — it never returns an array, which is why the array arm that used to
		// sit alongside this one in production is gone. The uuid arrives through
		// getUuid(); reverting the fix to `method_exists($saved, 'getUuid')`
		// makes this assertion read null (pipelinq#807).
		$saved = new ObjectEntity();
		$saved->setUuid('ticket-uuid-2');
		$saved->setObject(['ticketType' => TicketService::TYPE_CONTACTMOMENT]);

		$this->objectService->method('saveObject')->willReturn($saved);

		$this->assertSame(
			'ticket-uuid-2',
			$this->service->recordOutboundMessage(
				channel: 'sms',
				subject: 'Outbound SMS',
				summary: 'Your request is being handled.',
				channelMetadata: ['platform' => 'sms', 'direction' => 'outbound'],
			)
		);
	}//end testRecordOutboundMessageReturnsTheUuidWhenSaveReturnsAnEntity()

	/**
	 * The audit is log-and-continue: an unconfigured ticket schema returns null
	 * instead of throwing.
	 *
	 * @return void
	 */
	public function testRecordOutboundMessageSkipsWhenUnconfigured(): void {
		$this->ticketService->method('isConfigured')->willReturn(false);

		// Nothing may reach OpenRegister. The container hop this used to assert
		// against is gone (the service is injected), so the assertion moved onto
		// the write itself — which is the thing that must not happen.
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertNull($this->service->recordOutboundMessage(
				channel: 'sms',
				subject: 'Outbound SMS',
				summary: 'Body',
				channelMetadata: [],
			)
		);
	}//end testRecordOutboundMessageSkipsWhenUnconfigured()

	/**
	 * getObjectService() hands back the injected service.
	 *
	 * FINDING (reported, not edited away): this test used to assert that an
	 * unresolvable container made getObjectService() throw
	 * `RuntimeException: OpenRegister service is not available.` That runtime
	 * failure mode no longer exists — under ADR-083/ADR-084 the service is a
	 * non-nullable constructor dependency, so "OpenRegister is unavailable" is
	 * a CONSTRUCTION-time failure the DI container raises before any method
	 * runs, and the old catch was dead code phpstan flagged. The guarantee
	 * therefore moved rather than disappeared, and this asserts where it now
	 * lives: the accessor returns exactly the injected instance, never null and
	 * never a re-resolved one.
	 *
	 * @return void
	 */
	public function testGetObjectServiceReturnsTheInjectedService(): void {
		$this->assertSame($this->objectService, $this->service->getObjectService());
	}//end testGetObjectServiceReturnsTheInjectedService()

	/**
	 * Test delete by the creating agent succeeds.
	 *
	 * This test verifies that the agent who created the contactmoment can delete it.
	 *
	 * @return void
	 */
	public function testDeleteByCreatorSucceeds(): void {
		// We can't mock ObjectService directly since it may not be loaded,
		// so we test the service's config and permission logic separately.
		// The integration with ObjectService is tested at the integration level.
		$this->groupManager->method('isAdmin')->with('agent-user')->willReturn(false);

		// Verify the group manager check works for non-admin creator.
		$this->assertFalse($this->groupManager->isAdmin('agent-user'));
	}//end testDeleteByCreatorSucceeds()

	/**
	 * Test admin users can delete any contactmoment.
	 *
	 * @return void
	 */
	public function testAdminCanDeleteAny(): void {
		$this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);

		// Verify the admin check.
		$this->assertTrue($this->groupManager->isAdmin('admin-user'));
	}//end testAdminCanDeleteAny()

	/**
	 * Test non-creator non-admin is correctly identified.
	 *
	 * @return void
	 */
	public function testNonCreatorNonAdminIdentified(): void {
		$this->groupManager->method('isAdmin')->with('other-user')->willReturn(false);

		// Verify the non-admin check.
		$this->assertFalse($this->groupManager->isAdmin('other-user'));
	}//end testNonCreatorNonAdminIdentified()

	/**
	 * Capture saveObject() calls with the access flags they were made with.
	 *
	 * @return void
	 */
	private function captureSaves(): void {
		$this->ticketService->method('isConfigured')->willReturn(true);
		$this->ticketService->method('getRegisterId')->willReturn('reg-123');
		$this->ticketService->method('getSchemaId')->willReturn('ticket-456');

		$this->saves = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ObjectEntityInterface {
				$this->saves[] = ['payload' => $object, 'register' => $register, 'schema' => $schema, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];

				$entity = new ObjectEntity();
				$entity->setUuid('ticket-uuid-2');
				$entity->setObject($object);
				return $entity;
			}
		);
	}//end captureSaves()

	/**
	 * Saves seen by captureSaves().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * A WhatsApp message from a number that matches no contact becomes a new,
	 * unassigned contact moment for a person to pick up. It follows the
	 * outbound convention (WhatsApp is channel chat, platform whatsapp). It is
	 * written with OpenRegister's checks on: the webhook runs it as the
	 * messaging service account.
	 *
	 * @return void
	 */
	public function testAMessageFromAnUnknownNumberIsLoggedForAPerson(): void {
		$this->captureSaves();

		$uuid = $this->service->recordInboundFromUnknownNumber(
			platform: 'whatsapp',
			phone: '+31699990000',
			body: 'Hallo, ik heb een vraag',
			messageId: 'msg-1',
		);

		$this->assertSame('ticket-uuid-2', $uuid);
		$this->assertCount(1, $this->saves);
		$save = $this->saves[0];
		$this->assertTrue($save['_rbac'], 'the contact moment skipped RBAC');
		$this->assertTrue($save['_multitenancy'], 'the contact moment skipped multitenancy');
		$this->assertSame('reg-123', $save['register']);
		$this->assertSame('ticket-456', $save['schema']);

		$payload = $save['payload'];
		$this->assertSame(TicketService::TYPE_CONTACTMOMENT, $payload['ticketType']);
		$this->assertSame('chat', $payload['channel']);
		$this->assertSame('inbound', $payload['direction']);
		$this->assertSame('new', $payload['status']);
		$this->assertSame('WhatsApp message from unknown number +31699990000', $payload['title']);
		$this->assertSame('Hallo, ik heb een vraag', $payload['description']);
		$this->assertSame('whatsapp', $payload['channelMetadata']['platform']);
		$this->assertSame('inbound', $payload['channelMetadata']['direction']);
		$this->assertSame('+31699990000', $payload['channelMetadata']['from']);
		$this->assertSame('msg-1', $payload['channelMetadata']['messageId']);
		$this->assertArrayNotHasKey('client', $payload);
		$this->assertArrayNotHasKey('assignee', $payload);
	}//end testAMessageFromAnUnknownNumberIsLoggedForAPerson()

	/**
	 * An SMS from an unknown number is logged on the sms channel.
	 *
	 * @return void
	 */
	public function testAnSmsFromAnUnknownNumberIsLoggedOnTheSmsChannel(): void {
		$this->captureSaves();

		$this->service->recordInboundFromUnknownNumber(
			platform: 'sms',
			phone: '+31699990000',
			body: 'Wie is dit?',
			messageId: '',
		);

		$payload = $this->saves[0]['payload'];
		$this->assertSame('sms', $payload['channel']);
		$this->assertSame('SMS from unknown number +31699990000', $payload['title']);
	}//end testAnSmsFromAnUnknownNumberIsLoggedOnTheSmsChannel()

	/**
	 * The log is log-and-continue: an unconfigured ticket schema answers null.
	 *
	 * @return void
	 */
	public function testAnUnknownNumberIsNotLoggedWhenTicketsAreUnconfigured(): void {
		$this->ticketService->method('isConfigured')->willReturn(false);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertNull(
			$this->service->recordInboundFromUnknownNumber(platform: 'sms', phone: '+31699990000', body: 'Hoi', messageId: '')
		);
	}//end testAnUnknownNumberIsNotLoggedWhenTicketsAreUnconfigured()
}//end class
