<?php

/**
 * The edges of SurveyDispatchListener: failures it swallows and contacts it cannot read.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\Listener\SurveyDispatchListener;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SurveyDispatchService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A deleted event is ignored, a failing deferral is logged and swallowed,
 * and the deferred work falls back to only the ticket's client when the
 * contact is absent, gone or unreadable, and reads nothing without a register.
 *
 * @covers \OCA\Pipelinq\Listener\SurveyDispatchListener
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 */
class SurveyDispatchListenerEdgesTest extends TestCase {
	/**
	 * The dispatch service.
	 *
	 * @var SurveyDispatchService&MockObject
	 */
	private SurveyDispatchService&MockObject $dispatch;

	/**
	 * The schema map.
	 *
	 * @var SchemaMapService&MockObject
	 */
	private SchemaMapService&MockObject $schemaMap;

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The deferral service.
	 *
	 * @var ListenerDeferralService&MockObject
	 */
	private ListenerDeferralService&MockObject $deferral;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatch = $this->createMock(originalClassName: SurveyDispatchService::class);
		$this->schemaMap = $this->createMock(originalClassName: SchemaMapService::class);
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->deferral = $this->createMock(originalClassName: ListenerDeferralService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the listener.
	 *
	 * @param string $register The configured register.
	 *
	 * @return SurveyDispatchListener The listener.
	 */
	private function listener(string $register = 'reg-1'): SurveyDispatchListener {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => $register,
				'contact_schema' => 'sch-contact',
				default => $default,
			}
		);

		return new SurveyDispatchListener(
			dispatchService: $this->dispatch,
			schemaMapService: $this->schemaMap,
			objectService: $this->objects,
			appConfig: $config,
			deferral: $this->deferral,
			logger: $this->logger,
		);
	}//end listener()

	/**
	 * An object entity holding the given data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function object(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('t-1');
		$entity->setSchema('sch-ticket');
		$entity->setObject($data);
		return $entity;
	}//end object()

	/**
	 * Stub find() to return the ticket for `t-1` and the given result for the contact.
	 *
	 * @param ObjectEntity|\Throwable|null $contact The contact lookup result.
	 *
	 * @return void
	 */
	private function stubFind(ObjectEntity|\Throwable|null $contact): void {
		$ticket = $this->object(data: ['status' => 'closed', 'client' => 'cl-1', 'contact' => 'c-1']);
		$this->objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($ticket, $contact): ?ObjectEntity {
				if ($id === 't-1') {
					return $ticket;
				}

				if ($contact instanceof \Throwable) {
					throw $contact;
				}

				return $contact;
			}
		);
	}//end stubFind()

	/**
	 * Run the deferred work for the completed ticket.
	 *
	 * @return void
	 */
	private function runForTicket(): void {
		$this->listener()->runDeferredWork(
			entry: ['uuid' => 't-1', 'schema' => 'sch-ticket', 'status' => 'closed', 'entityType' => 'request']
		);
	}//end runForTicket()

	/**
	 * A deleted event is neither resolved nor deferred.
	 *
	 * @return void
	 */
	public function testADeletedEventIsIgnored(): void {
		$this->schemaMap->expects($this->never())->method('resolveEntityType');
		$this->deferral->expects($this->never())->method('defer');

		$this->listener()->handle(event: new ObjectDeletedEvent(object: $this->object(data: ['status' => 'closed'])));
	}//end testADeletedEventIsIgnored()

	/**
	 * A deferral that throws is logged and does not escape.
	 *
	 * @return void
	 */
	public function testAFailingDeferralIsLoggedAndSwallowed(): void {
		$this->schemaMap->method('resolveEntityType')->willReturn('ticket');
		$this->dispatch->method('matchingRules')->willReturn([['id' => 'rule-1']]);
		$this->deferral->method('defer')->willThrowException(new \RuntimeException('queue full'));
		$this->logger->expects($this->once())->method('warning')->with(
			'SurveyDispatchListener: the survey dispatch could not be queued; the ticket is unaffected',
			['exception' => 'queue full']
		);

		$this->listener()->handle(event: new ObjectCreatedEvent(object: $this->object(data: ['status' => 'closed', 'ticketType' => 'request'])));
	}//end testAFailingDeferralIsLoggedAndSwallowed()

	/**
	 * A ticket without a contact is dispatched with only its client.
	 *
	 * @return void
	 */
	public function testATicketWithoutAContactGetsOnlyItsClient(): void {
		$ticket = $this->object(data: ['status' => 'closed', 'client' => 'cl-1']);
		$this->objects->expects($this->once())->method('find')->willReturn($ticket);
		$this->dispatch->method('read')->willReturn([]);
		$this->dispatch->expects($this->once())->method('onInteractionCompleted')->with(
			'request',
			['status' => 'closed', 'client' => 'cl-1', 'id' => 't-1'],
			['client' => 'cl-1']
		);

		$this->runForTicket();
	}//end testATicketWithoutAContactGetsOnlyItsClient()

	/**
	 * A contact that is gone leaves only the client.
	 *
	 * @return void
	 */
	public function testAGoneContactLeavesOnlyTheClient(): void {
		$this->stubFind(contact: null);
		$this->dispatch->method('read')->willReturn([]);
		$this->dispatch->expects($this->once())->method('onInteractionCompleted')->with(
			'request',
			$this->anything(),
			['client' => 'cl-1']
		);

		$this->runForTicket();
	}//end testAGoneContactLeavesOnlyTheClient()

	/**
	 * A readable contact is merged over the client with its id.
	 *
	 * @return void
	 */
	public function testAReadableContactIsMergedWithItsId(): void {
		$contact = new ObjectEntity();
		$contact->setUuid('c-1');
		$contact->setObject(['email' => 'a@example.org']);
		$this->stubFind(contact: $contact);
		$this->dispatch->method('read')->willReturn([]);
		$this->dispatch->expects($this->once())->method('onInteractionCompleted')->with(
			'request',
			$this->anything(),
			['client' => 'cl-1', 'id' => 'c-1', 'email' => 'a@example.org']
		);

		$this->runForTicket();
	}//end testAReadableContactIsMergedWithItsId()

	/**
	 * An unreadable contact is logged with its uuid and leaves only the client.
	 *
	 * @return void
	 */
	public function testAnUnreadableContactIsLoggedAndLeavesOnlyTheClient(): void {
		$this->stubFind(contact: new \RuntimeException('denied'));
		$this->dispatch->method('read')->willReturn([]);
		$this->logger->expects($this->once())->method('warning')->with(
			'SurveyDispatchListener: an object could not be read for the survey dispatch',
			['uuid' => 'c-1', 'exception' => 'denied']
		);
		$this->dispatch->expects($this->once())->method('onInteractionCompleted')->with(
			'request',
			$this->anything(),
			['client' => 'cl-1']
		);

		$this->runForTicket();
	}//end testAnUnreadableContactIsLoggedAndLeavesOnlyTheClient()

	/**
	 * Without a register nothing is read and nothing dispatched.
	 *
	 * @return void
	 */
	public function testWithoutARegisterNothingIsRead(): void {
		$this->objects->expects($this->never())->method('find');
		$this->dispatch->expects($this->never())->method('onInteractionCompleted');

		$this->listener(register: '')->runDeferredWork(
			entry: ['uuid' => 't-1', 'schema' => 'sch-ticket', 'status' => 'closed', 'entityType' => 'request']
		);
	}//end testWithoutARegisterNothingIsRead()
}//end class
