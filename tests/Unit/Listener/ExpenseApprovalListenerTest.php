<?php

/**
 * Unit tests for ExpenseApprovalListener.
 *
 * Asserts the listener dispatches an approved expense to the Shillinq AP
 * webhook, records the synced/failed outcome on the persisted object, is
 * idempotent for an already-synced expense (REQ-AP-002 Scenario 5),
 * no-ops when unconfigured (REQ-AP-002 Scenario 6), ignores non-expense
 * schemas and ignores non-approved status transitions.
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
use OCA\Pipelinq\Listener\ExpenseApprovalListener;
use OCA\Pipelinq\Service\ApSyncNotifier;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\ShillinqApService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

// The ADR-078 deferral doubles live in the Tests namespace, which has no PSR-4
// mapping, and PHPUnit only auto-loads files whose name ends in `Test.php`. Load
// them from the test that uses them — the convention the rest of this suite
// follows — so they resolve under every bootstrap (tests/bootstrap.php,
// bootstrap-unit.php, bootstrap-bare.php) instead of only the one that happens
// to list them.
require_once __DIR__ . '/RecordingDeferralService.php';
require_once __DIR__ . '/DeferredJobDrain.php';

/**
 * In-memory ObjectService capturing saveObject() calls.
 */
class ApFakeObjectService {
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
		$this->stored[(string)$uuid] = (array)$object;
		return (array)$object;
	}//end saveObject()

	/**
	 * Objects readable by find(), keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $stored = [];

	/**
	 * Serve the deferred pass's re-read of current state.
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
 * An ObjectService double that dispatches the lifecycle event its write causes.
 *
 * The plain double above captures and stops, which is why the suite could be
 * green over an unbounded re-entrant loop (pipelinq#808). This one mirrors
 * MagicMapper::update(), which dispatches ObjectUpdatedEvent for every
 * non-system write with the persisted entity as `newObject`.
 */
class ReDispatchingApFakeObjectService extends ApFakeObjectService {
	/**
	 * The listener to re-enter, standing in for the event dispatcher.
	 *
	 * @var ExpenseApprovalListener|null
	 */
	public ?ExpenseApprovalListener $listener = null;

	/**
	 * Builds an ObjectEntity from persisted data, as the mapper returns one.
	 *
	 * @var callable|null
	 */
	public $entityFactory = null;

	/**
	 * How many re-dispatches were emitted.
	 *
	 * @var int
	 */
	public int $reDispatches = 0;

	/**
	 * Bound on re-dispatch depth.
	 *
	 * Present so an unguarded listener fails this test with a countable number
	 * rather than a stack overflow — a fatal is not a test failure, and a test
	 * that can only die is a test that cannot report.
	 *
	 * @var int
	 */
	public int $maxReDispatch = 3;

	/**
	 * Capture the save, then dispatch the update event it causes.
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
		$saved = parent::saveObject($object, $extend, $register, $schema, $uuid);

		if ($this->listener === null || $this->entityFactory === null) {
			return $saved;
		}

		if ($this->reDispatches >= $this->maxReDispatch) {
			return $saved;
		}

		$this->reDispatches++;
		$entity = ($this->entityFactory)($saved);
		$this->listener->handle(new ObjectUpdatedEvent($entity, $entity));

		return $saved;
	}//end saveObject()
}//end class

/**
 * Tests for ExpenseApprovalListener.
 */
class ExpenseApprovalListenerTest extends TestCase {

	/**
	 * The deferral double the last-built listener was wired with.
	 *
	 * @var RecordingDeferralService|null
	 */
	private ?RecordingDeferralService $deferral = null;

	/**
	 * Clear the shared re-entrancy guard between tests.
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
		// A REAL entity, not a mock. Production's getSchema()/getUuid() are served
		// by Entity::__call and are not declared methods, so a double that declares
		// them (or a mock configured through onlyMethods) inverts the very
		// predicate the listener guards on — that inversion is what kept this
		// listener's death invisible for the life of the feature (pipelinq#807).
		$entity = new ObjectEntity();
		$entity->setUuid((string)($data['uuid'] ?? 'exp-1'));
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Build the listener with the given collaborators.
	 *
	 * @param SchemaMapService $schemaMap The schema map service.
	 * @param ShillinqApService $apService The AP service.
	 * @param ApFakeObjectService $objects The fake object service.
	 * @param ApSyncNotifier|null $notify The failure notifier (optional).
	 *
	 * @return ExpenseApprovalListener The listener under test.
	 */
	private function listener(
		SchemaMapService $schemaMap,
		ShillinqApService $apService,
		ApFakeObjectService $objects,
		?ApSyncNotifier $notify = null,
	): ExpenseApprovalListener {
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
				if ($key === 'expense_schema') {
					return 'schema-expense';
				}
				return $default;
			}
		);

		$this->deferral = new RecordingDeferralService();

		return new ExpenseApprovalListener(
			schemaMapService: $schemaMap,
			apService: $apService,
			notifier: ($notify ?? $this->createMock(ApSyncNotifier::class)),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			container: $container,
			appConfig: $appConfig,
			deferral: $this->deferral,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * Run every entry the listener queued, through the real background job.
	 *
	 * @param ExpenseApprovalListener $listener The listener under test.
	 *
	 * @return void
	 */
	private function drain(ExpenseApprovalListener $listener): void {
		if ($this->deferral !== null) {
			DeferredJobDrain::run($this, $this->deferral, $listener);
		}
	}//end drain()

	/**
	 * A non-ObjectCreatedEvent / ObjectUpdatedEvent is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresNonObjectEvent(): void {
		$apService = $this->createMock(ShillinqApService::class);
		$apService->expects($this->never())->method('dispatchApEvent');

		$objects = new ApFakeObjectService();
		$listener = $this->listener($this->createMock(SchemaMapService::class), $apService, $objects);

		$listener->handle(new class extends Event {});
		$this->assertCount(0, $objects->saved);
	}//end testIgnoresNonObjectEvent()

	/**
	 * A non-expense schema is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresNonExpenseSchema(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('lead');

		$apService = $this->createMock(ShillinqApService::class);
		$apService->expects($this->never())->method('dispatchApEvent');

		$objects = new ApFakeObjectService();
		$listener = $this->listener($schemaMap, $apService, $objects);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-lead', ['uuid' => 'lead-1', 'status' => 'approved'])));
		$this->assertCount(0, $objects->saved);
	}//end testIgnoresNonExpenseSchema()

	/**
	 * A non-approved expense is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresNonApprovedExpense(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('expense');

		$apService = $this->createMock(ShillinqApService::class);
		$apService->expects($this->never())->method('dispatchApEvent');

		$objects = new ApFakeObjectService();
		$listener = $this->listener($schemaMap, $apService, $objects);

		$listener->handle(new ObjectUpdatedEvent($this->entity('schema-expense', ['uuid' => 'exp-1', 'status' => 'draft']),
			$this->entity('schema-expense', ['uuid' => 'exp-1', 'status' => 'draft'])
		));
		$this->assertCount(0, $objects->saved);
	}//end testIgnoresNonApprovedExpense()

	/**
	 * An unconfigured integration no-ops without persisting (REQ-AP-002 Scenario 6).
	 *
	 * @return void
	 */
	public function testNoopWhenUnconfigured(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('expense');

		$apService = $this->createMock(ShillinqApService::class);
		$apService->method('shouldDispatch')->willReturn(false);
		$apService->expects($this->never())->method('dispatchApEvent');

		$objects = new ApFakeObjectService();
		$listener = $this->listener($schemaMap, $apService, $objects);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-expense', ['uuid' => 'exp-1', 'status' => 'approved'])
		));
		$this->assertCount(0, $objects->saved);
	}//end testNoopWhenUnconfigured()

	/**
	 * A successful dispatch marks the expense synced and stamps apSyncedAt (REQ-AP-003 Scenario 9).
	 *
	 * @return void
	 */
	public function testSuccessfulDispatchMarksSynced(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('expense');

		$apService = $this->createMock(ShillinqApService::class);
		$apService->method('shouldDispatch')->willReturn(true);
		$apService->method('dispatchApEvent')->willReturn(true);
		$apService->method('now')->willReturn('2026-05-15T14:35:00Z');

		$expense = [
			'uuid' => 'exp-1',
			'title' => 'Hotel',
			'amount' => 185.50,
			'status' => 'approved',
			'approvedBy' => 'alice',
			'approvedAt' => '2026-05-15T14:30:00Z',
		];

		$objects = new ApFakeObjectService();
		$objects->stored['exp-1'] = $expense;
		$listener = $this->listener($schemaMap, $apService, $objects);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-expense', $expense)));

		// ADR-078: nothing written and nothing dispatched on the request.
		$this->assertCount(1, $this->deferral->entries);
		$this->assertCount(0, $objects->saved);

		$this->drain($listener);

		$this->assertArrayHasKey('exp-1', $objects->saved);
		$this->assertSame('synced', $objects->saved['exp-1']['apSyncStatus']);
		$this->assertSame('2026-05-15T14:35:00Z', $objects->saved['exp-1']['apSyncedAt']);
	}//end testSuccessfulDispatchMarksSynced()

	/**
	 * A failed dispatch marks the expense failed and notifies admins (REQ-AP-003 Scenario 10).
	 *
	 * @return void
	 */
	public function testFailedDispatchMarksFailedAndNotifies(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('expense');

		$apService = $this->createMock(ShillinqApService::class);
		$apService->method('shouldDispatch')->willReturn(true);
		$apService->method('dispatchApEvent')->willReturn(false);
		$apService->method('now')->willReturn('2026-05-15T14:35:00Z');

		$notify = $this->createMock(ApSyncNotifier::class);
		$notify->expects($this->once())->method('notifyFailure');

		$expense = [
			'uuid' => 'exp-1',
			'title' => 'Catering',
			'amount' => 78.30,
			'status' => 'approved',
			'approvedBy' => 'alice',
			'approvedAt' => '2026-05-10T11:20:00Z',
		];

		$objects = new ApFakeObjectService();
		$objects->stored['exp-1'] = $expense;
		$listener = $this->listener($schemaMap, $apService, $objects, $notify);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-expense', $expense)));
		$this->drain($listener);

		$this->assertSame('failed', $objects->saved['exp-1']['apSyncStatus']);
	}//end testFailedDispatchMarksFailedAndNotifies()

	/**
	 * An already-synced expense is not re-dispatched (REQ-AP-002 Scenario 5 — idempotency).
	 *
	 * @return void
	 */
	public function testIdempotentSkipsAlreadySynced(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('expense');

		$apService = $this->createMock(ShillinqApService::class);
		$apService->method('shouldDispatch')->willReturn(true);
		$apService->expects($this->never())->method('dispatchApEvent');

		$objects = new ApFakeObjectService();
		$listener = $this->listener($schemaMap, $apService, $objects);

		$listener->handle(new ObjectUpdatedEvent($this->entity('schema-expense', [
				'uuid' => 'exp-1',
				'status' => 'approved',
				'apSyncStatus' => 'synced',
				'apSyncedAt' => '2026-05-15T14:35:00Z',
			]),
			$this->entity('schema-expense', [
				'uuid' => 'exp-1',
				'status' => 'approved',
				'apSyncStatus' => 'synced',
				'apSyncedAt' => '2026-05-15T14:35:00Z',
			])
		));

		$this->assertCount(0, $objects->saved);
	}//end testIdempotentSkipsAlreadySynced()

	/**
	 * The listener's own write must not re-enter it (pipelinq#808).
	 *
	 * This is the test the ordinary suite could not have: every other double
	 * here captures saveObject() and stops. Production does not stop —
	 * MagicMapper::update() dispatches ObjectUpdatedEvent for that write
	 * (openregister lib/Db/MagicMapper.php:8997), and it is withheld only
	 * inside SystemOperationContext, which a listener is not in. `silent: true`
	 * does not change that: $silent gates the audit trail and inverse-relation
	 * pass only.
	 *
	 * So the double below re-dispatches, exactly as the mapper does, with the
	 * data persist() just wrote. The idempotency guard cannot stop the loop —
	 * it short-circuits on apSyncStatus === 'synced' and the re-entry sees
	 * 'pending' — so without the in-flight guard each level fires another
	 * outbound AP webhook and the terminating 'synced' write is never reached.
	 *
	 * Revert control, predicted before running: with the in-flight guard
	 * removed and the double's re-dispatch capped at 3, dispatchApEvent is
	 * called 4 times instead of 1.
	 *
	 * @return void
	 */
	public function testOwnWriteDoesNotReEnterTheListener(): void {
		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn('expense');

		$dispatches = 0;

		$apService = $this->createMock(ShillinqApService::class);
		$apService->method('shouldDispatch')->willReturn(true);
		$apService->method('now')->willReturn('2026-05-15T14:35:00Z');
		$apService->method('dispatchApEvent')->willReturnCallback(
			function () use (&$dispatches): bool {
				$dispatches++;
				return true;
			}
		);

		$expense = [
			'uuid' => 'exp-1',
			'title' => 'Hotel',
			'amount' => 185.50,
			'status' => 'approved',
			'approvedBy' => 'alice',
			'approvedAt' => '2026-05-15T14:30:00Z',
		];

		$objects = new ReDispatchingApFakeObjectService();
		$objects->stored['exp-1'] = $expense;
		$listener = $this->listener($schemaMap, $apService, $objects);

		$objects->listener = $listener;
		$objects->entityFactory = fn (array $data): ObjectEntity => $this->entity('schema-expense', $data);

		$listener->handle(new ObjectCreatedEvent($this->entity('schema-expense', $expense)));

		// ADR-078: the approval only QUEUES on the request.
		$this->assertCount(1, $this->deferral->entries);
		$this->assertSame(0, $dispatches);

		// The job runs the work — and its writes come straight back through the
		// dispatcher into handle(), exactly as MagicMapper::update() does.
		$this->drain($listener);

		// One outbound AP webhook for one approval, however many times our own
		// write comes back around.
		$this->assertSame(1, $dispatches);

		// And the terminating write is reached, which is what the recursion
		// prevented: the loop never got past 'pending'.
		$this->assertSame('synced', $objects->saved['exp-1']['apSyncStatus']);

		// The re-dispatch actually happened — otherwise this test asserts
		// nothing and would pass with the guard deleted.
		$this->assertGreaterThan(0, $objects->reDispatches);

		// THE DEFERRED-ERA HALF OF THE SAME BUG: a re-entrant handle() that
		// deferred again would put a NEW entry on the queue, and cron — which
		// runs one job per web call — would grind on this expense for ever,
		// starving every other job. The recorder was emptied by the drain, so a
		// non-empty list here is that loop.
		$this->assertCount(0, $this->deferral->entries);
	}//end testOwnWriteDoesNotReEnterTheListener()
}//end class
