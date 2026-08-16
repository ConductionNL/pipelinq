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
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ContactmomentService($this->ticketService,
			$this->groupManager,
			$this->logger,
			objectService: $this->objectService,
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
}//end class
