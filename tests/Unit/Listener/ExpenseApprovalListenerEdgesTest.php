<?php

/**
 * The edges of ExpenseApprovalListener: failures it swallows and stale work it drops.
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
 * @spec openspec/changes/archive/2026-06-14-pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\Listener\DeferredWorkGuard;
use OCA\Pipelinq\Listener\ExpenseApprovalListener;
use OCA\Pipelinq\Service\ApSyncNotifier;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\ShillinqApService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A deferral failure is logged and swallowed, an expense without a uuid is
 * not deferred, the expense_schema config identifies an expense when the
 * schema map does not, and deferred work for a gone, un-approved or
 * unreadable expense dispatches nothing.
 *
 * @covers \OCA\Pipelinq\Listener\ExpenseApprovalListener
 * @uses   \OCA\Pipelinq\Listener\DeferredWorkGuard
 * @uses   \OCA\Pipelinq\Event\ExpenseApprovedEvent
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 */
class ExpenseApprovalListenerEdgesTest extends TestCase {
	/**
	 * The schema map.
	 *
	 * @var SchemaMapService&MockObject
	 */
	private SchemaMapService&MockObject $schemaMap;

	/**
	 * The AP service.
	 *
	 * @var ShillinqApService&MockObject
	 */
	private ShillinqApService&MockObject $apService;

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
	 * What the container returns for OpenRegister's ObjectService.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * Reset the re-entrancy guard and build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		DeferredWorkGuard::reset();
		$this->schemaMap = $this->createMock(originalClassName: SchemaMapService::class);
		$this->apService = $this->createMock(originalClassName: ShillinqApService::class);
		$this->deferral = $this->createMock(originalClassName: ListenerDeferralService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the listener.
	 *
	 * @param array<string, string> $config App-config values by key.
	 *
	 * @return ExpenseApprovalListener The listener.
	 */
	private function listener(array $config = ['register' => 'reg-1', 'expense_schema' => 'sch-expense']): ExpenseApprovalListener {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): ?object => $this->objectService);

		return new ExpenseApprovalListener(
			schemaMapService: $this->schemaMap,
			apService: $this->apService,
			notifier: $this->createMock(originalClassName: ApSyncNotifier::class),
			eventDispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
			container: $container,
			appConfig: $appConfig,
			deferral: $this->deferral,
			logger: $this->logger,
		);
	}//end listener()

	/**
	 * An expense entity.
	 *
	 * @param array<string, mixed> $data   The expense data.
	 * @param string               $uuid   The uuid.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function expense(array $data, string $uuid = 'exp-1', string $schema = 'sch-expense'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end expense()

	/**
	 * A deferral that throws is logged as a warning and does not escape.
	 *
	 * @return void
	 */
	public function testAFailingDeferralIsLoggedAndSwallowed(): void {
		$this->schemaMap->method('resolveEntityType')->willReturn('billableExpense');
		$this->apService->method('shouldDispatch')->willReturn(true);
		$this->deferral->method('defer')->willThrowException(new \RuntimeException('queue full'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: AP listener failed; expense workflow unaffected',
			['exception' => 'queue full']
		);

		$this->listener()->handle(event: new ObjectCreatedEvent(object: $this->expense(data: ['status' => 'approved'])));
	}//end testAFailingDeferralIsLoggedAndSwallowed()

	/**
	 * An approved expense without a uuid is not deferred.
	 *
	 * @return void
	 */
	public function testAnExpenseWithoutAUuidIsNotDeferred(): void {
		$this->schemaMap->method('resolveEntityType')->willReturn('billableExpense');
		$this->apService->method('shouldDispatch')->willReturn(true);
		$this->deferral->expects($this->never())->method('defer');

		$this->listener()->handle(event: new ObjectCreatedEvent(object: $this->expense(data: ['status' => 'approved'], uuid: '')));
	}//end testAnExpenseWithoutAUuidIsNotDeferred()

	/**
	 * When the schema map does not know the schema, the configured
	 * expense_schema still identifies it as an expense.
	 *
	 * @return void
	 */
	public function testTheExpenseSchemaConfigIdentifiesAnExpense(): void {
		$this->schemaMap->method('resolveEntityType')->willReturn(null);
		$this->apService->method('shouldDispatch')->willReturn(true);
		$this->deferral->expects($this->once())->method('defer')->with(
			\OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob::class,
			['handler' => 'expense-approval', 'uuid' => 'exp-9'],
			$this->anything(),
			'expense-approval|exp-9'
		);

		$this->listener()->handle(event: new ObjectCreatedEvent(object: $this->expense(data: ['status' => 'approved'], uuid: 'exp-9')));
	}//end testTheExpenseSchemaConfigIdentifiesAnExpense()

	/**
	 * A schema the map does not know and the config does not name is no expense.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaIsNoExpense(): void {
		$this->schemaMap->method('resolveEntityType')->willReturn(null);
		$this->apService->method('shouldDispatch')->willReturn(true);
		$this->deferral->expects($this->never())->method('defer');

		$this->listener()->handle(event: new ObjectCreatedEvent(object: $this->expense(data: ['status' => 'approved'], schema: 'sch-other')));
	}//end testAnUnknownSchemaIsNoExpense()

	/**
	 * Deferred work for an expense that is gone dispatches nothing.
	 *
	 * @return void
	 */
	public function testDeferredWorkForAGoneExpenseDoesNothing(): void {
		$this->objectService = new class {
			/**
			 * The expense is gone.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return null
			 */
			public function find(...$args) {
				return null;
			}//end find()
		};
		$this->apService->expects($this->never())->method('dispatchApEvent');

		$this->listener()->runDeferredWork(entry: ['handler' => 'expense-approval', 'uuid' => 'exp-1']);
	}//end testDeferredWorkForAGoneExpenseDoesNothing()

	/**
	 * Deferred work for an expense no longer approved dispatches nothing.
	 *
	 * @return void
	 */
	public function testDeferredWorkForAnUnapprovedExpenseDoesNothing(): void {
		$current = $this->expense(data: ['status' => 'draft']);
		$this->objectService = new class ($current) {
			/**
			 * Constructor.
			 *
			 * @param ObjectEntity $current The stored expense.
			 */
			public function __construct(private ObjectEntity $current) {
			}//end __construct()

			/**
			 * The stored expense.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return ObjectEntity The expense.
			 */
			public function find(...$args): ObjectEntity {
				return $this->current;
			}//end find()
		};
		$this->apService->method('shouldDispatch')->willReturn(true);
		$this->apService->expects($this->never())->method('dispatchApEvent');

		$this->listener()->runDeferredWork(entry: ['uuid' => 'exp-1']);
	}//end testDeferredWorkForAnUnapprovedExpenseDoesNothing()

	/**
	 * Without a register configured, deferred work reads nothing and dispatches nothing.
	 *
	 * @return void
	 */
	public function testDeferredWorkWithoutConfigDoesNothing(): void {
		$this->apService->expects($this->never())->method('dispatchApEvent');
		$this->logger->expects($this->never())->method('warning');

		$this->listener(config: ['expense_schema' => 'sch-expense'])->runDeferredWork(entry: ['uuid' => 'exp-1']);
	}//end testDeferredWorkWithoutConfigDoesNothing()

	/**
	 * A failing re-read is logged with the uuid and dispatches nothing.
	 *
	 * @return void
	 */
	public function testAFailingReReadIsLogged(): void {
		$this->objectService = new class {
			/**
			 * The store is down.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return never
			 */
			public function find(...$args) {
				throw new \RuntimeException('db down');
			}//end find()
		};
		$this->apService->expects($this->never())->method('dispatchApEvent');
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: failed to re-read expense for deferred AP dispatch',
			['exception' => 'db down', 'uuid' => 'exp-1']
		);

		$this->listener()->runDeferredWork(entry: ['uuid' => 'exp-1']);
	}//end testAFailingReReadIsLogged()

	/**
	 * A failing persist is logged and the dispatch still runs and records nothing else.
	 *
	 * @return void
	 */
	public function testAFailingPersistIsLoggedAndTheDispatchStillRuns(): void {
		$current = $this->expense(data: ['status' => 'approved', 'approvedBy' => 'alice', 'approvedAt' => '2026-05-15T14:30:00Z']);
		$this->objectService = new class ($current) {
			/**
			 * Constructor.
			 *
			 * @param ObjectEntity $current The stored expense.
			 */
			public function __construct(private ObjectEntity $current) {
			}//end __construct()

			/**
			 * The stored expense.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return ObjectEntity The expense.
			 */
			public function find(...$args): ObjectEntity {
				return $this->current;
			}//end find()

			/**
			 * The store refuses writes.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return never
			 */
			public function saveObject(...$args) {
				throw new \RuntimeException('read only');
			}//end saveObject()
		};
		$this->apService->method('shouldDispatch')->willReturn(true);
		$this->apService->method('now')->willReturn('2026-05-15T14:35:00Z');
		$this->apService->expects($this->once())->method('dispatchApEvent')->willReturn(true);
		$this->logger->expects($this->exactly(2))->method('warning')->with(
			'Pipelinq: failed to persist expense AP sync status',
			['exception' => 'read only', 'uuid' => 'exp-1']
		);

		$this->listener()->runDeferredWork(entry: ['uuid' => 'exp-1']);
	}//end testAFailingPersistIsLoggedAndTheDispatchStillRuns()
}//end class
