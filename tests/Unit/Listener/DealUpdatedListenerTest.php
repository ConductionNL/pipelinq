<?php

/**
 * Unit tests for DealUpdatedListener.
 *
 * Asserts which lead updates queue deferred work (a rejected forecast
 * transition or a reopen reset, not while their own pass runs), and what the
 * deferred pass does: revert a rejected category to the old one, save a reopen
 * reset, and end quietly when unconfigured or failing.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Listener\DealUpdatedListener;
use OCA\Pipelinq\Listener\DeferredWorkGuard;
use OCA\Pipelinq\Service\ForecastDealService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__.'/RecordingDeferralService.php';

/**
 * Tests for DealUpdatedListener.
 *
 * @covers \OCA\Pipelinq\Listener\DealUpdatedListener
 * @uses   \OCA\Pipelinq\Listener\DeferredWorkGuard
 */
class DealUpdatedListenerTest extends TestCase {

	/**
	 * The schema map mock.
	 *
	 * @var SchemaMapService&MockObject
	 */
	private SchemaMapService $schemaMap;

	/**
	 * The forecast service mock.
	 *
	 * @var ForecastDealService&MockObject
	 */
	private ForecastDealService $deals;

	/**
	 * The container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The deferral recorder.
	 *
	 * @var RecordingDeferralService
	 */
	private RecordingDeferralService $deferral;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The listener under test.
	 *
	 * @var DealUpdatedListener
	 */
	private DealUpdatedListener $listener;

	/**
	 * Set up the listener with its doubles; the register and lead schema are configured.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		DeferredWorkGuard::reset();
		$this->schemaMap = $this->createMock(originalClassName: SchemaMapService::class);
		$this->schemaMap->method('resolveEntityType')->willReturnCallback(
			callback: static fn (?string $schemaId): ?string => match ($schemaId) {
				'schema-lead' => 'lead',
				default => 'client',
			}
		);
		$this->deals     = $this->createMock(originalClassName: ForecastDealService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->configure(register: 'reg-1', schema: 'lead-schema');
		$this->deferral = new RecordingDeferralService();
		$this->logger   = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new DealUpdatedListener(
			schemaMapService: $this->schemaMap,
			dealService: $this->deals,
			container: $this->container,
			appConfig: $this->appConfig,
			deferral: $this->deferral,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * Reset the in-flight guard.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		DeferredWorkGuard::reset();
	}//end tearDown()

	/**
	 * Configure the register and lead schema app values.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The lead schema id.
	 *
	 * @return void
	 */
	private function configure(string $register, string $schema): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			callback: static fn (string $app, string $key, string $default=''): string => match ($key) {
				'register' => $register,
				'lead_schema' => $schema,
				default => $default,
			}
		);
	}//end configure()

	/**
	 * An object entity double.
	 *
	 * @param string               $schema The schema id.
	 * @param string               $uuid   The uuid.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, string $uuid, array $data=[]): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An ObjectService stand-in that serves find() and records saveObject().
	 *
	 * @param ObjectEntity|null|\Throwable $found  What find() returns or throws.
	 * @param \Throwable|null              $onSave What saveObject() throws, if anything.
	 *
	 * @return object
	 */
	private function objects(mixed $found, ?\Throwable $onSave=null): object {
		$objects = new class($found, $onSave) {
			/**
			 * The find() calls.
			 *
			 * @var array<int, array<string, string>>
			 */
			public array $finds = [];

			/**
			 * The saveObject() calls.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param mixed           $found  What find() returns or throws.
			 * @param \Throwable|null $onSave What saveObject() throws.
			 */
			public function __construct(private mixed $found, private ?\Throwable $onSave) {
			}//end __construct()

			/**
			 * Record and answer a find.
			 *
			 * @param string $id       The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return ObjectEntity|null
			 */
			public function find(string $id, string $register, string $schema): ?ObjectEntity {
				$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}//end find()

			/**
			 * Record a save.
			 *
			 * @param array       $object   The data.
			 * @param array       $extend   The extend list.
			 * @param string      $register The register.
			 * @param string      $schema   The schema.
			 * @param string|null $uuid     The uuid.
			 *
			 * @return void
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid): void {
				$this->saves[] = ['object' => $object, 'extend' => $extend, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				if ($this->onSave !== null) {
					throw $this->onSave;
				}
			}//end saveObject()
		};
		$this->container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn(value: $objects);
		return $objects;
	}//end objects()

	/**
	 * Wrap two lead states in an update event.
	 *
	 * @param array<string, mixed> $old  The old data.
	 * @param array<string, mixed> $new  The new data.
	 * @param string               $uuid The uuid.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function update(array $old, array $new, string $uuid='lead-1'): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent(
			$this->entity(schema: 'schema-lead', uuid: $uuid, data: $new),
			$this->entity(schema: 'schema-lead', uuid: $uuid, data: $old)
		);
	}//end update()

	/**
	 * A rejected transition queues the deal with its old data.
	 *
	 * @return void
	 */
	public function testARejectedTransitionIsDeferredWithTheOldData(): void {
		$this->deals->expects($this->once())->method('validateTransition')
			->with(['forecast_category' => 'closed_won', 'id' => 'lead-1'], ['forecast_category' => 'commit', 'id' => 'lead-1'])
			->willReturn(value: 'forecast.error.closed_deal_locked');
		$this->deals->expects($this->never())->method('applyReopenReset');

		$this->listener->handle(event: $this->update(old: ['forecast_category' => 'closed_won'], new: ['forecast_category' => 'commit']));

		self::assertSame(expected: [DeferredObjectListenerJob::class], actual: $this->deferral->jobClasses);
		self::assertSame(
			expected: [['handler' => 'deal-updated', 'uuid' => 'lead-1', 'oldData' => ['id' => 'lead-1', 'forecast_category' => 'closed_won']]],
			actual: $this->deferral->entries
		);
		self::assertSame(expected: ['deal-updated|lead-1'], actual: $this->deferral->dedupeKeys);
	}//end testARejectedTransitionIsDeferredWithTheOldData()

	/**
	 * A valid transition that needs a reopen reset is queued too.
	 *
	 * @return void
	 */
	public function testAReopenResetIsDeferred(): void {
		$this->deals->method('validateTransition')->willReturn(value: null);
		$this->deals->method('applyReopenReset')->willReturn(value: ['forecast_category' => 'pipeline']);

		$this->listener->handle(event: $this->update(old: ['status' => 'won'], new: ['status' => 'open']));

		self::assertCount(expectedCount: 1, haystack: $this->deferral->entries);
	}//end testAReopenResetIsDeferred()

	/**
	 * An update with nothing to correct, other events, other schemas, no uuid or a running pass queue nothing.
	 *
	 * @return void
	 */
	public function testNothingElseIsDeferred(): void {
		$this->deals->method('validateTransition')->willReturn(value: null);
		$this->deals->method('applyReopenReset')->willReturn(value: null);

		$this->listener->handle(event: $this->update(old: ['forecast_category' => 'pipeline'], new: ['forecast_category' => 'commit']));
		$this->listener->handle(event: new Event());
		$this->listener->handle(
			event: new ObjectUpdatedEvent($this->entity(schema: 'schema-client', uuid: 'c-1'), $this->entity(schema: 'schema-client', uuid: 'c-1'))
		);
		$this->listener->handle(event: $this->update(old: [], new: [], uuid: ''));
		DeferredWorkGuard::enter(key: DeferredWorkGuard::key(handler: 'deal-updated', uuid: 'lead-2'));
		$this->listener->handle(event: $this->update(old: [], new: [], uuid: 'lead-2'));

		self::assertSame(expected: [], actual: $this->deferral->entries);
	}//end testNothingElseIsDeferred()

	/**
	 * An update without an old object is ignored before the service is asked.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAnOldObjectIsIgnored(): void {
		$this->deals->expects($this->never())->method('validateTransition');

		$this->listener->handle(event: new ObjectUpdatedEvent($this->entity(schema: 'schema-lead', uuid: 'lead-1'), null));

		self::assertSame(expected: [], actual: $this->deferral->entries);
	}//end testAnUpdateWithoutAnOldObjectIsIgnored()

	/**
	 * A transition still rejected on re-read is reverted to the old category, and logged.
	 *
	 * @return void
	 */
	public function testDeferredWorkRevertsARejectedCategory(): void {
		$objects = $this->objects(found: $this->entity(schema: 'lead-schema', uuid: 'lead-1', data: ['forecast_category' => 'commit']));
		$this->deals->method('validateTransition')->willReturn(value: 'forecast.error.closed_deal_locked');
		$this->deals->expects($this->never())->method('applyReopenReset');
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: rejected forecast_category transition; reverting',
			['uuid' => 'lead-1', 'reason' => 'forecast.error.closed_deal_locked']
		);

		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1', 'oldData' => ['forecast_category' => 'closed_won']]);

		self::assertSame(expected: [['id' => 'lead-1', 'register' => 'reg-1', 'schema' => 'lead-schema']], actual: $objects->finds);
		self::assertSame(
			expected: [[
				'object' => ['id' => 'lead-1', 'forecast_category' => 'closed_won'],
				'extend' => [],
				'register' => 'reg-1',
				'schema' => 'lead-schema',
				'uuid' => 'lead-1',
			]],
			actual: $objects->saves
		);
	}//end testDeferredWorkRevertsARejectedCategory()

	/**
	 * Old data without a category reverts to the default category.
	 *
	 * @return void
	 */
	public function testDeferredWorkRevertsToTheDefaultWithoutAnOldCategory(): void {
		$objects = $this->objects(found: $this->entity(schema: 'lead-schema', uuid: 'lead-1', data: ['forecast_category' => 'commit']));
		$this->deals->method('validateTransition')->willReturn(value: 'forecast.error.justification_required');

		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1']);

		self::assertSame(expected: ForecastDealService::DEFAULT_CATEGORY, actual: $objects->saves[0]['object']['forecast_category']);
	}//end testDeferredWorkRevertsToTheDefaultWithoutAnOldCategory()

	/**
	 * A valid transition with a reopen reset saves the reset data; without one nothing is saved.
	 *
	 * @return void
	 */
	public function testDeferredWorkSavesAReopenReset(): void {
		$objects = $this->objects(found: $this->entity(schema: 'lead-schema', uuid: 'lead-1', data: ['status' => 'open']));
		$this->deals->method('validateTransition')->willReturn(value: null);
		$this->deals->method('applyReopenReset')->willReturnOnConsecutiveCalls(['status' => 'open', 'forecast_category' => 'pipeline'], null);

		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1', 'oldData' => ['status' => 'won']]);
		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1', 'oldData' => ['status' => 'won']]);

		self::assertSame(
			expected: [['status' => 'open', 'forecast_category' => 'pipeline']],
			actual: array_column(array: $objects->saves, column_key: 'object')
		);
	}//end testDeferredWorkSavesAReopenReset()

	/**
	 * Non-array old data, a missing deal, or a missing register end the pass without a save.
	 *
	 * @return void
	 */
	public function testDeferredWorkStopsEarly(): void {
		$objects = $this->objects(found: null);
		$this->deals->expects($this->never())->method('validateTransition');

		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1', 'oldData' => 'bad']);
		$this->listener->runDeferredWork(entry: ['uuid' => 'gone']);

		self::assertSame(expected: ['gone'], actual: array_column(array: $objects->finds, column_key: 'id'));
		self::assertSame(expected: [], actual: $objects->saves);
	}//end testDeferredWorkStopsEarly()

	/**
	 * Without a configured lead schema the pass never reaches the object service.
	 *
	 * @return void
	 */
	public function testDeferredWorkNeedsTheSchemaConfigured(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			callback: static fn (string $app, string $key, string $default=''): string => match ($key) {
				'register' => 'reg-1',
				default => '',
			}
		);
		$this->container->expects($this->never())->method('get');
		$listener = new DealUpdatedListener(
			schemaMapService: $this->schemaMap,
			dealService: $this->deals,
			container: $this->container,
			appConfig: $appConfig,
			deferral: $this->deferral,
			logger: $this->logger
		);

		$listener->runDeferredWork(entry: ['uuid' => 'lead-1', 'oldData' => []]);
	}//end testDeferredWorkNeedsTheSchemaConfigured()

	/**
	 * Failing reads and saves are logged with their uuid and not rethrown.
	 *
	 * @return void
	 */
	public function testFailuresAreLogged(): void {
		$this->objects(found: new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: failed to re-read deal for deferred forecast_category correction',
			['exception' => 'db down', 'uuid' => 'lead-1']
		);

		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1', 'oldData' => []]);
	}//end testFailuresAreLogged()

	/**
	 * A failing save of a reset is logged.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLogged(): void {
		$this->objects(found: $this->entity(schema: 'lead-schema', uuid: 'lead-1'), onSave: new RuntimeException('locked'));
		$this->deals->method('validateTransition')->willReturn(value: null);
		$this->deals->method('applyReopenReset')->willReturn(value: ['forecast_category' => 'pipeline']);
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: failed to persist forecast_category correction on deal update',
			['exception' => 'locked', 'uuid' => 'lead-1']
		);

		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1', 'oldData' => []]);
	}//end testAFailingSaveIsLogged()
	/**
	 * A slip without a reason is undone by restoring the old close date, not the category.
	 *
	 * @return void
	 */
	public function testDeferredWorkRevertsASlipWithoutAReason(): void {
		$objects = $this->objects(found: $this->entity(schema: 'lead-schema', uuid: 'lead-1', data: ['forecast_category' => 'commit', 'expectedCloseDate' => '2026-07-15']));
		$this->deals->method('validateTransition')->willReturn(value: 'forecast.error.slip_reason_required');
		$this->deals->expects($this->never())->method('applySlip');

		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1', 'oldData' => ['forecast_category' => 'best_case', 'expectedCloseDate' => '2026-06-30']]);

		self::assertSame(
			expected: [['id' => 'lead-1', 'forecast_category' => 'commit', 'expectedCloseDate' => '2026-06-30']],
			actual: array_column(array: $objects->saves, column_key: 'object')
		);
	}//end testDeferredWorkRevertsASlipWithoutAReason()

	/**
	 * A valid slip is deferred and its record saved.
	 *
	 * @return void
	 */
	public function testAValidSlipIsDeferredAndSaved(): void {
		$this->deals->method('validateTransition')->willReturn(value: null);
		$this->deals->method('applyReopenReset')->willReturn(value: null);
		$this->deals->method('applySlip')->willReturn(value: ['expectedCloseDate' => '2026-07-15', 'slipped_from' => '2026-06-30']);

		$this->listener->handle(event: $this->update(old: ['expectedCloseDate' => '2026-06-30'], new: ['expectedCloseDate' => '2026-07-15']));
		self::assertCount(expectedCount: 1, haystack: $this->deferral->entries);

		$objects = $this->objects(found: $this->entity(schema: 'lead-schema', uuid: 'lead-1', data: ['expectedCloseDate' => '2026-07-15']));
		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1', 'oldData' => ['expectedCloseDate' => '2026-06-30']]);

		self::assertSame(
			expected: [['expectedCloseDate' => '2026-07-15', 'slipped_from' => '2026-06-30']],
			actual: array_column(array: $objects->saves, column_key: 'object')
		);
	}//end testAValidSlipIsDeferredAndSaved()
}//end class
