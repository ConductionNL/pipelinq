<?php

/**
 * Unit tests for CostReconciliationService::reconcile(): which channel
 * messages pending a currency conversion are converted to EUR and saved, the
 * full-scan fallback when the pending filter cannot be queried, and failures.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CostReconciliationService;
use OCA\Pipelinq\Service\MessagingExchangeRateService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Deferred currency reconciliation of message costs.
 *
 * @covers \OCA\Pipelinq\Service\CostReconciliationService
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
final class CostReconciliationServiceTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock exchange rates.
	 *
	 * @var MessagingExchangeRateService&MockObject
	 */
	private MessagingExchangeRateService&MockObject $rates;

	/**
	 * The object service double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Whether OpenRegister resolves.
	 *
	 * @var boolean
	 */
	private bool $openRegister = true;

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Set up shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rates = $this->createMock(MessagingExchangeRateService::class);
		$this->rates->method('toEur')->willReturnCallback(
			static fn (float $amount, string $currency): ?float => match ($currency) {
				'USD' => $amount * 0.9,
				'GBP' => $amount * 1.1666666666,
				default => null,
			}
		);
		$this->openRegister = true;
		$this->config = [];
		$this->objects = new class {
			/** @var list<mixed> */
			public array $rows = [];

			/** @var list<array<string, mixed>> */
			public array $queries = [];

			/** @var list<\Throwable|null> Failure per findAll call, in order */
			public array $findAllFails = [];

			/** @var list<array<string, mixed>> */
			public array $saves = [];

			/** @var list<string> */
			public array $saveFailsFor = [];

			/**
			 * Find rows.
			 *
			 * @param array<string, mixed> $config The query
			 *
			 * @return list<mixed>
			 */
			public function findAll(array $config = []): array {
				$this->queries[] = $config;
				$fail = array_shift($this->findAllFails);
				if ($fail !== null) {
					throw $fail;
				}

				return $this->rows;
			}

			/**
			 * Save a row.
			 *
			 * @param array<string, mixed> $object The payload
			 * @param mixed $register The register
			 * @param mixed $schema The schema
			 * @param string|null $uuid The uuid
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null): array {
				if (in_array((string)$uuid, $this->saveFailsFor, true) === true) {
					throw new RuntimeException('locked ' . $uuid);
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $object;
			}
		};

	}//end setUp()

	/**
	 * Build the service.
	 *
	 * @return CostReconciliationService
	 */
	private function service(): CostReconciliationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService' && $this->openRegister === true) {
					return $this->objects;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return new CostReconciliationService($container, $appConfig, $this->rates, $this->logger);

	}//end service()

	/**
	 * A pending message row.
	 *
	 * @param string $id The id
	 * @param array<string, mixed> $metadata Metadata overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function pending(string $id, array $metadata = []): array {
		return [
			'id' => $id,
			'channel' => 'sms',
			'metadata' => array_merge(['costCurrencyPending' => true, 'costCurrency' => 'USD', 'costSourceAmount' => 0.1], $metadata),
		];

	}//end pending()

	/**
	 * Pending rows with a convertible amount get costEur and leave the pending state.
	 *
	 * @return void
	 */
	public function testPendingRowsAreConverted(): void {
		$this->objects->rows = [self::pending('m-1'), self::pending('m-2', ['costCurrency' => 'GBP', 'costSourceAmount' => '3'])];

		$result = $this->service()->reconcile();

		$this->assertSame(['scanned' => 2, 'reconciled' => 2], $result);
		$this->assertSame(
			[['filters' => ['metadata.costCurrencyPending' => true, 'register' => 'pipelinq', 'schema' => 'channelMessage']]],
			$this->objects->queries
		);
		$first = $this->objects->saves[0];
		$this->assertSame('m-1', $first['uuid']);
		$this->assertSame('pipelinq', $first['register']);
		$this->assertSame('channelMessage', $first['schema']);
		$this->assertSame(0.09, $first['object']['costEur']);
		$this->assertSame('sms', $first['object']['channel']);
		$this->assertFalse($first['object']['metadata']['costCurrencyPending']);
		$this->assertSame('USD', $first['object']['metadata']['costCurrency']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $first['object']['metadata']['costReconciledAt']);
		$this->assertSame(3.5, $this->objects->saves[1]['object']['costEur']);

	}//end testPendingRowsAreConverted()

	/**
	 * Rows not pending are not scanned; pending rows without currency, amount or rate are scanned but not saved.
	 *
	 * @return void
	 */
	public function testUnconvertibleRowsAreScannedButNotSaved(): void {
		$this->objects->rows = [
			['id' => 'm-0', 'metadata' => ['costCurrencyPending' => false]],
			['id' => 'm-x'],
			self::pending('m-1', ['costCurrency' => '']),
			self::pending('m-2', ['costSourceAmount' => 0]),
			self::pending('m-3', ['costSourceAmount' => -2]),
			self::pending('m-4', ['costCurrency' => 'XYZ']),
			self::pending('m-5'),
		];

		$result = $this->service()->reconcile();

		$this->assertSame(['scanned' => 5, 'reconciled' => 1], $result);
		$this->assertSame(['m-5'], array_column($this->objects->saves, 'uuid'));

	}//end testUnconvertibleRowsAreScannedButNotSaved()

	/**
	 * The id comes from uuid, id, slug or @self; without one the row is saved as new.
	 *
	 * @return void
	 */
	public function testIdResolution(): void {
		$row = self::pending('');
		unset($row['id']);
		$this->objects->rows = [
			['uuid' => 'u-1'] + $row,
			['slug' => 'msg-1'] + $row,
			['@self' => ['id' => 's-1']] + $row,
			$row,
		];

		$this->service()->reconcile();

		$this->assertSame(['u-1', 'msg-1', 's-1', null], array_column($this->objects->saves, 'uuid'));

	}//end testIdResolution()

	/**
	 * When the pending filter fails the whole schema is scanned and still only pending rows are converted.
	 *
	 * @return void
	 */
	public function testFailingFilterFallsBackToFullScan(): void {
		$this->config = ['register' => 'crm', 'message_schema' => 'messages'];
		$this->objects->findAllFails = [new RuntimeException('no metadata index')];
		$this->objects->rows = [self::pending('m-1'), ['id' => 'm-2', 'metadata' => []]];
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'CostReconciliationService.reconcile: findAll failed — falling back to full scan',
				['exception' => 'no metadata index']
			);

		$result = $this->service()->reconcile();

		$this->assertSame(['scanned' => 1, 'reconciled' => 1], $result);
		$this->assertSame(
			[
				['filters' => ['metadata.costCurrencyPending' => true, 'register' => 'crm', 'schema' => 'messages']],
				['filters' => ['register' => 'crm', 'schema' => 'messages']],
			],
			$this->objects->queries
		);
		$this->assertSame('crm', $this->objects->saves[0]['register']);
		$this->assertSame('messages', $this->objects->saves[0]['schema']);

	}//end testFailingFilterFallsBackToFullScan()

	/**
	 * When the full scan fails too, nothing is reported.
	 *
	 * @return void
	 */
	public function testFailingFullScanReportsNothing(): void {
		$this->objects->findAllFails = [new RuntimeException('a'), new RuntimeException('b')];
		$this->objects->rows = [self::pending('m-1')];

		$this->assertSame(['scanned' => 0, 'reconciled' => 0], $this->service()->reconcile());
		$this->assertSame([], $this->objects->saves);

	}//end testFailingFullScanReportsNothing()

	/**
	 * A failed save is logged and not counted; the next row is still saved.
	 *
	 * @return void
	 */
	public function testFailedSaveIsLoggedAndNotCounted(): void {
		$this->objects->rows = [self::pending('m-1'), self::pending('m-2')];
		$this->objects->saveFailsFor = ['m-1'];
		$this->logger->expects($this->once())
			->method('warning')
			->with('CostReconciliationService.reconcile: save failed', ['id' => 'm-1', 'exception' => 'locked m-1']);

		$this->assertSame(['scanned' => 2, 'reconciled' => 1], $this->service()->reconcile());
		$this->assertSame(['m-2'], array_column($this->objects->saves, 'uuid'));

	}//end testFailedSaveIsLoggedAndNotCounted()

	/**
	 * Without OpenRegister nothing is scanned.
	 *
	 * @return void
	 */
	public function testNoOpenRegister(): void {
		$this->openRegister = false;
		$this->logger->expects($this->once())
			->method('warning')
			->with('CostReconciliationService.getObjectService: OpenRegister unavailable');

		$this->assertSame(['scanned' => 0, 'reconciled' => 0], $this->service()->reconcile());
		$this->assertSame([], $this->objects->queries);

	}//end testNoOpenRegister()
}//end class
