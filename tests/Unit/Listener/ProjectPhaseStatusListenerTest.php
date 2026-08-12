<?php

/**
 * Unit tests for ProjectPhaseStatusListener.
 *
 * Asserts a project status change dispatches and records the outcome, a no-op
 * when status is unchanged, a non-project/non-phase schema is ignored, and a
 * projectPhase status change is resolved in the parent project's context.
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
use OCA\Pipelinq\Listener\ProjectPhaseStatusListener;
use OCA\Pipelinq\Service\LedgerSyncNotifier;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\ShillinqLedgerService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService capturing saves and serving a parent project on find().
 */
class PhaseFakeObjectService {
	/**
	 * Captured saved objects keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Project entities returned by find(), keyed by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	public array $store = [];

	/**
	 * Find an object by uuid.
	 *
	 * @param string $id The object uuid.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return object|null
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?object {
		return $this->store[$id] ?? null;
	}//end find()

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
 * Tests for ProjectPhaseStatusListener.
 */
class ProjectPhaseStatusListenerTest extends TestCase {
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
		$entity->setUuid((string)($data['uuid'] ?? 'obj-1'));
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Build the listener with the given collaborators.
	 *
	 * @param SchemaMapService $schemaMap The schema map service.
	 * @param ShillinqLedgerService $ledger The ledger service.
	 * @param PhaseFakeObjectService $objects The fake object service.
	 *
	 * @return ProjectPhaseStatusListener The listener under test.
	 */
	private function listener(
		SchemaMapService $schemaMap,
		ShillinqLedgerService $ledger,
		PhaseFakeObjectService $objects,
	): ProjectPhaseStatusListener {
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

		return new ProjectPhaseStatusListener(
			schemaMapService: $schemaMap,
			ledgerService: $ledger,
			notifier: $this->createMock(LedgerSyncNotifier::class),
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A status change on a project dispatches and records synced.
	 *
	 * @return void
	 */
	public function testProjectStatusChangeDispatches(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('project');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->method('shouldDispatch')->willReturn(true);
		$ledger->expects($this->once())
			->method('dispatchPhaseChangeEvent')
			->with($this->anything(), 'open', 'in_progress')
			->willReturn(true);

		$objects = new PhaseFakeObjectService();
		$listener = $this->listener($schemaMap, $ledger, $objects);

		$new = $this->entity('schema-project', ['uuid' => 'proj-1', 'status' => 'in_progress', 'name' => 'P']);
		$old = $this->entity('schema-project', ['uuid' => 'proj-1', 'status' => 'open', 'name' => 'P']);

		$listener->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertSame('synced', $objects->saved['proj-1']['ledgerSyncStatus']);
	}//end testProjectStatusChangeDispatches()

	/**
	 * An unchanged status is a no-op.
	 *
	 * @return void
	 */
	public function testNoopWhenStatusUnchanged(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('project');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->method('shouldDispatch')->willReturn(true);
		$ledger->expects($this->never())->method('dispatchPhaseChangeEvent');

		$objects = new PhaseFakeObjectService();
		$listener = $this->listener($schemaMap, $ledger, $objects);

		$new = $this->entity('schema-project', ['uuid' => 'proj-1', 'status' => 'open']);
		$old = $this->entity('schema-project', ['uuid' => 'proj-1', 'status' => 'open']);

		$listener->handle(new ObjectUpdatedEvent($new, $old));
		$this->assertCount(0, $objects->saved);
	}//end testNoopWhenStatusUnchanged()

	/**
	 * A non-project/non-phase schema is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresUnrelatedSchema(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('lead');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->expects($this->never())->method('dispatchPhaseChangeEvent');

		$objects = new PhaseFakeObjectService();
		$listener = $this->listener($schemaMap, $ledger, $objects);

		$new = $this->entity('schema-lead', ['uuid' => 'lead-1', 'status' => 'won']);
		$old = $this->entity('schema-lead', ['uuid' => 'lead-1', 'status' => 'open']);

		$listener->handle(new ObjectUpdatedEvent($new, $old));
		$this->assertCount(0, $objects->saved);
	}//end testIgnoresUnrelatedSchema()

	/**
	 * A phase status change resolves the parent project and records on it.
	 *
	 * @return void
	 */
	public function testPhaseStatusChangeResolvesParentProject(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('projectPhase');

		$ledger = $this->createMock(ShillinqLedgerService::class);
		$ledger->method('shouldDispatch')->willReturn(true);
		$ledger->expects($this->once())->method('dispatchPhaseChangeEvent')->willReturn(true);

		$objects = new PhaseFakeObjectService();
		// Parent project served by find().
		$objects->store['parent-proj'] = $this->entity('schema-project', ['uuid' => 'parent-proj', 'name' => 'Parent', 'status' => 'in_progress']);

		$listener = $this->listener($schemaMap, $ledger, $objects);

		$new = $this->entity('schema-phase', ['uuid' => 'phase-1', 'project' => 'parent-proj', 'status' => 'completed']);
		$old = $this->entity('schema-phase', ['uuid' => 'phase-1', 'project' => 'parent-proj', 'status' => 'in_progress']);

		$listener->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertArrayHasKey('parent-proj', $objects->saved);
		$this->assertSame('synced', $objects->saved['parent-proj']['ledgerSyncStatus']);
	}//end testPhaseStatusChangeResolvesParentProject()
}//end class
