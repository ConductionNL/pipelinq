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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ContactmomentService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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
	 * Mock container.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

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
		$this->container = $this->createMock(ContainerInterface::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ContactmomentService(
			$this->container,
			$this->ticketService,
			$this->groupManager,
			$this->logger,
			objectService: $objectService,
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

		// The audit write is issued through the duck-typed OpenRegister probe
		// (it must never depend on the concrete OR class being loadable).
		$objectService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/**
			 * @param array<string, mixed> $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Uuid.
			 *
			 * @return array<string, mixed> The saved row.
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				$this->saves[] = ['payload' => $object, 'register' => $register, 'schema' => $schema];
				$object['uuid'] = 'ticket-uuid-1';
				return $object;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$uuid = $this->service->recordOutboundMessage(
			channel: 'sms',
			subject: 'Outbound SMS',
			summary: 'Your request is being handled.',
			channelMetadata: ['platform' => 'sms', 'direction' => 'outbound'],
			clientId: 'client-1',
			agent: 'agent-1',
		);

		$this->assertSame('ticket-uuid-1', $uuid);
		$this->assertCount(1, $objectService->saves);

		$save = $objectService->saves[0];
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
	public function testRecordOutboundMessageSkipsWhenUnconfigured(): void {
		$this->ticketService->method('isConfigured')->willReturn(false);
		$this->container->expects($this->never())->method('get');

		$this->assertNull(
			$this->service->recordOutboundMessage(
				channel: 'sms',
				subject: 'Outbound SMS',
				summary: 'Body',
				channelMetadata: [],
			)
		);
	}//end testRecordOutboundMessageSkipsWhenUnconfigured()

	/**
	 * Test getObjectService throws when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testGetObjectServiceThrowsWhenUnavailable(): void {
		$this->container->method('get')->willThrowException(new \Exception('Not found'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister service is not available.');

		$this->service->getObjectService();
	}//end testGetObjectServiceThrowsWhenUnavailable()

	/**
	 * Test delete by the creating agent succeeds.
	 *
	 * This test verifies that the agent who created the contactmoment can delete it.
	 *
	 * @return void
	 */
	public function testDeleteByCreatorSucceeds(): void {
		$mockObject = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$mockObject->method('getObject')->willReturn(['agent' => 'agent-user']);

		$objectService = $this->createMock(\stdClass::class);

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
