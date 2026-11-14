<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\ContactmomentService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Error paths of the contact moment audit write and the permission-checked delete.
 *
 * @covers \OCA\Pipelinq\Service\ContactmomentService
 */
class ContactmomentServiceCoverageTest extends TestCase {

	/**
	 * The injected OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Mock unified ticket resolver.
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService $ticketService;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var ContactmomentService
	 */
	private ContactmomentService $service;

	/**
	 * Set up a configured ticket surface.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->ticketService->method('isConfigured')->willReturn(true);
		$this->ticketService->method('getRegisterId')->willReturn('reg-1');
		$this->ticketService->method('getSchemaId')->willReturn('ticket-1');
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$this->service = new ContactmomentService(
			ticketService: $this->ticketService,
			groupManager: $this->groupManager,
			logger: $this->logger,
			objectService: $this->objectService,
			l10n: $l10n,
		);
	}//end setUp()

	/**
	 * A stored ticket entity carrying the given payload.
	 *
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('cm-1');
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * A failed save is logged with the channel and answers null, never throws.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLoggedAndAnswersNull(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willThrowException(new RuntimeException('database down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'ContactmomentService: contact moment save failed',
				['channel' => 'chat', 'exception' => 'database down']
			);

		$this->assertNull(
			$this->service->recordOutboundMessage(
				channel: 'chat',
				subject: 'WhatsApp',
				summary: 'Body',
				channelMetadata: ['platform' => 'whatsapp'],
			)
		);
	}//end testAFailedSaveIsLoggedAndAnswersNull()

	/**
	 * A save that returns an entity without a uuid answers null, not ''.
	 *
	 * @return void
	 */
	public function testASavedEntityWithoutUuidAnswersNull(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn(new ObjectEntity());

		$this->assertNull(
			$this->service->recordInboundFromUnknownNumber(
				platform: 'sms',
				phone: '+31600000000',
				body: 'Hoi',
				messageId: 'm-1',
			)
		);
	}//end testASavedEntityWithoutUuidAnswersNull()

	/**
	 * Deleting an id that does not exist raises DoesNotExistException and deletes nothing.
	 *
	 * @return void
	 */
	public function testDeletingAMissingContactmomentThrows(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with('cm-404', [], false, 'reg-1', 'ticket-1')
			->willReturn(null);
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->expectException(DoesNotExistException::class);
		$this->expectExceptionMessage('Contactmoment not found: cm-404');

		$this->service->delete(id: 'cm-404', currentUserId: 'agent-1');
	}//end testDeletingAMissingContactmomentThrows()

	/**
	 * A ticket of another subtype is reported as not found, so it can never be deleted here.
	 *
	 * @return void
	 */
	public function testDeletingAnotherTicketTypeIsNotFound(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['ticketType' => 'complaint', 'createdBy' => 'agent-1'])
		);
		$this->groupManager->expects($this->never())->method('isAdmin');
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->expectException(DoesNotExistException::class);
		$this->expectExceptionMessage('Contactmoment not found: cm-1');

		$this->service->delete(id: 'cm-1', currentUserId: 'agent-1');
	}//end testDeletingAnotherTicketTypeIsNotFound()

	/**
	 * A ticket without a ticketType is likewise not a contactmoment.
	 *
	 * @return void
	 */
	public function testDeletingATicketWithoutTypeIsNotFound(): void {
		$this->objectService->method('find')->willReturn($this->entity(['createdBy' => 'agent-1']));
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->expectException(DoesNotExistException::class);

		$this->service->delete(id: 'cm-1', currentUserId: 'agent-1');
	}//end testDeletingATicketWithoutTypeIsNotFound()

	/**
	 * Expect the scoped delete of the given uuid exactly once.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return void
	 */
	private function expectScopedDelete(string $uuid): void {
		$this->objectService->expects($this->once())
			->method('setRegister')
			->with('reg-1')
			->willReturnSelf();
		$this->objectService->expects($this->once())
			->method('setSchema')
			->with('ticket-1')
			->willReturnSelf();
		$this->objectService->expects($this->once())
			->method('deleteObject')
			->with($uuid);
	}//end expectScopedDelete()

	/**
	 * The creating agent may delete their own contactmoment; the delete is scoped and logged.
	 *
	 * @return void
	 */
	public function testTheCreatorMayDelete(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['ticketType' => TicketService::TYPE_CONTACTMOMENT, 'createdBy' => 'agent-1', 'assignee' => 'someone-else'])
		);
		$this->groupManager->expects($this->once())->method('isAdmin')->with('agent-1')->willReturn(false);
		$this->expectScopedDelete('cm-1');
		$this->logger->expects($this->once())
			->method('info')
			->with('Contactmoment deleted', ['id' => 'cm-1', 'userId' => 'agent-1']);

		$this->assertTrue($this->service->delete(id: 'cm-1', currentUserId: 'agent-1'));
	}//end testTheCreatorMayDelete()

	/**
	 * An admin may delete a contactmoment someone else created.
	 *
	 * @return void
	 */
	public function testAnAdminMayDeleteAnyContactmoment(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['ticketType' => TicketService::TYPE_CONTACTMOMENT, 'createdBy' => 'agent-1'])
		);
		$this->groupManager->expects($this->once())->method('isAdmin')->with('admin')->willReturn(true);
		$this->expectScopedDelete('cm-7');

		$this->assertTrue($this->service->delete(id: 'cm-7', currentUserId: 'admin'));
	}//end testAnAdminMayDeleteAnyContactmoment()

	/**
	 * Someone who neither created it nor is admin is refused, even when named as assignee.
	 *
	 * @return void
	 */
	public function testANonCreatorNonAdminIsRefused(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['ticketType' => TicketService::TYPE_CONTACTMOMENT, 'createdBy' => 'agent-1', 'assignee' => 'intruder'])
		);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Only the creating agent or an admin can delete this contactmoment.');

		$this->service->delete(id: 'cm-1', currentUserId: 'intruder');
	}//end testANonCreatorNonAdminIsRefused()

	/**
	 * An empty createdBy never matches an empty user id: that is not ownership.
	 *
	 * @return void
	 */
	public function testAnEmptyCreatorDoesNotMatchAnEmptyUser(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['ticketType' => TicketService::TYPE_CONTACTMOMENT])
		);
		$this->groupManager->method('isAdmin')->with('')->willReturn(false);
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->expectException(NotPermittedException::class);

		$this->service->delete(id: 'cm-1', currentUserId: '');
	}//end testAnEmptyCreatorDoesNotMatchAnEmptyUser()

	/**
	 * Delete refuses before reaching OpenRegister when the ticket surface is unconfigured.
	 *
	 * @return void
	 */
	public function testDeleteThrowsWhenUnconfigured(): void {
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(false);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('find');
		$service = new ContactmomentService(
			ticketService: $tickets,
			groupManager: $this->groupManager,
			logger: $this->logger,
			objectService: $objects,
			l10n: $this->createMock(IL10N::class),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Contactmoment register or schema not configured.');

		$service->delete(id: 'cm-1', currentUserId: 'agent-1');
	}//end testDeleteThrowsWhenUnconfigured()
}//end class
