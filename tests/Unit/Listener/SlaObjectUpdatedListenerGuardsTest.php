<?php

/**
 * Unit tests for the guards and deferred pass of SlaObjectUpdatedListener.
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
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Listener\DeferredWorkGuard;
use OCA\Pipelinq\Listener\SlaObjectUpdatedListener;
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
 * Which ticket updates queue an SLA re-evaluation, and the deferred pass:
 * early stops, an unknown policy, resolution, escalation and persistence.
 *
 * @covers \OCA\Pipelinq\Listener\SlaObjectUpdatedListener
 * @uses   \OCA\Pipelinq\Listener\DeferredWorkGuard
 *
 * @spec openspec/specs/sla-management/spec.md
 */
class SlaObjectUpdatedListenerGuardsTest extends TestCase {

	private const ENTRY = ['handler' => 'sla-object-updated', 'uuid' => 'ticket-1', 'schema' => 'schema-ticket', 'type' => 'request'];

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
			->onlyMethods(
				methods: ['loadActivePolicies', 'policyIdentity', 'evaluateTargets', 'executeEscalations', 'markTargetMet', 'pauseTimer', 'resumeTimer']
			)
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
	 * Configured app values by key.
	 *
	 * @var array<string,string>
	 */
	private array $values = [];

	/**
	 * The listener under test.
	 *
	 * @return SlaObjectUpdatedListener
	 */
	private function listener(): SlaObjectUpdatedListener {
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
				default => $this->values[$key] ?? $default,
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);

		return new SlaObjectUpdatedListener(
			engine: $this->engine,
			schemaMapService: $map,
			container: $container,
			appConfig: $config,
			deferral: $this->deferral,
			logger: $this->logger
		);
	}//end listener()

	/**
	 * An updated-event for an object.
	 *
	 * @param array<string,mixed> $data   The object data.
	 * @param string              $uuid   The uuid.
	 * @param string              $schema The schema id.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function updated(array $data, string $uuid = 'ticket-1', string $schema = 'schema-ticket'): ObjectUpdatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return new ObjectUpdatedEvent($entity, null);
	}//end updated()

	/**
	 * Let the engine know one policy and pass targets through unchanged.
	 *
	 * @return void
	 */
	private function onePolicy(): void {
		$this->engine->method('loadActivePolicies')->willReturn([['id' => 'other'], ['id' => 'p1', 'pauseConditions' => ['awaiting_customer']]]);
		$this->engine->method('policyIdentity')->willReturnCallback(static fn (array $policy): string => $policy['id']);
		$this->engine->method('evaluateTargets')->willReturnCallback(static fn (array $targets): array => $targets);
	}//end onePolicy()

	/**
	 * An update of a tracked request or complaint ticket queues one re-evaluation.
	 *
	 * @return void
	 */
	public function testATrackedTicketUpdateIsQueued(): void {
		$this->listener()->handle(event: $this->updated(data: ['ticketType' => 'complaint', 'slaStatus' => ['policyId' => 'p1']]));

		$this->assertSame(expected: [DeferredObjectListenerJob::class], actual: $this->deferral->jobClasses);
		$this->assertSame(
			expected: [['handler' => 'sla-object-updated', 'uuid' => 'ticket-1', 'schema' => 'schema-ticket', 'type' => 'complaint']],
			actual: $this->deferral->entries
		);
		$this->assertSame(expected: ['sla-object-updated|ticket-1'], actual: $this->deferral->dedupeKeys);
	}//end testATrackedTicketUpdateIsQueued()

	/**
	 * Untracked updates and our own re-entrant write queue nothing.
	 *
	 * @return void
	 */
	public function testUntrackedUpdatesAreIgnored(): void {
		$listener = $this->listener();
		$armed    = ['ticketType' => 'request', 'slaStatus' => ['policyId' => 'p1']];

		$listener->handle(event: new Event());
		$listener->handle(event: $this->updated(data: $armed, schema: 'schema-lead'));
		$listener->handle(event: $this->updated(data: ['ticketType' => 'request']));
		$listener->handle(event: $this->updated(data: ['ticketType' => 'request', 'slaStatus' => ['policyId' => '']]));
		$listener->handle(event: $this->updated(data: $armed, uuid: ''));
		DeferredWorkGuard::enter(key: DeferredWorkGuard::key(handler: SlaObjectUpdatedListener::HANDLER_KEY, uuid: 'ticket-1'));
		$listener->handle(event: $this->updated(data: $armed));

		$this->assertSame(expected: [], actual: $this->deferral->entries);
	}//end testUntrackedUpdatesAreIgnored()

	/**
	 * A failing deferral is logged, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingDeferralIsLogged(): void {
		$this->deferral = new class extends RecordingDeferralService {
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
		$this->logger->expects($this->once())->method('warning')
			->with('SlaObjectUpdatedListener: SLA update could not be queued (non-blocking)', ['error' => 'queue full']);

		$this->listener()->handle(event: $this->updated(data: ['ticketType' => 'request', 'slaStatus' => ['policyId' => 'p1']]));
	}//end testAFailingDeferralIsLogged()

	/**
	 * Incomplete entries, a missing register, a vanished, failing or unarmed object end the pass.
	 *
	 * @return void
	 */
	public function testTheDeferredPassStopsEarly(): void {
		$this->engine->expects($this->never())->method('loadActivePolicies');
		$listener = $this->listener();

		$listener->runDeferredWork(entry: ['uuid' => 'ticket-1', 'schema' => 'schema-ticket']);
		$this->register = '';
		$listener->runDeferredWork(entry: self::ENTRY);
		$this->assertSame(expected: [], actual: $this->objects->finds);

		$this->register       = 'register-1';
		$this->objects->stored = null;
		$listener->runDeferredWork(entry: self::ENTRY);
		$this->objects->stored = ['slaStatus' => 'broken'];
		$listener->runDeferredWork(entry: self::ENTRY);
		$this->objects->stored = new RuntimeException(message: 'db gone');
		$this->logger->expects($this->once())->method('warning')
			->with('SlaObjectUpdatedListener: re-read failed (non-blocking)', ['error' => 'db gone', 'uuid' => 'ticket-1']);
		$listener->runDeferredWork(entry: self::ENTRY);

		$this->assertCount(expectedCount: 3, haystack: $this->objects->finds);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testTheDeferredPassStopsEarly()

	/**
	 * An envelope whose policy is no longer active is left alone.
	 *
	 * @return void
	 */
	public function testAnUnknownPolicyIsLeftAlone(): void {
		$this->onePolicy();
		$this->objects->stored = ['status' => 'new', 'slaStatus' => ['policyId' => 'gone']];
		$this->logger->expects($this->once())->method('debug')->with('SlaObjectUpdatedListener: policy not found', ['policyId' => 'gone']);

		$this->listener()->runDeferredWork(entry: self::ENTRY);

		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testAnUnknownPolicyIsLeftAlone()

	/**
	 * A configured resolved status marks the resolution target met; fired
	 * escalations raise the level and attach their breach events.
	 *
	 * @return void
	 */
	public function testResolutionAndEscalationAreStored(): void {
		$this->onePolicy();
		$this->values = ['sla_resolved_statuses' => 'done, archived'];
		$this->objects->stored = [
			'status' => 'done',
			'slaStatus' => [
				'policyId' => 'p1',
				'pausedAt' => null,
				'currentEscalationLevel' => 1,
				'targets' => ['resolution' => ['breachEventIds' => ['e1']]],
			],
		];
		$this->engine->expects($this->once())->method('markTargetMet')
			->with($this->anything(), 'resolution', $this->isInstanceOf(className: \DateTimeImmutable::class))
			->willReturnCallback(static fn (array $status): array => $status + ['resolvedMarked' => true]);
		$this->engine->expects($this->once())->method('executeEscalations')
			->with(['id' => 'p1', 'pauseConditions' => ['awaiting_customer']], 'request', 'ticket-1', $this->anything(), $this->anything(), 1)
			->willReturn(['level' => 2, 'eventIds' => ['e2']]);
		$this->engine->expects($this->never())->method('pauseTimer');

		$this->listener()->runDeferredWork(entry: self::ENTRY);

		$saved = $this->objects->saves[0]['object']['slaStatus'];
		$this->assertTrue(condition: $saved['resolvedMarked']);
		$this->assertSame(expected: 2, actual: $saved['currentEscalationLevel']);
		$this->assertSame(expected: ['e1', 'e2'], actual: $saved['targets']['resolution']['breachEventIds']);
		$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T/', string: $saved['lastEvaluatedAt']);
	}//end testResolutionAndEscalationAreStored()

	/**
	 * A status outside the default resolved list marks nothing; a paused clock fires no escalation.
	 *
	 * @return void
	 */
	public function testAPausedTicketFiresNoEscalation(): void {
		$this->onePolicy();
		$this->objects->stored = ['status' => 'awaiting_customer', 'slaStatus' => ['policyId' => 'p1', 'pausedAt' => null, 'targets' => []]];
		$this->engine->expects($this->once())->method('pauseTimer')
			->willReturnCallback(static fn (array $status): array => ['pausedAt' => '2026-11-13T00:00:00+00:00'] + $status);
		$this->engine->expects($this->never())->method('markTargetMet');
		$this->engine->expects($this->never())->method('executeEscalations');

		$this->listener()->runDeferredWork(entry: self::ENTRY);

		$this->assertSame(expected: '2026-11-13T00:00:00+00:00', actual: $this->objects->saves[0]['object']['slaStatus']['pausedAt']);
	}//end testAPausedTicketFiresNoEscalation()

	/**
	 * A failing save is logged, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLogged(): void {
		$this->onePolicy();
		$this->objects->stored   = ['status' => '', 'slaStatus' => ['policyId' => 'p1', 'targets' => []]];
		$this->objects->failSave = true;
		$this->engine->method('executeEscalations')->willReturn(['level' => 0, 'eventIds' => []]);
		$this->logger->expects($this->once())->method('warning')
			->with('SlaObjectUpdatedListener: persist failed (non-blocking)', ['error' => 'locked', 'uuid' => 'ticket-1']);

		$this->listener()->runDeferredWork(entry: self::ENTRY);
	}//end testAFailingSaveIsLogged()
}//end class
