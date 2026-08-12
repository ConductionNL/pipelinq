<?php

/**
 * Unit tests for ProjectCreationListener.
 *
 * Asserts the listener dispatches a new project to the ledger, records the
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
 * In-memory ObjectService capturing saveObject() calls.
 */
class CreationFakeObjectService {
	/**
	 * Captured saved objects keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $saved = [];

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
		return (array)$object;
	}//end saveObject()
}//end class

/**
 * Tests for ProjectCreationListener.
 */
class ProjectCreationListenerTest extends TestCase {
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

		return new ProjectCreationListener(
			schemaMapService: $schemaMap,
			ledgerService: $ledger,
			notifier: ($notify ?? $this->createMock(LedgerSyncNotifier::class)),
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end listener()

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
		$this->assertCount(0, $objects->saved);
	}//end testIgnoresNonProjectSchema()

	/**
	 * An unconfigured integration no-ops without persisting.
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
		$this->assertCount(0, $objects->saved);
	}//end testNoopWhenUnconfigured()

	/**
	 * A successful dispatch marks the project synced.
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
		$listener = $this->listener($schemaMap, $ledger, $objects);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-project', ['uuid' => 'proj-1', 'name' => 'P'])));

		$this->assertArrayHasKey('proj-1', $objects->saved);
		$this->assertSame('synced', $objects->saved['proj-1']['ledgerSyncStatus']);
		$this->assertArrayHasKey('ledgerSyncedAt', $objects->saved['proj-1']);
	}//end testSuccessfulDispatchMarksSynced()

	/**
	 * A failed dispatch marks the project failed and notifies admins.
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
		$listener = $this->listener($schemaMap, $ledger, $objects, $notify);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-project', ['uuid' => 'proj-1', 'name' => 'P'])));

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

		$this->assertCount(0, $objects->saved);
	}//end testIdempotentForAlreadySyncedProject()
}//end class
