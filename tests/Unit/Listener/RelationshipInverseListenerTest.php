<?php

/**
 * Unit tests for RelationshipInverseListener.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Listener\DeferredWorkGuard;
use OCA\Pipelinq\Listener\RelationshipInverseListener;
use OCA\Pipelinq\Service\RelationshipInverseService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/RecordingDeferralService.php';
require_once __DIR__ . '/DeferredJobDrain.php';

/**
 * The inverse row of a relationship is kept by the server, for every writer.
 */
class RelationshipInverseListenerTest extends TestCase {

	private const SCHEMA = 'sch-relationship';

	/**
	 * Stored relationship rows by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Every write the listener made, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * Events the in-memory register raised and the listener has not yet seen.
	 *
	 * @var array<int, object>
	 */
	private array $raised = [];

	private int $sequence = 0;

	private ?RecordingDeferralService $deferral = null;

	private ?RelationshipInverseListener $listener = null;

	protected function setUp(): void {
		parent::setUp();
		DeferredWorkGuard::reset();
		$this->listener = $this->listener();
	}//end setUp()

	public function testApplicationRegistersTheListenerOnCreateUpdateAndDelete(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		$this->assertContains([ObjectCreatedEvent::class, RelationshipInverseListener::class], $registered);
		$this->assertContains([ObjectUpdatedEvent::class, RelationshipInverseListener::class], $registered);
		$this->assertContains([ObjectDeletedEvent::class, RelationshipInverseListener::class], $registered);
	}//end testApplicationRegistersTheListenerOnCreateUpdateAndDelete()

	public function testACreatedRelationshipGetsItsInverseOnce(): void {
		$this->create($this->relationship(from: 'jan', to: 'sophie', type: 'ouder', inverse: 'kind'));
		$this->assertCount(1, $this->deferral->entries, 'The inverse is queued, not written inside the save');

		$this->settle();

		$inverse = $this->rowsFrom('sophie');
		$this->assertCount(1, $inverse);
		$this->assertSame('jan', $inverse[0]['toContact']);
		$this->assertSame('kind', $inverse[0]['type']);
		$this->assertSame('ouder', $inverse[0]['inverseType']);
		$this->assertSame('client', $inverse[0]['fromType']);
		$this->assertSame('contact', $inverse[0]['toType']);
		$this->assertSame('Familie', $inverse[0]['category']);
		$this->assertSame('strong', $inverse[0]['strength']);
		$this->assertCount(1, $this->writes, 'The inverse of the inverse is the original, so nothing more is written');
	}//end testACreatedRelationshipGetsItsInverseOnce()

	public function testACreateWhoseInverseExistsWritesNothing(): void {
		$this->rows['inv'] = $this->relationship(from: 'sophie', to: 'jan', type: 'kind', inverse: 'ouder', fromType: 'client', toType: 'contact');
		$this->create($this->relationship(from: 'jan', to: 'sophie', type: 'ouder', inverse: 'kind'));
		$this->settle();

		$this->assertSame([], $this->writes);
	}//end testACreateWhoseInverseExistsWritesNothing()

	public function testEditingNotesPatchesOnlyTheNotesOfTheInverse(): void {
		$uuid = $this->create($this->relationship(from: 'jan', to: 'sophie', type: 'ouder', inverse: 'kind'));
		$this->settle();
		$this->writes = [];

		$this->update($uuid, ['notes' => 'Lives abroad']);
		$this->settle();

		$this->assertCount(1, $this->writes);
		$this->assertSame('patch', $this->writes[0]['op']);
		$this->assertSame(['notes' => 'Lives abroad'], $this->writes[0]['data']);
		$inverse = $this->rowsFrom('sophie')[0];
		$this->assertSame('jan', $inverse['toContact'], 'The pair survives an edit');
		$this->assertSame('sophie', $inverse['fromContact']);
		$this->assertSame('Lives abroad', $inverse['notes']);
	}//end testEditingNotesPatchesOnlyTheNotesOfTheInverse()

	public function testChangingTheTypeMovesTheInverseInsteadOfAddingOne(): void {
		$uuid = $this->create($this->relationship(from: 'jan', to: 'sophie', type: 'ouder', inverse: 'kind'));
		$this->settle();

		$this->update($uuid, ['type' => 'partner', 'inverseType' => 'partner']);
		$this->settle();

		$inverse = $this->rowsFrom('sophie');
		$this->assertCount(1, $inverse);
		$this->assertSame('partner', $inverse[0]['type']);
		$this->assertSame('partner', $inverse[0]['inverseType']);
	}//end testChangingTheTypeMovesTheInverseInsteadOfAddingOne()

	public function testMovingTheRelationshipToAnotherPartyLeavesNoInverseAtTheOldOne(): void {
		$uuid = $this->create($this->relationship(from: 'jan', to: 'sophie', type: 'ouder', inverse: 'kind'));
		$this->settle();

		$this->update($uuid, ['toContact' => 'piet']);
		$this->settle();

		$this->assertSame([], $this->rowsFrom('sophie'), 'The old party keeps no inverse');
		$this->assertCount(1, $this->rowsFrom('piet'));
		$this->assertSame('jan', $this->rowsFrom('piet')[0]['toContact']);
	}//end testMovingTheRelationshipToAnotherPartyLeavesNoInverseAtTheOldOne()

	public function testAnEditThatTouchesNoPairFieldQueuesNothing(): void {
		$uuid = $this->create($this->relationship(from: 'jan', to: 'sophie', type: 'ouder', inverse: 'kind'));
		$this->settle();

		$this->update($uuid, ['provenance' => 'imported']);

		$this->assertSame([], $this->deferral->entries);
	}//end testAnEditThatTouchesNoPairFieldQueuesNothing()

	public function testAPurgedRelationshipTakesItsInverseWithIt(): void {
		$uuid = $this->create($this->relationship(from: 'jan', to: 'sophie', type: 'ouder', inverse: 'kind'));
		$this->settle();

		$this->purge($uuid);
		$this->settle();

		$this->assertSame([], $this->rows);
	}//end testAPurgedRelationshipTakesItsInverseWithIt()

	public function testASoftDeletedRelationshipTakesItsInverseWithIt(): void {
		$uuid = $this->create($this->relationship(from: 'jan', to: 'sophie', type: 'ouder', inverse: 'kind'));
		$this->settle();

		$this->trash($uuid);
		$this->settle();

		$this->assertSame([], $this->rowsFrom('sophie'));
	}//end testASoftDeletedRelationshipTakesItsInverseWithIt()

	public function testAnEditInsideTheTrashQueuesNothing(): void {
		$old = $this->entity(uuid: 'r1', data: $this->relationship(from: 'jan', to: 'sophie', type: 'ouder', inverse: 'kind'));
		$old->setDeleted(['deleted' => '2026-10-21']);
		$new = $this->entity(uuid: 'r1', data: array_merge($old->getObject(), ['notes' => 'x']));
		$new->setDeleted(['deleted' => '2026-10-21']);

		$this->listener->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertSame([], $this->deferral->entries);
	}//end testAnEditInsideTheTrashQueuesNothing()

	public function testAnotherSchemaIsIgnored(): void {
		$this->listener->handle(
			new ObjectCreatedEvent($this->entity(uuid: 'lead-1', data: ['title' => 'x'], schema: 'sch-lead'))
		);

		$this->assertSame([], $this->deferral->entries);
	}//end testAnotherSchemaIsIgnored()

	public function testARelationshipDeletedBeforeTheJobRanIsLeftAlone(): void {
		$this->listener->runDeferredWork(
			['handler' => RelationshipInverseListener::HANDLER_KEY, 'uuid' => 'gone', 'action' => 'save']
		);

		$this->assertSame([], $this->writes);
	}//end testARelationshipDeletedBeforeTheJobRanIsLeftAlone()

	/**
	 * Run queued jobs and feed the register's events back until nothing is left.
	 *
	 * @return void
	 */
	private function settle(): void {
		for ($round = 0; $round < 10; $round++) {
			foreach ($this->raised as $event) {
				$this->listener->handle($event);
			}

			$this->raised = [];
			if ($this->deferral->entries === []) {
				return;
			}

			DeferredJobDrain::run($this, $this->deferral, $this->listener);
		}

		$this->fail('The inverse work did not settle: it keeps re-queuing itself');
	}//end settle()

	private function create(array $data): string {
		$uuid = 'r' . (++$this->sequence);
		$this->rows[$uuid] = $data;
		$this->listener->handle(new ObjectCreatedEvent($this->entity(uuid: $uuid, data: $data)));

		return $uuid;
	}//end create()

	private function update(string $uuid, array $changes): void {
		$old = $this->entity(uuid: $uuid, data: $this->rows[$uuid]);
		$this->rows[$uuid] = array_merge($this->rows[$uuid], $changes);
		$this->listener->handle(new ObjectUpdatedEvent($this->entity(uuid: $uuid, data: $this->rows[$uuid]), $old));
	}//end update()

	private function purge(string $uuid): void {
		$entity = $this->entity(uuid: $uuid, data: $this->rows[$uuid]);
		unset($this->rows[$uuid]);
		$this->listener->handle(new ObjectDeletedEvent($entity));
	}//end purge()

	private function trash(string $uuid): void {
		$old = $this->entity(uuid: $uuid, data: $this->rows[$uuid]);
		$new = $this->entity(uuid: $uuid, data: $this->rows[$uuid]);
		$new->setDeleted(['deleted' => '2026-10-21']);
		unset($this->rows[$uuid]);
		$this->listener->handle(new ObjectUpdatedEvent($new, $old));
	}//end trash()

	private function rowsFrom(string $from): array {
		return array_values(array_filter($this->rows, static fn (array $row): bool => $row['fromContact'] === $from));
	}//end rowsFrom()

	private function relationship(
		string $from,
		string $to,
		string $type,
		string $inverse,
		string $fromType = 'contact',
		string $toType = 'client',
	): array {
		return [
			'fromContact' => $from,
			'toContact' => $to,
			'fromType' => $fromType,
			'toType' => $toType,
			'type' => $type,
			'inverseType' => $inverse,
			'category' => 'Familie',
			'strength' => 'strong',
		];
	}//end relationship()

	private function entity(string $uuid, array $data, string $schema = self::SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	private function listener(): RelationshipInverseListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = ['register' => 'reg-1', 'relationship_schema' => self::SCHEMA];

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				if (isset($this->rows[(string)$id]) === false) {
					return null;
				}

				return $this->entity(uuid: (string)$id, data: $this->rows[(string)$id]);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$out = [];
				foreach ($this->rows as $uuid => $row) {
					if ($row['fromContact'] === $filters['fromContact'] && $row['toContact'] === $filters['toContact']) {
						$out[] = $this->entity(uuid: (string)$uuid, data: $row);
					}
				}

				return $out;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$uuid = 'r' . (++$this->sequence);
				$this->rows[$uuid] = $object;
				$this->writes[] = ['op' => 'create', 'uuid' => $uuid, 'data' => $object];
				$entity = $this->entity(uuid: $uuid, data: $object);
				$this->raised[] = new ObjectCreatedEvent($entity);

				return $entity;
			}
		);
		$objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntity {
				$old = $this->entity(uuid: $objectId, data: $this->rows[$objectId]);
				$this->rows[$objectId] = array_merge($this->rows[$objectId], $data);
				$this->writes[] = ['op' => 'patch', 'uuid' => $objectId, 'data' => $data];
				$entity = $this->entity(uuid: $objectId, data: $this->rows[$objectId]);
				$this->raised[] = new ObjectUpdatedEvent($entity, $old);

				return $entity;
			}
		);
		$objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				$entity = $this->entity(uuid: $uuid, data: $this->rows[$uuid]);
				unset($this->rows[$uuid]);
				$this->writes[] = ['op' => 'delete', 'uuid' => $uuid];
				$this->raised[] = new ObjectDeletedEvent($entity);

				return true;
			}
		);

		$this->deferral = new RecordingDeferralService();

		$logger = $this->createMock(LoggerInterface::class);

		return new RelationshipInverseListener(
			inverses: new RelationshipInverseService(objectService: $objectService, appConfig: $appConfig, logger: $logger),
			deferral: $this->deferral,
			logger: $logger,
		);
	}//end listener()
}//end class
