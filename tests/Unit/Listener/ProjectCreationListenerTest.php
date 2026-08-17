<?php

/**
 * Unit tests for ProjectCreationListener.
 *
 * Asserts the listener does NO ledger work on the request (ADR-078) but queues
 * it, and that the queued work dispatches to the ledger, records the
 * synced/failed outcome on the persisted object, is idempotent for an
 * already-synced project, ignores non-project schemas, and no-ops when the
 * integration is unconfigured.
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
use OCA\Pipelinq\Listener\ProjectCreationListener;
use OCA\Pipelinq\Service\LedgerSyncNotifier;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\ShillinqLedgerService;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService capturing saveObject() calls and serving find().
 */
class CreationFakeObjectService {
	/**
	 * Captured saved objects keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Objects readable by find(), keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $stored = [];

	/**
	 * Capture a saved object.
	 *
	 * @param array|object $object The object data.
	 * @param array $extend Unused.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string|null $uuid The object uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject($object, array $extend = [], string $register = '', string $schema = '', ?string $uuid = null): array {
		$this->saved[(string)$uuid] = (array)$object;
		$this->stored[(string)$uuid] = (array)$object;
		return (array)$object;
	}//end saveObject()

	/**
	 * Serve the deferred pass's re-read.
	 *
	 * @param string $id The object uuid.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity|null
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?ObjectEntity {
		if (isset($this->stored[$id]) === false) {
			return null;
		}

		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setSchema($schema);
		$entity->setObject($this->stored[$id]);
		return $entity;
	}//end find()
}//end class

/**
 * Tests for ProjectCreationListener.
 */
class ProjectCreationListenerTest extends TestCase {

	/**
	 * The deferral double the last-built listener was wired with.
	 *
	 * @var RecordingDeferralService|null
	 */
	private ?RecordingDeferralService $deferral = null;

	/**
	 * Clear the shared re-entrancy guard between tests.
	 *
	 * A key leaked by one test would make the next one silently skip its work
	 * and still report success.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\OCA\Pipelinq\Listener\DeferredWorkGuard::reset();
	}//end setUp()

	/**
	 * Build an ObjectEntity double for the given schema and data.
	 *
	 * @param string $schema The schema id.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The entity double.
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		// A REAL entity, not a mock: getSchema()/getUuid() are served by
		// Entity::__call in production and cannot be configured with onlyMethods()
		// against a faithful stub (pipelinq#807).
		$entity = new ObjectEntity();
		$entity->setUuid((string)($data['uuid'] ?? 'proj-1'));
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Build the listener with the given collaborators.
	 *
	 * @param SchemaMapService $schemaMap The schema map service.
	 * @param ShillinqLedgerService $ledger The ledger service.
	 * @param CreationFakeObjectService $objects The fake object service.
	 * @param LedgerSyncNotifier|null $notify The failure notifier (optional).
	 *
	 * @return ProjectCreationListener The listener under test.
	 */
	private function listener(
		SchemaMapService $schemaMap,
		ShillinqLedgerService $ledger,
		CreationFakeObjectService $objects,
		?LedgerSyncNotifier $notify = null,
	): ProjectCreationListener {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objects) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objects;
				}
				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				if ($key === 'register') {
					return 'reg-1';
				}
				if ($key === 'project_schema') {
					return 'schema-project';
				}
				return $default;
			}
		);

		$this->deferral = new RecordingDeferralService();

		return new ProjectCreationListener(
			schemaMapService: $schemaMap,
			ledgerService: $ledger,
			notifier: ($notify ?? $this->createMock(LedgerSyncNotifier::class)),
			container: $container,
			appConfig: $appConfig,
			deferral: $this->deferral,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * Run every entry the listener queued, as the background job would.
	 *
	 * @param ProjectCreationListener $listener The listener under test.
	 *
	 * @return void
	 */
	private function drain(ProjectCreationListener $listener): void {
		if ($this->deferral !== null) {
			DeferredJobDrain::run($this, $this->deferral, $listener);
		}
	}//end drain()

	/**
	 * POSITIVE CONTROL: a new project is queued, not dispatched inline.
	 *
	 * Every "nothing happened" assertion below is only meaningful because this
	 * one shows the listener CAN produce an entry.
	 *
	 * @return void
	 */
	public function testCreationIsQueuedAndNothingIsDispatchedOnTheRequest(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('project');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->method('shouldDispatch')->willReturn(true);
		$ledger->expects($this->never())->method('dispatchProjectEvent');

		$objects = new CreationFakeObjectService();
		$listener = $this->listener($schemaMap, $ledger, $objects);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-project', ['uuid' => 'proj-1', 'name' => 'P'])));

		$this->assertCount(1, $this->deferral->entries);
		$this->assertSame(ProjectCreationListener::HANDLER_KEY, $this->deferral->entries[0]['handler']);
		$this->assertSame('proj-1', $this->deferral->entries[0]['uuid']);
		$this->assertSame(
			\OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob::class,
			$this->deferral->jobClasses[0]
		);
		// The whole point: no write and no outbound dispatch on the request.
		$this->assertCount(0, $objects->saved);
	}//end testCreationIsQueuedAndNothingIsDispatchedOnTheRequest()

	/**
	 * A non-ObjectCreatedEvent is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresNonObjectCreatedEvent(): void {
		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->expects($this->never())->method('dispatchProjectEvent');

		$objects = new CreationFakeObjectService();
		$listener = $this->listener($this->createMock(SchemaMapService::class), $ledger, $objects);

		$listener->handle(new class extends Event {});
		$this->assertCount(0, $this->deferral->entries);
		$this->assertCount(0, $objects->saved);
	}//end testIgnoresNonObjectCreatedEvent()

	/**
	 * A non-project schema is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresNonProjectSchema(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('lead');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->expects($this->never())->method('dispatchProjectEvent');

		$objects = new CreationFakeObjectService();
		$listener = $this->listener($schemaMap, $ledger, $objects);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-lead', ['uuid' => 'lead-1'])));
		$this->assertCount(0, $this->deferral->entries);
		$this->assertCount(0, $objects->saved);
	}//end testIgnoresNonProjectSchema()

	/**
	 * An unconfigured integration no-ops without queueing or persisting.
	 *
	 * @return void
	 */
	public function testNoopWhenUnconfigured(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('project');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->method('shouldDispatch')->willReturn(false);
		$ledger->expects($this->never())->method('dispatchProjectEvent');

		$objects = new CreationFakeObjectService();
		$listener = $this->listener($schemaMap, $ledger, $objects);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-project', ['uuid' => 'proj-1'])));
		$this->assertCount(0, $this->deferral->entries);
		$this->assertCount(0, $objects->saved);
	}//end testNoopWhenUnconfigured()

	/**
	 * A successful deferred dispatch marks the project synced.
	 *
	 * @return void
	 */
	public function testSuccessfulDispatchMarksSynced(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('project');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->method('shouldDispatch')->willReturn(true);
		$ledger->method('dispatchProjectEvent')->willReturn(true);

		$objects = new CreationFakeObjectService();
		$objects->stored['proj-1'] = ['uuid' => 'proj-1', 'name' => 'P'];
		$listener = $this->listener($schemaMap, $ledger, $objects);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-project', ['uuid' => 'proj-1', 'name' => 'P'])));
		$this->drain($listener);

		$this->assertArrayHasKey('proj-1', $objects->saved);
		$this->assertSame('synced', $objects->saved['proj-1']['ledgerSyncStatus']);
		$this->assertArrayHasKey('ledgerSyncedAt', $objects->saved['proj-1']);
	}//end testSuccessfulDispatchMarksSynced()

	/**
	 * A failed deferred dispatch marks the project failed and notifies admins.
	 *
	 * @return void
	 */
	public function testFailedDispatchMarksFailedAndNotifies(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('project');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->method('shouldDispatch')->willReturn(true);
		$ledger->method('dispatchProjectEvent')->willReturn(false);

		$notify = $this->createMock(LedgerSyncNotifier::class);
		$notify->expects($this->once())->method('notifyFailure');

		$objects = new CreationFakeObjectService();
		$objects->stored['proj-1'] = ['uuid' => 'proj-1', 'name' => 'P'];
		$listener = $this->listener($schemaMap, $ledger, $objects, $notify);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-project', ['uuid' => 'proj-1', 'name' => 'P'])));
		$this->drain($listener);

		$this->assertSame('failed', $objects->saved['proj-1']['ledgerSyncStatus']);
	}//end testFailedDispatchMarksFailedAndNotifies()

	/**
	 * An already-synced project is not re-dispatched (idempotency).
	 *
	 * @return void
	 */
	public function testIdempotentForAlreadySyncedProject(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('project');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->method('shouldDispatch')->willReturn(true);
		$ledger->expects($this->never())->method('dispatchProjectEvent');

		$objects = new CreationFakeObjectService();
		$listener = $this->listener($schemaMap, $ledger, $objects);

		$entity = $this->entity('schema-project', ['uuid' => 'proj-1', 'ledgerSyncStatus' => 'synced']);
		$listener->handle(new ObjectCreatedEvent($entity));

		$this->assertCount(0, $this->deferral->entries);
		$this->assertCount(0, $objects->saved);
	}//end testIdempotentForAlreadySyncedProject()

	/**
	 * The project having been deleted before the job runs is a no-op, not an
	 * error (ADR-078 Rule 7 — at-least-once delivery reconciles against
	 * current state).
	 *
	 * @return void
	 */
	public function testDeletedProjectIsAStaleNoOp(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('project');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->method('shouldDispatch')->willReturn(true);
		$ledger->expects($this->never())->method('dispatchProjectEvent');

		// `stored` deliberately empty: find() returns null.
		$objects = new CreationFakeObjectService();
		$listener = $this->listener($schemaMap, $ledger, $objects);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-project', ['uuid' => 'proj-1'])));
		$this->assertCount(1, $this->deferral->entries);

		$this->drain($listener);
		$this->assertCount(0, $objects->saved);
	}//end testDeletedProjectIsAStaleNoOp()
}//end class
