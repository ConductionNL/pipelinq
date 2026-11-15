<?php

/**
 * Unit tests for CostReconciliationService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\CostReconciliationService;
use OCA\Pipelinq\Service\MessagingExchangeRateService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The daily sweep that converts messages whose cost waited for an exchange
 * rate: which rows it scans, which it converts, how it saves them and how it
 * degrades when OpenRegister or a save fails.
 *
 * @covers \OCA\Pipelinq\Service\CostReconciliationService
 * @uses   \OCA\Pipelinq\Service\MessagingExchangeRateService
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class CostReconciliationServiceTest extends TestCase {
	/** @var ContainerInterface&MockObject */
	private ContainerInterface $container;

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var ObjectServiceInterface&MockObject */
	private ObjectServiceInterface $objectService;

	/** @var array<string,string> */
	private array $config = [
		'messaging.exchange_rates_json' => '{"USD":0.5,"GBP":1.25}',
	];

	/**
	 * Wire the mocks; the container hands out the object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			fn (string $id) => $id === 'OCA\\OpenRegister\\Service\\ObjectService' ? $this->objectService : throw new RuntimeException('unknown ' . $id)
		);
	}//end setUp()

	/**
	 * The service under test, with the real exchange-rate service on the mocked config.
	 *
	 * @return CostReconciliationService
	 */
	private function service(): CostReconciliationService {
		return new CostReconciliationService(
			$this->container,
			$this->appConfig,
			new MessagingExchangeRateService($this->appConfig, $this->logger),
			$this->logger,
		);
	}//end service()

	/**
	 * A pending-cost message row.
	 *
	 * @param array<string,mixed> $meta  Extra metadata.
	 * @param array<string,mixed> $extra Extra top-level fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function pending(array $meta, array $extra = []): array {
		return $extra + ['metadata' => $meta + ['costCurrencyPending' => true]];
	}//end pending()

	/**
	 * Record every saveObject call.
	 *
	 * @return array<int,array<string,mixed>> Filled by reference.
	 */
	private function recordSaves(): array {
		$this->saves = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		return $this->saves;
	}//end recordSaves()

	/** @var array<int,array<string,mixed>> */
	private array $saves = [];

	/**
	 * The sweep asks for pending rows in the default register and message schema.
	 *
	 * @return void
	 */
	public function testReconcileQueriesPendingRowsInTheDefaultSchema(): void {
		$this->objectService->expects(self::once())->method('findAll')->with(
			[
				'filters' => [
					'metadata.costCurrencyPending' => true,
					'register' => 'pipelinq',
					'schema' => 'channelMessage',
				],
			]
		)->willReturn([]);

		self::assertSame(['scanned' => 0, 'reconciled' => 0], $this->service()->reconcile());
	}//end testReconcileQueriesPendingRowsInTheDefaultSchema()

	/**
	 * A converted row gets costEur, a cleared flag and a reconciled stamp, and is
	 * saved under its id in the configured register and schema.
	 *
	 * @return void
	 */
	public function testReconcileConvertsAndSavesUnderTheRowId(): void {
		$this->config['register'] = 'crm';
		$this->config['message_schema'] = 'msg';
		$this->objectService->method('findAll')->willReturn(
			[self::pending(['costCurrency' => 'usd', 'costSourceAmount' => '0.0123457', 'provider' => 'x'], ['uuid' => 'm-1', 'body' => 'Hi'])]
		);
		$this->recordSaves();

		$summary = $this->service()->reconcile();

		self::assertSame(['scanned' => 1, 'reconciled' => 1], $summary);
		self::assertCount(1, $this->saves);
		$save = $this->saves[0];
		self::assertSame('m-1', $save['uuid']);
		self::assertSame('crm', $save['register']);
		self::assertSame('msg', $save['schema']);
		self::assertSame(0.006173, $save['object']['costEur']);
		self::assertSame('Hi', $save['object']['body']);
		self::assertFalse($save['object']['metadata']['costCurrencyPending']);
		self::assertSame('x', $save['object']['metadata']['provider']);
		self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $save['object']['metadata']['costReconciledAt']);
	}//end testReconcileConvertsAndSavesUnderTheRowId()

	/**
	 * Rows that are not pending are not scanned; pending rows without a currency,
	 * with a zero amount or without a rate are scanned but left alone.
	 *
	 * @return void
	 */
	public function testReconcileSkipsRowsItCannotConvert(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				['metadata' => ['costCurrencyPending' => false, 'costCurrency' => 'USD', 'costSourceAmount' => 1]],
				['id' => 'no-meta'],
				self::pending(['costSourceAmount' => 1]),
				self::pending(['costCurrency' => 'USD', 'costSourceAmount' => 0]),
				self::pending(['costCurrency' => 'USD', 'costSourceAmount' => -2]),
				self::pending(['costCurrency' => 'ZZZ', 'costSourceAmount' => 3]),
			]
		);
		$this->objectService->expects(self::never())->method('saveObject');

		self::assertSame(['scanned' => 4, 'reconciled' => 0], $this->service()->reconcile());
	}//end testReconcileSkipsRowsItCannotConvert()

	/**
	 * The id comes from uuid, id or slug, then from @self; with none the row is
	 * saved as new (uuid null). Entities are read through jsonSerialize.
	 *
	 * @return void
	 */
	public function testReconcileResolvesTheIdFromEveryShape(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(self::pending(['costCurrency' => 'GBP', 'costSourceAmount' => 2], ['slug' => 'by-slug']));
		$this->objectService->method('findAll')->willReturn(
			[
				self::pending(['costCurrency' => 'USD', 'costSourceAmount' => 2], ['id' => 42]),
				$entity,
				self::pending(['costCurrency' => 'USD', 'costSourceAmount' => 2], ['uuid' => '', '@self' => ['uuid' => '', 'id' => 'self-id']]),
				self::pending(['costCurrency' => 'EUR', 'costSourceAmount' => 2], ['@self' => 'not-an-array']),
			]
		);
		$this->recordSaves();

		self::assertSame(['scanned' => 4, 'reconciled' => 4], $this->service()->reconcile());
		self::assertSame(['42', 'by-slug', 'self-id', null], array_column($this->saves, 'uuid'));
		self::assertSame([1.0, 2.5, 1.0, 2.0], array_map(fn (array $s) => $s['object']['costEur'], $this->saves));
	}//end testReconcileResolvesTheIdFromEveryShape()

	/**
	 * A failed save is logged with the row id and not counted; the sweep goes on.
	 *
	 * @return void
	 */
	public function testReconcileLogsAFailedSaveAndContinues(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				self::pending(['costCurrency' => 'USD', 'costSourceAmount' => 1], ['id' => 'bad']),
				self::pending(['costCurrency' => 'USD', 'costSourceAmount' => 1], ['id' => 'good']),
			]
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			fn (array $object) => ($object['id'] === 'bad') ? throw new RuntimeException('locked') : $this->createMock(ObjectEntityInterface::class)
		);
		$this->logger->expects(self::once())->method('warning')->with(
			'CostReconciliationService.reconcile: save failed',
			['id' => 'bad', 'exception' => 'locked']
		);

		self::assertSame(['scanned' => 2, 'reconciled' => 1], $this->service()->reconcile());
	}//end testReconcileLogsAFailedSaveAndContinues()

	/**
	 * When the filtered query fails the sweep falls back to scanning the whole
	 * schema, and still only converts the pending rows.
	 *
	 * @return void
	 */
	public function testReconcileFallsBackToAFullScan(): void {
		$calls = [];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$calls): array {
				$calls[] = $config['filters'];
				if (count($calls) === 1) {
					throw new RuntimeException('unknown filter');
				}

				return [
					['id' => 'done', 'metadata' => ['costCurrencyPending' => false]],
					self::pending(['costCurrency' => 'USD', 'costSourceAmount' => 4], ['id' => 'todo']),
				];
			}
		);
		$this->recordSaves();
		$this->logger->expects(self::once())->method('warning')->with(
			'CostReconciliationService.reconcile: findAll failed — falling back to full scan',
			['exception' => 'unknown filter']
		);

		self::assertSame(['scanned' => 1, 'reconciled' => 1], $this->service()->reconcile());
		self::assertSame(['register' => 'pipelinq', 'schema' => 'channelMessage'], $calls[1]);
		self::assertSame(['todo'], array_column($this->saves, 'uuid'));
	}//end testReconcileFallsBackToAFullScan()

	/**
	 * When the full scan fails too the sweep reports nothing done.
	 *
	 * @return void
	 */
	public function testReconcileReportsNothingWhenBothQueriesFail(): void {
		$this->objectService->expects(self::exactly(2))->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->objectService->expects(self::never())->method('saveObject');

		self::assertSame(['scanned' => 0, 'reconciled' => 0], $this->service()->reconcile());
	}//end testReconcileReportsNothingWhenBothQueriesFail()

	/**
	 * Without OpenRegister the sweep logs it and reports nothing done.
	 *
	 * @return void
	 */
	public function testReconcileWithoutOpenRegister(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));
		$this->logger->expects(self::once())->method('warning')->with(
			'CostReconciliationService.getObjectService: OpenRegister unavailable',
			['exception' => 'not installed']
		);
		$service = new CostReconciliationService(
			$container,
			$this->appConfig,
			new MessagingExchangeRateService($this->appConfig, $this->logger),
			$this->logger,
		);

		self::assertSame(['scanned' => 0, 'reconciled' => 0], $service->reconcile());
	}//end testReconcileWithoutOpenRegister()
}//end class
