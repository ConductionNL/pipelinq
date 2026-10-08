<?php

/**
 * Unit tests for the SLA listeners' handling of tickets.
 *
 * Since unify-ticket-supertype the ticket schema resolves to the entity type
 * `ticket`, and both SLA listeners filtered on the retired `request` /
 * `complaint` types, so a ticket never got an SLA envelope on create and never
 * paused or resumed on a status change (pipelinq#2051).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Listener\SlaObjectCreatedListener;
use OCA\Pipelinq\Listener\SlaObjectUpdatedListener;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests that a ticket's SLA clock pauses, resumes and initialises.
 */
class SlaObjectUpdatedListenerTest extends TestCase {

	/**
	 * The ticket as the stubbed ObjectService stores it.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = [];

	/**
	 * Payloads handed to ObjectService::saveObject(), in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The seeded request policy, pausing on the ticket's awaiting_customer.
	 *
	 * @var array<string, mixed>
	 */
	private const POLICY = [
		'id' => 'policy-request',
		'appliesTo' => 'request',
		'pauseConditions' => ['awaiting_customer'],
		'holidayCalendar' => 'none',
	];

	/**
	 * A container whose ObjectService reads and writes $this->stored.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$test = $this;
		$objectService = new class($test) {
			/**
			 * Constructor.
			 *
			 * @param SlaObjectUpdatedListenerTest $test The owning test.
			 */
			public function __construct(private SlaObjectUpdatedListenerTest $test) {
			}

			/**
			 * Return the stored ticket.
			 *
			 * @param string $id Uuid.
			 * @param string $register Register.
			 * @param string $schema Schema.
			 *
			 * @return ObjectEntity The ticket.
			 */
			public function find(string $id, string $register, string $schema): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setSchema($schema);
				$entity->setObject($this->test->getStored());
				return $entity;
			}

			/**
			 * Record and store a save.
			 *
			 * @param array<string, mixed> $object Payload.
			 * @param array<int, string> $extend Extend.
			 * @param string $register Register.
			 * @param string $schema Schema.
			 * @param string $uuid Uuid.
			 *
			 * @return void
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): void {
				$this->test->recordSave($object);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		return $container;
	}//end container()

	/**
	 * Accessor for the anonymous ObjectService.
	 *
	 * @return array<string, mixed> The stored ticket.
	 */
	public function getStored(): array {
		return $this->stored;
	}//end getStored()

	/**
	 * Record a save and make it the stored state.
	 *
	 * @param array<string, mixed> $object The saved payload.
	 *
	 * @return void
	 */
	public function recordSave(array $object): void {
		$this->saved[] = $object;
		$this->stored = $object;
	}//end recordSave()

	/**
	 * The schema map, resolving the ticket schema the way production does.
	 *
	 * @return SchemaMapService The schema map.
	 */
	private function schemaMap(): SchemaMapService {
		$map = $this->createMock(SchemaMapService::class);
		$map->method('resolveEntityType')->willReturnCallback(
			static fn (string $schemaId): ?string => ($schemaId === 'schema-ticket' ? 'ticket' : null)
		);
		return $map;
	}//end schemaMap()

	/**
	 * App config that answers the register and falls back to defaults.
	 *
	 * @return IAppConfig The config.
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register' ? 'register-1' : $default)
		);
		return $config;
	}//end appConfig()

	/**
	 * The real engine timer maths, with policy loading and escalation stubbed.
	 *
	 * @return SlaEngineService&MockObject The engine.
	 */
	private function engine(): SlaEngineService {
		$engine = $this->getMockBuilder(SlaEngineService::class)
			->disableOriginalConstructor()
			->onlyMethods(['loadActivePolicies', 'executeEscalations', 'resolvePolicyForObject'])
			->getMock();
		$engine->method('loadActivePolicies')->willReturn([self::POLICY]);
		$engine->method('executeEscalations')->willReturn(['level' => 0, 'eventIds' => []]);
		return $engine;
	}//end engine()

	/**
	 * Build a ticket entity.
	 *
	 * @param array<string, mixed> $data The ticket payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function ticket(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ticket-1');
		$entity->setSchema('schema-ticket');
		$entity->setObject($data);
		return $entity;
	}//end ticket()

	/**
	 * Move the stored ticket to a status and run the update listener end to end.
	 *
	 * @param SlaObjectUpdatedListener $listener The listener.
	 * @param RecordingDeferralService $deferral The deferral recorder.
	 * @param string $status The new status.
	 *
	 * @return void
	 */
	private function moveTo(SlaObjectUpdatedListener $listener, RecordingDeferralService $deferral, string $status): void {
		$this->stored['status'] = $status;
		$listener->handle(new ObjectUpdatedEvent($this->ticket($this->stored), null));
		DeferredJobDrain::run($this, $deferral, $listener);
	}//end moveTo()

	/**
	 * A request ticket moving to awaiting_customer pauses its SLA clock, and
	 * moving on to in_progress resumes it.
	 *
	 * Before the fix the listener returned on the `ticket` entity type, so
	 * nothing was queued and the clock never paused.
	 *
	 * @return void
	 */
	public function testATicketPausesOnAwaitingCustomerAndResumesOnInProgress(): void {
		$this->stored = [
			'ticketType' => 'request',
			'status' => 'new',
			'slaStatus' => [
				'policyId' => 'policy-request',
				'pausedAt' => null,
				'targets' => [],
			],
		];

		$deferral = new RecordingDeferralService();
		$listener = new SlaObjectUpdatedListener(
			$this->engine(),
			$this->schemaMap(),
			$this->container(),
			$this->appConfig(),
			$deferral,
			$this->createMock(LoggerInterface::class),
		);

		$this->moveTo($listener, $deferral, 'awaiting_customer');
		$this->assertCount(1, $this->saved, 'the ticket update was not processed at all');
		$this->assertNotNull($this->stored['slaStatus']['pausedAt'], 'awaiting_customer did not pause the SLA clock');

		$this->moveTo($listener, $deferral, 'in_progress');
		$this->assertCount(2, $this->saved);
		$this->assertNull($this->stored['slaStatus']['pausedAt'], 'in_progress did not resume the SLA clock');
		$this->assertArrayHasKey('totalPausedMs', $this->stored['slaStatus']);
	}//end testATicketPausesOnAwaitingCustomerAndResumesOnInProgress()

	/**
	 * An interaction ticket carries no SLA, so the update listener ignores it.
	 *
	 * @return void
	 */
	public function testAnInteractionTicketIsNotTracked(): void {
		$this->stored = [
			'ticketType' => 'interaction',
			'status' => 'new',
			'slaStatus' => ['policyId' => 'policy-request', 'pausedAt' => null, 'targets' => []],
		];

		$deferral = new RecordingDeferralService();
		$listener = new SlaObjectUpdatedListener(
			$this->engine(),
			$this->schemaMap(),
			$this->container(),
			$this->appConfig(),
			$deferral,
			$this->createMock(LoggerInterface::class),
		);

		$this->moveTo($listener, $deferral, 'awaiting_customer');
		$this->assertSame([], $this->saved);
	}//end testAnInteractionTicketIsNotTracked()

	/**
	 * A newly created complaint ticket resolves its policy under the
	 * `complaint` SLA type, which is what the seeded policies' appliesTo names.
	 *
	 * @return void
	 */
	public function testACreatedTicketResolvesItsPolicyByTicketType(): void {
		$this->stored = ['ticketType' => 'complaint', 'status' => 'new'];

		$engine = $this->engine();
		$engine->expects($this->once())
			->method('resolvePolicyForObject')
			->with('complaint', 'ticket-1', $this->anything())
			->willReturn(null);

		$deferral = new RecordingDeferralService();
		$listener = new SlaObjectCreatedListener(
			$engine,
			$this->schemaMap(),
			$this->container(),
			$this->appConfig(),
			$deferral,
			$this->createMock(LoggerInterface::class),
		);

		$listener->handle(new ObjectCreatedEvent($this->ticket($this->stored)));
		DeferredJobDrain::run($this, $deferral, $listener);
	}//end testACreatedTicketResolvesItsPolicyByTicketType()
}//end class
