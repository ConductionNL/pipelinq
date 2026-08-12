<?php

/**
 * Unit tests for PosStockMovedEvent + PosTransactionService::emitStockMovedEvent().
 *
 * Exercises the typed CloudEvent shape and the settle-path emission: sold
 * lines resolved to product SKU/unit (never dropped when unresolvable),
 * administrationId resolution via the shillinq soft-dependency seam, and the
 * fire-and-forget guarantee (a dispatcher / webhook failure never
 * propagates out of emitStockMovedEvent()).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-stock-moved-event/tasks.md#2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Event\PosStockMovedEvent;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosTransactionService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory fake of the OR ObjectService, keyed by schema then uuid.
 *
 * `findAll()` mirrors PosTransactionService::fetchLines()'s exact filter
 * shape: `filters.@self.schema` selects the schema, `filters.transaction`
 * scopes to the parent transaction.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the real ObjectService signature.
 */
class StockMovedFakeObjectService {
	/**
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * @param array<string, mixed> $config
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config): array {
		$filters = (array)($config['filters'] ?? []);
		$self = (array)($filters['@self'] ?? []);
		$schema = (string)($self['schema'] ?? '');
		$rows = array_values($this->store[$schema] ?? []);

		if (isset($filters['transaction']) === true) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn (array $row): bool => ($row['transaction'] ?? null) === $filters['transaction']
				)
			);
		}

		return $rows;
	}//end findAll()

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, string $register, string $schema): ?array {
		return $this->store[$schema][$id] ?? null;
	}//end find()
}//end class

/**
 * In-memory fake of OR WebhookService capturing dispatched events.
 */
class StockMovedFakeWebhookService {
	/**
	 * @var array<int, array{eventName: string, payload: array<string, mixed>}>
	 */
	public array $events = [];

	/**
	 * @var bool
	 */
	public bool $throwOnDispatch = false;

	/**
	 * @param array<string, mixed> $payload
	 */
	public function dispatchEvent(object $_event, string $eventName, array $payload): void {
		if ($this->throwOnDispatch === true) {
			throw new \RuntimeException('fake webhook error');
		}

		$this->events[] = ['eventName' => $eventName, 'payload' => $payload];
	}//end dispatchEvent()
}//end class

/**
 * Fake shillinq AdministrationContextService seam.
 */
class StockMovedFakeAdministrationContextService {
	/**
	 * @param string $administrationId
	 */
	public function __construct(
		private string $administrationId,
	) {
	}//end __construct()

	/**
	 * @return array<string, mixed>
	 */
	public function buildContext(): array {
		return ['activeAdministrationId' => $this->administrationId];
	}//end buildContext()
}//end class

/**
 * Tests for PosStockMovedEvent + the settle-path emission.
 *
 * @spec openspec/changes/pos-stock-moved-event/tasks.md#2
 */
class PosStockMovedEventTest extends TestCase {
	private StockMovedFakeObjectService $objects;

	private StockMovedFakeWebhookService $webhooks;

	private ?object $administrationContextService = null;

	/**
	 * @var array<string, string>
	 */
	private array $appConfigStore = [
		'register' => 'reg',
		'posTransaction_schema' => 'posTransaction',
		'posTransactionLine_schema' => 'posTransactionLine',
		'product_schema' => 'product',
	];

	private $dispatcher;

	/**
	 * Build the service under test wired to the fakes above.
	 *
	 * @return PosTransactionService The wired service.
	 */
	private function buildService(): PosTransactionService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return $this->appConfigStore[$key] ?? $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objects;
				}

				if ($id === 'OCA\\OpenRegister\\Service\\WebhookService') {
					return $this->webhooks;
				}

				if ($id === 'OCA\\Shillinq\\Service\\AdministrationContextService') {
					if ($this->administrationContextService === null) {
						throw new \RuntimeException('shillinq not installed');
					}

					return $this->administrationContextService;
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$policy = $this->createMock(PosAccessPolicy::class);

		return new PosTransactionService(
			container: $container,
			appConfig: $appConfig,
			policy: $policy,
			logger: $this->createMock(LoggerInterface::class),
			eventDispatcher: $this->dispatcher,
		);
	}//end buildService()

	protected function setUp(): void {
		$this->objects = new StockMovedFakeObjectService();
		$this->webhooks = new StockMovedFakeWebhookService();
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
	}//end setUp()

	/**
	 * Seed one posTransaction row.
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function seedTransaction(string $id, array $overrides = []): void {
		$this->objects->store['posTransaction'][$id] = array_merge(
			['id' => $id, 'reference' => 'TXN-2026-0001'],
			$overrides
		);
	}//end seedTransaction()

	/**
	 * Seed one posTransactionLine row.
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function seedLine(string $id, string $transactionId, array $overrides = []): void {
		$this->objects->store['posTransactionLine'][$id] = array_merge(
			['id' => $id, 'transaction' => $transactionId, 'quantity' => 1],
			$overrides
		);
	}//end seedLine()

	/**
	 * Seed one product row.
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function seedProduct(string $id, array $overrides = []): void {
		$this->objects->store['product'][$id] = array_merge(['id' => $id], $overrides);
	}//end seedProduct()

	// -------------------------------------------------------------------
	// PosStockMovedEvent::toCloudEvent()
	// -------------------------------------------------------------------

	/**
	 * toCloudEvent() builds the nl.pipelinq.pos.stock.moved CloudEvents 1.0 envelope.
	 *
	 * @return void
	 */
	public function testToCloudEventBuildsEnvelope(): void {
		$event = new PosStockMovedEvent(
			eventId: 'evt-1',
			transactionUuid: 'tx-1',
			transactionReference: 'TXN-2026-0003',
			administrationId: 'admin-1',
			lines: [
				['productRef' => 'SKU-1001', 'qty' => 3.0, 'unit' => 'pcs', 'location' => ''],
			],
			emittedAt: '2026-07-23T10:00:00+00:00',
		);

		$cloudEvent = $event->toCloudEvent();

		$this->assertSame('1.0', $cloudEvent['specversion']);
		$this->assertSame('nl.pipelinq.pos.stock.moved', $cloudEvent['type']);
		$this->assertSame('/apps/pipelinq/pos/stock', $cloudEvent['source']);
		$this->assertSame('evt-1', $cloudEvent['id']);
		$this->assertSame('tx-1', $cloudEvent['data']['posTxnId']);
		$this->assertSame('TXN-2026-0003', $cloudEvent['data']['transactionReference']);
		$this->assertSame('admin-1', $cloudEvent['data']['administrationId']);
		$this->assertCount(1, $cloudEvent['data']['lines']);
		$this->assertSame('SKU-1001', $cloudEvent['data']['lines'][0]['productRef']);
		$this->assertSame(3.0, $cloudEvent['data']['lines'][0]['qty']);
		$this->assertSame('pcs', $cloudEvent['data']['lines'][0]['unit']);
	}//end testToCloudEventBuildsEnvelope()

	/**
	 * Getter accessors expose the constructor values (used by future listeners
	 * that consume the typed event directly rather than the CloudEvent shape).
	 *
	 * @return void
	 */
	public function testGettersExposeConstructorValues(): void {
		$event = new PosStockMovedEvent(
			eventId: 'evt-2',
			transactionUuid: 'tx-2',
			transactionReference: 'TXN-2026-0004',
			administrationId: 'admin-2',
			lines: [['productRef' => 'SKU-2002', 'qty' => 1.0, 'unit' => 'pcs', 'location' => '']],
			emittedAt: '2026-07-23T10:05:00+00:00',
		);

		$this->assertSame('evt-2', $event->getEventId());
		$this->assertSame('tx-2', $event->getTransactionUuid());
		$this->assertSame('TXN-2026-0004', $event->getTransactionReference());
		$this->assertSame('admin-2', $event->getAdministrationId());
		$this->assertSame('2026-07-23T10:05:00+00:00', $event->getEmittedAt());
		$this->assertCount(1, $event->getLines());
	}//end testGettersExposeConstructorValues()

	// -------------------------------------------------------------------
	// PosTransactionService::emitStockMovedEvent()
	// -------------------------------------------------------------------

	/**
	 * Settling with two sold lines emits one event carrying both lines mapped
	 * to their product's SKU/unit, plus posTxnId + administrationId.
	 *
	 * @return void
	 */
	public function testEmitStockMovedEventMapsAllSoldLinesToSku(): void {
		$this->seedTransaction('tx-1');
		$this->seedLine('line-1', 'tx-1', ['product' => 'prod-1', 'quantity' => 3]);
		$this->seedLine('line-2', 'tx-1', ['product' => 'prod-2', 'quantity' => 1]);
		$this->seedProduct('prod-1', ['sku' => 'SKU-1001', 'unit' => 'pcs']);
		$this->seedProduct('prod-2', ['sku' => 'SKU-2002', 'unit' => 'box']);

		$this->administrationContextService = new StockMovedFakeAdministrationContextService('admin-42');

		$capturedTyped = null;
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event) use (&$capturedTyped): void {
				$capturedTyped = $event;
			}
		);

		$service = $this->buildService();
		$eventId = $service->emitStockMovedEvent(transactionId: 'tx-1');

		$this->assertNotSame('', $eventId);
		$this->assertInstanceOf(PosStockMovedEvent::class, $capturedTyped);
		$this->assertSame('admin-42', $capturedTyped->getAdministrationId());
		$this->assertCount(2, $capturedTyped->getLines());

		$this->assertCount(1, $this->webhooks->events);
		$payload = $this->webhooks->events[0]['payload'];
		$this->assertSame('nl.pipelinq.pos.stock.moved', $this->webhooks->events[0]['eventName']);
		$this->assertSame('tx-1', $payload['data']['posTxnId']);
		$this->assertSame('admin-42', $payload['data']['administrationId']);

		$refs = array_column($payload['data']['lines'], 'productRef');
		$this->assertContains('SKU-1001', $refs);
		$this->assertContains('SKU-2002', $refs);
	}//end testEmitStockMovedEventMapsAllSoldLinesToSku()

	/**
	 * A line whose product has no resolvable sku is still carried in the
	 * payload with an empty productRef — never silently dropped.
	 *
	 * @return void
	 */
	public function testUnresolvableProductSkuIsCarriedNotDropped(): void {
		$this->seedTransaction('tx-2');
		// Line references a product id that does not exist in the store.
		$this->seedLine('line-1', 'tx-2', ['product' => 'ghost-product', 'quantity' => 2]);

		$service = $this->buildService();
		$eventId = $service->emitStockMovedEvent(transactionId: 'tx-2');

		$this->assertNotSame('', $eventId);
		$this->assertCount(1, $this->webhooks->events);

		$lines = $this->webhooks->events[0]['payload']['data']['lines'];
		$this->assertCount(1, $lines);
		$this->assertSame('', $lines[0]['productRef']);
		$this->assertSame(2.0, $lines[0]['qty']);
	}//end testUnresolvableProductSkuIsCarriedNotDropped()

	/**
	 * No sold lines -> no event emitted (empty string, no webhook call).
	 *
	 * @return void
	 */
	public function testNoLinesEmitsNothing(): void {
		$this->seedTransaction('tx-3');

		$service = $this->buildService();
		$eventId = $service->emitStockMovedEvent(transactionId: 'tx-3');

		$this->assertSame('', $eventId);
		$this->assertCount(0, $this->webhooks->events);
	}//end testNoLinesEmitsNothing()

	/**
	 * An unresolvable transaction id yields an empty string (never throws).
	 *
	 * @return void
	 */
	public function testUnknownTransactionReturnsEmptyString(): void {
		$service = $this->buildService();
		$eventId = $service->emitStockMovedEvent(transactionId: 'does-not-exist');

		$this->assertSame('', $eventId);
	}//end testUnknownTransactionReturnsEmptyString()

	/**
	 * A typed-dispatch failure never propagates — the webhook fallback still
	 * fires and the event id is still returned (fire-and-forget).
	 *
	 * @return void
	 */
	public function testTypedDispatchFailureFallsBackToWebhook(): void {
		$this->seedTransaction('tx-4');
		$this->seedLine('line-1', 'tx-4', ['product' => 'prod-1', 'quantity' => 1]);
		$this->seedProduct('prod-1', ['sku' => 'SKU-9', 'unit' => 'pcs']);

		$this->dispatcher->method('dispatchTyped')->willThrowException(new \RuntimeException('no listener'));

		$service = $this->buildService();
		$eventId = $service->emitStockMovedEvent(transactionId: 'tx-4');

		$this->assertNotSame('', $eventId);
		$this->assertCount(1, $this->webhooks->events);
	}//end testTypedDispatchFailureFallsBackToWebhook()

	/**
	 * A webhook dispatch failure never propagates out of emitStockMovedEvent()
	 * — settleTransaction()'s caller wraps this in its own try/catch too, but
	 * the method itself must not throw for a downstream-unavailable case.
	 *
	 * @return void
	 */
	public function testWebhookDispatchFailureDoesNotThrow(): void {
		$this->seedTransaction('tx-5');
		$this->seedLine('line-1', 'tx-5', ['product' => 'prod-1', 'quantity' => 1]);
		$this->seedProduct('prod-1', ['sku' => 'SKU-9', 'unit' => 'pcs']);

		$this->webhooks->throwOnDispatch = true;

		$service = $this->buildService();
		$eventId = $service->emitStockMovedEvent(transactionId: 'tx-5');

		// Typed dispatch still succeeded so an id was generated and returned;
		// the failure is confined to the webhook fallback branch.
		$this->assertNotSame('', $eventId);
	}//end testWebhookDispatchFailureDoesNotThrow()

	/**
	 * AdministrationId falls back to 'default' when shillinq is not installed.
	 *
	 * @return void
	 */
	public function testAdministrationIdFallsBackToDefaultWhenShillinqUnavailable(): void {
		$this->seedTransaction('tx-6');
		$this->seedLine('line-1', 'tx-6', ['product' => 'prod-1', 'quantity' => 1]);
		$this->seedProduct('prod-1', ['sku' => 'SKU-9', 'unit' => 'pcs']);

		// AdministrationContextService left null -> container->get() throws.
		$service = $this->buildService();
		$service->emitStockMovedEvent(transactionId: 'tx-6');

		$payload = $this->webhooks->events[0]['payload'];
		$this->assertSame('default', $payload['data']['administrationId']);
	}//end testAdministrationIdFallsBackToDefaultWhenShillinqUnavailable()
}//end class
