<?php

/**
 * Unit tests for DealCreatedListener.
 *
 * Asserts which created objects queue deferred work (leads only, with a uuid,
 * not while their own deferred pass runs), and what the deferred pass does:
 * re-read the deal, default its forecast category, and save it back, with
 * every unconfigured or failing step ending quietly.
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
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Listener\DealCreatedListener;
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
 * Tests for DealCreatedListener.
 *
 * @covers \OCA\Pipelinq\Listener\DealCreatedListener
 * @uses   \OCA\Pipelinq\Listener\DeferredWorkGuard
 */
class DealCreatedListenerTest extends TestCase {

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
	 * @var DealCreatedListener
	 */
	private DealCreatedListener $listener;

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
		$this->listener = new DealCreatedListener(
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
	 * A created lead queues one deferred entry, deduplicated by handler and uuid.
	 *
	 * @return void
	 */
	public function testACreatedLeadIsDeferred(): void {
		$this->listener->handle(event: new ObjectCreatedEvent($this->entity(schema: 'schema-lead', uuid: 'lead-1')));

		self::assertSame(expected: [DeferredObjectListenerJob::class], actual: $this->deferral->jobClasses);
		self::assertSame(expected: [['handler' => 'deal-created', 'uuid' => 'lead-1']], actual: $this->deferral->entries);
		self::assertSame(expected: ['deal-created|lead-1'], actual: $this->deferral->dedupeKeys);
	}//end testACreatedLeadIsDeferred()

	/**
	 * Other events, other schemas, a lead without a uuid and a lead whose own pass is running queue nothing.
	 *
	 * @return void
	 */
	public function testNothingElseIsDeferred(): void {
		$this->listener->handle(event: new Event());
		$this->listener->handle(event: new ObjectCreatedEvent($this->entity(schema: 'schema-client', uuid: 'c-1')));
		$this->listener->handle(event: new ObjectCreatedEvent($this->entity(schema: 'schema-lead', uuid: '')));
		DeferredWorkGuard::enter(key: DeferredWorkGuard::key(handler: 'deal-created', uuid: 'lead-2'));
		$this->listener->handle(event: new ObjectCreatedEvent($this->entity(schema: 'schema-lead', uuid: 'lead-2')));

		self::assertSame(expected: [], actual: $this->deferral->entries);
	}//end testNothingElseIsDeferred()

	/**
	 * The deferred pass re-reads the deal and saves the defaulted category back.
	 *
	 * @return void
	 */
	public function testDeferredWorkSavesTheDefaultedDeal(): void {
		$objects = $this->objects(found: $this->entity(schema: 'lead-schema', uuid: 'lead-1', data: ['title' => 'Deal']));
		$this->deals->expects($this->once())->method('applyDefaultCategory')
			->with(['title' => 'Deal', 'id' => 'lead-1'])
			->willReturn(value: ['title' => 'Deal', 'forecast_category' => 'pipeline']);

		$this->listener->runDeferredWork(entry: ['handler' => 'deal-created', 'uuid' => 'lead-1']);

		self::assertSame(expected: [['id' => 'lead-1', 'register' => 'reg-1', 'schema' => 'lead-schema']], actual: $objects->finds);
		self::assertSame(
			expected: [[
				'object' => ['title' => 'Deal', 'forecast_category' => 'pipeline'],
				'extend' => [],
				'register' => 'reg-1',
				'schema' => 'lead-schema',
				'uuid' => 'lead-1',
			]],
			actual: $objects->saves
		);
	}//end testDeferredWorkSavesTheDefaultedDeal()

	/**
	 * A deal that already has a category is not saved again.
	 *
	 * @return void
	 */
	public function testDeferredWorkSkipsADealThatNeedsNoDefault(): void {
		$objects = $this->objects(found: $this->entity(schema: 'lead-schema', uuid: 'lead-1', data: ['forecast_category' => 'commit']));
		$this->deals->method('applyDefaultCategory')->willReturn(value: null);

		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1']);

		self::assertCount(expectedCount: 1, haystack: $objects->finds);
		self::assertSame(expected: [], actual: $objects->saves);
	}//end testDeferredWorkSkipsADealThatNeedsNoDefault()

	/**
	 * A deal that no longer exists, or an entry without a uuid, does nothing.
	 *
	 * @return void
	 */
	public function testDeferredWorkStopsWithoutADeal(): void {
		$objects = $this->objects(found: null);
		$this->deals->expects($this->never())->method('applyDefaultCategory');

		$this->listener->runDeferredWork(entry: ['uuid' => 'gone']);
		$this->listener->runDeferredWork(entry: []);

		self::assertSame(expected: ['gone'], actual: array_column(array: $objects->finds, column_key: 'id'));
	}//end testDeferredWorkStopsWithoutADeal()

	/**
	 * Without a configured register the deferred pass never reaches the object service.
	 *
	 * @return void
	 */
	public function testDeferredWorkNeedsTheRegisterConfigured(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(value: '');
		$this->container->expects($this->never())->method('get');
		$this->deals->expects($this->never())->method('applyDefaultCategory');
		$listener = new DealCreatedListener(
			schemaMapService: $this->schemaMap,
			dealService: $this->deals,
			container: $this->container,
			appConfig: $appConfig,
			deferral: $this->deferral,
			logger: $this->logger
		);

		$listener->runDeferredWork(entry: ['uuid' => 'lead-1']);
	}//end testDeferredWorkNeedsTheRegisterConfigured()

	/**
	 * A failing re-read is logged with its uuid and stops the pass.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLogged(): void {
		$this->objects(found: new RuntimeException('db down'));
		$this->deals->expects($this->never())->method('applyDefaultCategory');
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: failed to re-read deal for deferred forecast_category default',
			['exception' => 'db down', 'uuid' => 'lead-1']
		);

		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1']);
	}//end testAFailingReadIsLogged()

	/**
	 * A failing save is logged with its uuid and not rethrown.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLogged(): void {
		$this->objects(found: $this->entity(schema: 'lead-schema', uuid: 'lead-1'), onSave: new RuntimeException('locked'));
		$this->deals->method('applyDefaultCategory')->willReturn(value: ['forecast_category' => 'pipeline']);
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: failed to default forecast_category on new deal',
			['exception' => 'locked', 'uuid' => 'lead-1']
		);

		$this->listener->runDeferredWork(entry: ['uuid' => 'lead-1']);
	}//end testAFailingSaveIsLogged()
}//end class
