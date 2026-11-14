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

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\Cti\EscalationQueues;
use OCA\Pipelinq\Service\CtiDispositionService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The CTI wrap-up: outcome validation, contactmoment write-back and follow-up task creation.
 *
 * @covers \OCA\Pipelinq\Service\CtiDispositionService
 * @uses   \OCA\Pipelinq\Service\Cti\EscalationQueues
 */
class CtiDispositionServiceCoverageTest extends TestCase {

	private const QUEUES = '[{"id":"vergunningen","label":"Vergunningen","groupId":"grp-vergunningen"},'
		. '{"id":"belastingen","label":"Belastingen","groupId":"grp-belastingen"}]';

	/**
	 * Mock container that serves the OpenRegister object service.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * Mock OpenRegister object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock unified ticket resolver.
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService $ticketService;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willReturn($this->objectService);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service with an app config answering the given values.
	 *
	 * @param array<string, string> $values Config values by key.
	 *
	 * @return CtiDispositionService The service.
	 */
	private function service(array $values = ['register' => 'reg-1', 'task_schema' => 'task-1']): CtiDispositionService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		return new CtiDispositionService(
			container: $this->container,
			appConfig: $config,
			ticketService: $this->ticketService,
			queues: new EscalationQueues(appConfig: $config),
			logger: $this->logger,
		);
	}//end service()

	/**
	 * A saved task entity with the given uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function savedEntity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		return $entity;
	}//end savedEntity()

	/**
	 * An outcome outside the enum is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnknownOutcomeIsRefused(): void {
		$this->ticketService->expects($this->never())->method('save');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown disposition outcome: maybe');

		$this->service()->processDisposition(interactionId: 'cm-1', subject: 'S', outcome: 'maybe', notes: '');
	}//end testAnUnknownOutcomeIsRefused()

	/**
	 * The wrap-up dialog gets every queue as id and label, without the group.
	 *
	 * @return void
	 */
	public function testQueueChoicesListIdAndLabel(): void {
		$this->assertSame(
			[
				['id' => 'vergunningen', 'label' => 'Vergunningen'],
				['id' => 'belastingen', 'label' => 'Belastingen'],
			],
			$this->service(['cti_escalation_queues' => self::QUEUES])->queueChoices()
		);
	}//end testQueueChoicesListIdAndLabel()

	/**
	 * An unknown escalation queue is refused before the contactmoment is touched.
	 *
	 * @return void
	 */
	public function testAnUnknownEscalationQueueIsRefused(): void {
		$this->ticketService->expects($this->never())->method('isConfigured');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown escalation queue: nope');

		$this->service(['cti_escalation_queues' => self::QUEUES])->processDisposition(
			interactionId: 'cm-1',
			subject: 'S',
			outcome: 'escalated',
			notes: '',
			queue: 'nope',
		);
	}//end testAnUnknownEscalationQueueIsRefused()

	/**
	 * Escalating without a chosen queue routes to the first queue's group.
	 *
	 * @return void
	 */
	public function testEscalatingWithoutAQueueUsesTheFirstQueue(): void {
		$this->ticketService->method('isConfigured')->willReturn(false);
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				[
					'type' => 'followUpTask',
					'subject' => 'Bouwvergunning',
					'description' => 'Bel terug',
					'status' => 'open',
					'assigneeGroupId' => 'grp-vergunningen',
					'interaction' => 'cm-1',
				],
				[],
				'reg-1',
				'task-1',
				null
			)
			->willReturn($this->savedEntity('task-9'));

		$result = $this->service(['cti_escalation_queues' => self::QUEUES, 'register' => 'reg-1', 'task_schema' => 'task-1'])
			->processDisposition(interactionId: 'cm-1', subject: 'Bouwvergunning', outcome: 'escalated', notes: 'Bel terug');

		$this->assertSame(['outcome' => 'escalated', 'interactionId' => 'cm-1', 'taskId' => 'task-9'], $result);
	}//end testEscalatingWithoutAQueueUsesTheFirstQueue()

	/**
	 * Escalating with no queues configured creates an unassigned task; empty notes are dropped.
	 *
	 * @return void
	 */
	public function testEscalatingWithNoQueuesCreatesAnUnassignedTask(): void {
		$this->ticketService->method('isConfigured')->willReturn(false);
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				['type' => 'followUpTask', 'subject' => 'S', 'status' => 'open', 'interaction' => 'cm-1'],
				[],
				'reg-1',
				'task-1',
				null
			)
			->willReturn($this->savedEntity('task-2'));

		$result = $this->service()->processDisposition(interactionId: 'cm-1', subject: 'S', outcome: 'escalated', notes: '');

		$this->assertSame('task-2', $result['taskId']);
	}//end testEscalatingWithNoQueuesCreatesAnUnassignedTask()

	/**
	 * The disposition is written back onto the contactmoment ticket.
	 *
	 * @return void
	 */
	public function testTheDispositionIsWrittenOntoTheContactmoment(): void {
		$this->ticketService->method('isConfigured')->willReturn(true);
		$this->ticketService->expects($this->once())
			->method('save')
			->with(
				TicketService::TYPE_CONTACTMOMENT,
				[
					'disposition_subject' => 'Vraag',
					'disposition_outcome' => 'resolved',
					'disposition_notes' => 'Opgelost',
				],
				'cm-1'
			)
			->willReturn(new \stdClass());
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logger->expects($this->never())->method('error');

		$result = $this->service()->processDisposition(interactionId: 'cm-1', subject: 'Vraag', outcome: 'resolved', notes: 'Opgelost');

		$this->assertSame(['outcome' => 'resolved', 'interactionId' => 'cm-1', 'taskId' => null], $result);
	}//end testTheDispositionIsWrittenOntoTheContactmoment()

	/**
	 * A failed contactmoment write is logged and does not stop the callback task.
	 *
	 * @return void
	 */
	public function testAFailedContactmomentWriteIsLoggedAndTheTaskStillCreated(): void {
		$this->ticketService->method('isConfigured')->willReturn(true);
		$this->ticketService->method('save')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())
			->method('error')
			->with('CTI disposition: contactmoment save failed', ['exception' => 'locked', 'id' => 'cm-1']);
		$saved = new ObjectEntity();
		$saved->setUuid('task-uuid');
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($saved);

		$result = $this->service()->processDisposition(interactionId: 'cm-1', subject: 'S', outcome: 'callback', notes: 'n');

		$this->assertSame('task-uuid', $result['taskId']);
	}//end testAFailedContactmomentWriteIsLoggedAndTheTaskStillCreated()

	/**
	 * A callback with no task register configured is skipped with a warning.
	 *
	 * @return void
	 */
	public function testACallbackWithoutTaskSchemaSkipsTheTask(): void {
		$this->ticketService->method('isConfigured')->willReturn(false);
		$this->container->expects($this->never())->method('get');
		$this->logger->expects($this->exactly(2))
			->method('warning')
			->with($this->logicalOr(
				'CTI disposition: ticket register/schema not configured.',
				'CTI disposition: task register/schema not configured -- skipping task creation.'
			));

		$result = $this->service(['register' => 'reg-1'])
			->processDisposition(interactionId: 'cm-1', subject: 'S', outcome: 'callback', notes: '');

		$this->assertNull($result['taskId']);
	}//end testACallbackWithoutTaskSchemaSkipsTheTask()

	/**
	 * A saved entity without a uuid yields no task id.
	 *
	 * @return void
	 */
	public function testASavedEntityWithoutUuidYieldsNoTaskId(): void {
		$this->ticketService->method('isConfigured')->willReturn(false);
		$this->objectService->expects($this->once())->method('saveObject')->willReturn(new ObjectEntity());

		$result = $this->service()->processDisposition(interactionId: 'cm-1', subject: 'S', outcome: 'callback', notes: '');

		$this->assertNull($result['taskId']);
	}//end testASavedEntityWithoutUuidYieldsNoTaskId()

	/**
	 * A saved array without id or uuid yields no task id.
	 *
	 * @return void
	 */
	public function testASavedArrayWithoutIdYieldsNoTaskId(): void {
		$this->ticketService->method('isConfigured')->willReturn(false);
		// The real ObjectService is typed to return an entity, so a mock cannot
		// answer an array; a plain double exercises the array arm.
		$arrayObjects = new class {
			/**
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * @return array<string, mixed>
			 */
			public function saveObject(): array {
				$this->calls++;
				return ['title' => 'no id here'];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($arrayObjects);
		$this->container = $container;

		$result = $this->service()->processDisposition(interactionId: 'cm-1', subject: 'S', outcome: 'callback', notes: '');

		$this->assertNull($result['taskId']);
		$this->assertSame(1, $arrayObjects->calls);
	}//end testASavedArrayWithoutIdYieldsNoTaskId()

	/**
	 * A failed task save is logged with the interaction and yields no task id.
	 *
	 * @return void
	 */
	public function testAFailedTaskSaveIsLogged(): void {
		$this->ticketService->method('isConfigured')->willReturn(false);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('schema invalid'));
		$this->logger->expects($this->once())
			->method('error')
			->with('CTI disposition: task save failed', ['exception' => 'schema invalid', 'interactionId' => 'cm-1']);

		$result = $this->service()->processDisposition(interactionId: 'cm-1', subject: 'S', outcome: 'callback', notes: '');

		$this->assertSame(['outcome' => 'callback', 'interactionId' => 'cm-1', 'taskId' => null], $result);
	}//end testAFailedTaskSaveIsLogged()
}//end class
