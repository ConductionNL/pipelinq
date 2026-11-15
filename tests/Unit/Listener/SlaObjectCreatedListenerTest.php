<?php

/**
 * Unit tests for SlaObjectCreatedListener.
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
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Listener\DeferredWorkGuard;
use OCA\Pipelinq\Listener\SlaObjectCreatedListener;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Queueing SLA initialisation for a new request or complaint ticket, and the
 * deferred pass that resolves the policy and stores the SLA envelope.
 *
 * @covers \OCA\Pipelinq\Listener\SlaObjectCreatedListener
 * @uses   \OCA\Pipelinq\Listener\DeferredWorkGuard
 *
 * @spec openspec/specs/sla-management/spec.md
 */
class SlaObjectCreatedListenerTest extends TestCase {

	private const ENTRY = ['handler' => 'sla-object-created', 'uuid' => 'ticket-1', 'schema' => 'schema-ticket', 'type' => 'request'];

	/**
	 * @var SlaEngineService&MockObject
	 */
	private SlaEngineService&MockObject $engine;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Deferral double recording what is queued.
	 *
	 * @var RecordingDeferralService
	 */
	private RecordingDeferralService $deferral;

	/**
	 * The object service fake the container hands out.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The configured register.
	 *
	 * @var string
	 */
	private string $register = 'register-1';

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		DeferredWorkGuard::reset();
		$this->engine = $this->getMockBuilder(className: SlaEngineService::class)
			->disableOriginalConstructor()
			->onlyMethods(methods: ['resolvePolicyForObject', 'initialiseStatus'])
			->getMock();
		$this->logger   = $this->createMock(originalClassName: LoggerInterface::class);
		$this->deferral = new RecordingDeferralService();
		$this->objects  = new class {
			/**
			 * What find() answers: an array (object data), null, or a Throwable to throw.
			 *
			 * @var mixed
			 */
			public mixed $stored = null;

			/**
			 * The find calls received.
			 *
			 * @var array<int,array<string,string>>
			 */
			public array $finds = [];

			/**
			 * The saves received.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saves = [];

			/**
			 * Whether saves throw.
			 *
			 * @var boolean
			 */
			public bool $failSave = false;

			/**
			 * Find an object.
			 *
			 * @param string $id       The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return ObjectEntity|null
			 */
			public function find(string $id, string $register, string $schema): ?ObjectEntity {
				$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema];
				if ($this->stored instanceof \Throwable) {
					throw $this->stored;
				}

				if ($this->stored === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject($this->stored);
				return $entity;
			}

			/**
			 * Save an object.
			 *
			 * @param array<string,mixed> $object   The data.
			 * @param array<int,string>   $extend   Extend.
			 * @param string              $register The register.
			 * @param string              $schema   The schema.
			 * @param string              $uuid     The uuid.
			 *
			 * @return void
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): void {
				if ($this->failSave === true) {
					throw new RuntimeException(message: 'locked');
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
			}
		};
	}//end setUp()

	/**
	 * Clear the in-flight guard.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		DeferredWorkGuard::reset();
	}//end tearDown()

	/**
	 * The listener under test.
	 *
	 * @return SlaObjectCreatedListener
	 */
	private function listener(): SlaObjectCreatedListener {
		$map = $this->createMock(originalClassName: SchemaMapService::class);
		$map->method('resolveEntityType')->willReturnCallback(
			static fn (string $schemaId): ?string => match ($schemaId) {
				'schema-ticket' => 'ticket',
				default => 'lead',
			}
		);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => $this->register,
				default => $default,
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);

		return new SlaObjectCreatedListener(
			engine: $this->engine,
			schemaMapService: $map,
			container: $container,
			appConfig: $config,
			deferral: $this->deferral,
			logger: $this->logger
		);
	}//end listener()

	/**
	 * A created-event for an object.
	 *
	 * @param array<string,mixed> $data   The object data.
	 * @param string              $uuid   The uuid.
	 * @param string              $schema The schema id.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function created(array $data, string $uuid = 'ticket-1', string $schema = 'schema-ticket'): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return new ObjectCreatedEvent($entity);
	}//end created()

	/**
	 * A new request or complaint ticket queues one deduplicated SLA initialisation.
	 *
	 * @return void
	 */
	public function testANewRequestOrComplaintTicketIsQueued(): void {
		$listener = $this->listener();
		$listener->handle(event: $this->created(data: ['ticketType' => 'request']));
		$listener->handle(event: $this->created(data: ['ticketType' => 'complaint'], uuid: 'ticket-2'));

		$this->assertSame(expected: [DeferredObjectListenerJob::class, DeferredObjectListenerJob::class], actual: $this->deferral->jobClasses);
		$this->assertSame(
			expected: [self::ENTRY, ['handler' => 'sla-object-created', 'uuid' => 'ticket-2', 'schema' => 'schema-ticket', 'type' => 'complaint']],
			actual: $this->deferral->entries
		);
		$this->assertSame(expected: ['sla-object-created|ticket-1', 'sla-object-created|ticket-2'], actual: $this->deferral->dedupeKeys);
	}//end testANewRequestOrComplaintTicketIsQueued()

	/**
	 * Other events, other entities, other ticket types, armed envelopes, a
	 * missing uuid and our own re-entrant write queue nothing.
	 *
	 * @return void
	 */
	public function testNothingIsQueuedWhenNoSlaApplies(): void {
		$listener = $this->listener();

		$listener->handle(event: new Event());
		$listener->handle(event: $this->created(data: ['ticketType' => 'request'], schema: 'schema-lead'));
		$listener->handle(event: $this->created(data: ['ticketType' => 'interaction']));
		$listener->handle(event: $this->created(data: ['ticketType' => 'request', 'slaStatus' => ['policyId' => 'p1']]));
		$listener->handle(event: $this->created(data: ['ticketType' => 'request'], uuid: ''));
		DeferredWorkGuard::enter(key: DeferredWorkGuard::key(handler: SlaObjectCreatedListener::HANDLER_KEY, uuid: 'ticket-1'));
		$listener->handle(event: $this->created(data: ['ticketType' => 'request']));

		$this->assertSame(expected: [], actual: $this->deferral->entries);
	}//end testNothingIsQueuedWhenNoSlaApplies()

	/**
	 * An empty policy id does not count as an armed envelope.
	 *
	 * @return void
	 */
	public function testAnEnvelopeWithoutPolicyIsStillQueued(): void {
		$this->listener()->handle(event: $this->created(data: ['ticketType' => 'request', 'slaStatus' => ['policyId' => '']]));

		$this->assertSame(expected: [self::ENTRY], actual: $this->deferral->entries);
	}//end testAnEnvelopeWithoutPolicyIsStillQueued()

	/**
	 * A failing deferral is logged, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingDeferralIsLogged(): void {
		$deferral = new class extends RecordingDeferralService {
			/**
			 * Refuse.
			 *
			 * @param string              $jobClass  The job.
			 * @param array<string,mixed> $entry     The entry.
			 * @param integer             $chunkSize The chunk size.
			 * @param string|null         $dedupeKey The key.
			 *
			 * @return void
			 */
			public function defer(string $jobClass, array $entry, int $chunkSize = self::DEFAULT_CHUNK_SIZE, ?string $dedupeKey = null): void {
				throw new RuntimeException(message: 'queue full');
			}
		};
		$this->deferral = $deferral;
		$this->logger->expects($this->once())->method('warning')
			->with('SlaObjectCreatedListener: SLA init could not be queued (non-blocking)', ['error' => 'queue full']);

		$this->listener()->handle(event: $this->created(data: ['ticketType' => 'request']));
	}//end testAFailingDeferralIsLogged()

	/**
	 * The deferred pass resolves the policy from the ticket's metadata and stores the envelope.
	 *
	 * @return void
	 */
	public function testTheDeferredPassStoresTheSlaEnvelope(): void {
		$this->objects->stored = ['ticketType' => 'request', 'customerTier' => 'gold', 'client' => 'org-1', 'contractId' => 'c-9'];
		$policy = ['id' => 'policy-request'];
		$this->engine->expects($this->once())->method('resolvePolicyForObject')
			->with('request', 'ticket-1', ['tier' => 'gold', 'organisationId' => 'org-1', 'contractId' => 'c-9'])
			->willReturn($policy);
		$this->engine->expects($this->once())->method('initialiseStatus')
			->with($policy, $this->isInstanceOf(className: \DateTimeImmutable::class))
			->willReturn(['policyId' => 'policy-request', 'targets' => []]);

		$this->listener()->runDeferredWork(entry: self::ENTRY);

		$this->assertSame(expected: [['id' => 'ticket-1', 'register' => 'register-1', 'schema' => 'schema-ticket']], actual: $this->objects->finds);
		$this->assertSame(
			expected: [[
				'object' => [
					'id' => 'ticket-1',
					'ticketType' => 'request',
					'customerTier' => 'gold',
					'client' => 'org-1',
					'contractId' => 'c-9',
					'slaStatus' => ['policyId' => 'policy-request', 'targets' => []],
				],
				'register' => 'register-1',
				'schema' => 'schema-ticket',
				'uuid' => 'ticket-1',
			]],
			actual: $this->objects->saves
		);
	}//end testTheDeferredPassStoresTheSlaEnvelope()

	/**
	 * Without a matching policy nothing is stored; the explicit tier wins over the customer tier.
	 *
	 * @return void
	 */
	public function testNoPolicyMeansNoEnvelope(): void {
		$this->objects->stored = ['slaTier' => 'silver', 'customerTier' => 'gold', 'organisationId' => 'org-2'];
		$this->engine->method('resolvePolicyForObject')
			->with('request', 'ticket-1', ['tier' => 'silver', 'organisationId' => 'org-2', 'contractId' => ''])
			->willReturn(null);
		$this->engine->expects($this->never())->method('initialiseStatus');
		$this->logger->expects($this->once())->method('debug')
			->with('SlaObjectCreatedListener: no matching policy', ['type' => 'request', 'tier' => 'silver']);

		$this->listener()->runDeferredWork(entry: self::ENTRY);

		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testNoPolicyMeansNoEnvelope()

	/**
	 * An incomplete entry, a missing register, a vanished or failing object and
	 * an envelope armed in the meantime all end the pass without a policy lookup.
	 *
	 * @return void
	 */
	public function testTheDeferredPassStopsEarly(): void {
		$this->engine->expects($this->never())->method('resolvePolicyForObject');
		$listener = $this->listener();

		$listener->runDeferredWork(entry: ['uuid' => 'ticket-1', 'schema' => 'schema-ticket']);
		$this->assertSame(expected: [], actual: $this->objects->finds);

		$this->register = '';
		$listener->runDeferredWork(entry: self::ENTRY);
		$this->assertSame(expected: [], actual: $this->objects->finds);

		$this->register = 'register-1';
		$this->objects->stored = null;
		$listener->runDeferredWork(entry: self::ENTRY);

		$this->objects->stored = ['slaStatus' => ['policyId' => 'armed']];
		$listener->runDeferredWork(entry: self::ENTRY);

		$this->objects->stored = new RuntimeException(message: 'db gone');
		$this->logger->expects($this->once())->method('warning')
			->with('SlaObjectCreatedListener: re-read failed (non-blocking)', ['error' => 'db gone', 'uuid' => 'ticket-1']);
		$listener->runDeferredWork(entry: self::ENTRY);

		$this->assertCount(expectedCount: 3, haystack: $this->objects->finds);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testTheDeferredPassStopsEarly()

	/**
	 * A failing save is logged, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLogged(): void {
		$this->objects->stored   = ['ticketType' => 'request'];
		$this->objects->failSave = true;
		$this->engine->method('resolvePolicyForObject')->willReturn(['id' => 'p']);
		$this->engine->method('initialiseStatus')->willReturn(['policyId' => 'p']);
		$this->logger->expects($this->once())->method('warning')
			->with('SlaObjectCreatedListener: persist failed (non-blocking)', ['error' => 'locked', 'uuid' => 'ticket-1']);

		$this->listener()->runDeferredWork(entry: self::ENTRY);
	}//end testAFailingSaveIsLogged()
}//end class
