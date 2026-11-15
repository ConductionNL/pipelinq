<?php

/**
 * ShillinqStockReader: the stock of one product, read from shillinq.
 *
 * The rows are shaped as shillinq's InventoryStock schema declares them
 * (shillinq lib/Settings/register.d/inventory-stock-tracking.json): one per
 * product and location, with quantityOnHand, quantityReserved and the computed
 * quantityAvailable.
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
 * @spec openspec/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ShillinqStockReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ShillinqStockReader coverage.
 */
class ShillinqStockReaderTest extends TestCase {

	/**
	 * A reader over the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The stock rows shillinq holds.
	 * @param bool $installed Whether shillinq is installed.
	 * @param bool $denied Whether OpenRegister refuses the read.
	 *
	 * @return array{0: ShillinqStockReader, 1: object}
	 */
	private function reader(array $rows, bool $installed = true, bool $denied = false): array {
		$objectService = new class ($rows, $denied) {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $rows Rows.
			 * @param bool $denied Refuse the read.
			 */
			public function __construct(private array $rows, private bool $denied) {
			}

			/**
			 * OR's findAll(array $config), RBAC on by default.
			 *
			 * @param array<string, mixed> $config Config.
			 * @param bool $_rbac RBAC.
			 * @param bool $_multitenancy Multitenancy.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['config' => $config, '_rbac' => $_rbac];
				if ($this->denied === true) {
					throw new \RuntimeException('not authorized to read InventoryStock');
				}

				return $this->rows;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$reader = new class ($installed, $container, $this->createMock(LoggerInterface::class)) extends ShillinqStockReader {
			/**
			 * Constructor.
			 *
			 * @param bool $installed Answer for the probe.
			 * @param ContainerInterface $container Container.
			 * @param LoggerInterface $logger Logger.
			 */
			public function __construct(private bool $installed, ContainerInterface $container, LoggerInterface $logger) {
				parent::__construct($container, $logger);
			}

			/**
			 * The probe answer.
			 *
			 * @return bool
			 */
			protected function probe(): bool {
				return $this->installed;
			}
		};

		return [$reader, $objectService];
	}//end reader()

	/**
	 * Two locations are summed and listed, read as the user.
	 *
	 * @return void
	 */
	public function testTwoLocationsAreSummed(): void {
		[$reader, $objectService] = $this->reader([
			['productId' => 'AFV-PAS-001', 'locationCode' => 'AMS', 'locationName' => 'Amsterdam', 'quantityOnHand' => 1300, 'quantityReserved' => 100, 'quantityAvailable' => 1200],
			['productId' => 'AFV-PAS-001', 'locationCode' => 'UTR', 'locationName' => 'Utrecht', 'quantityOnHand' => 660, 'quantityReserved' => 0, 'quantityAvailable' => 660],
		]);

		$stock = $reader->stockFor('AFV-PAS-001');

		$this->assertSame('ok', $stock['state']);
		$this->assertSame(1860.0, $stock['available']);
		$this->assertSame(1960.0, $stock['onHand']);
		$this->assertSame(100.0, $stock['reserved']);
		$this->assertSame(['Amsterdam', 'Utrecht'], array_column($stock['locations'], 'name'));
		$this->assertSame(['register' => 'shillinq', 'schema' => 'InventoryStock', 'productId' => 'AFV-PAS-001'], $objectService->calls[0]['config']['filters']);
		$this->assertSame(50, $objectService->calls[0]['config']['limit']);
		$this->assertTrue($objectService->calls[0]['_rbac'], 'the read runs as the user');
	}//end testTwoLocationsAreSummed()

	/**
	 * Without a computed quantityAvailable, available is on hand minus reserved.
	 *
	 * @return void
	 */
	public function testAvailableFallsBackToOnHandMinusReserved(): void {
		[$reader] = $this->reader([
			['productId' => 'P-1', 'locationCode' => 'AMS', 'quantityOnHand' => 100, 'quantityReserved' => 30],
		]);

		$stock = $reader->stockFor('P-1');

		$this->assertSame(70.0, $stock['available']);
		$this->assertSame('AMS', $stock['locations'][0]['name']);
	}//end testAvailableFallsBackToOnHandMinusReserved()

	/**
	 * A row for another product (an ignored filter) is not counted.
	 *
	 * @return void
	 */
	public function testARowOfAnotherProductIsNotCounted(): void {
		[$reader] = $this->reader([
			['productId' => 'P-1', 'quantityOnHand' => 5, 'quantityReserved' => 0, 'quantityAvailable' => 5],
			['productId' => 'P-2', 'quantityOnHand' => 900, 'quantityReserved' => 0, 'quantityAvailable' => 900],
		]);

		$this->assertSame(5.0, $reader->stockFor('P-1')['available']);
	}//end testARowOfAnotherProductIsNotCounted()

	/**
	 * A seeded row keyed on the SKU only is found through the SKU.
	 *
	 * @return void
	 */
	public function testASeededRowIsFoundByItsSku(): void {
		[$reader, $objectService] = $this->reader([
			['productSku' => 'TONER-HP-CF283A', 'locationCode' => 'WH-AMS-001', 'locationName' => 'Amsterdam Warehouse', 'quantityOnHand' => 150, 'quantityReserved' => 20],
		]);

		$stock = $reader->stockFor('', 'TONER-HP-CF283A');

		$this->assertSame(130.0, $stock['available']);
		$this->assertSame(['register' => 'shillinq', 'schema' => 'InventoryStock', 'productSku' => 'TONER-HP-CF283A'], $objectService->calls[0]['config']['filters']);
	}//end testASeededRowIsFoundByItsSku()

	/**
	 * Without shillinq the answer is empty and nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutShillinqNothingIsRead(): void {
		[$reader, $objectService] = $this->reader([['productId' => 'P-1', 'quantityAvailable' => 5]], installed: false);

		$stock = $reader->stockFor('P-1');

		$this->assertSame('no-shillinq', $stock['state']);
		$this->assertSame([], $objectService->calls);
	}//end testWithoutShillinqNothingIsRead()

	/**
	 * A refused read is no access, not zero stock.
	 *
	 * @return void
	 */
	public function testARefusedReadIsNoAccess(): void {
		[$reader] = $this->reader([], denied: true);

		$this->assertSame('no-access', $reader->stockFor('P-1')['state']);
	}//end testARefusedReadIsNoAccess()
	/**
	 * Every way a read ends: the id is asked first and an id hit stops the
	 * read; an empty id goes straight to the SKU; a missing object service is
	 * no shillinq; a non-list answer is no stock; a refusal on the SKU read is
	 * no access.
	 *
	 * @return void
	 */
	public function testEveryWayAStockReadEnds(): void {
		[$reader, $objectService] = $this->reader([['productId' => 'P-1', 'productSku' => 'S-1', 'quantityOnHand' => 4, 'locationCode' => 'A']]);
		$stock = $reader->stockFor(' P-1 ', ' S-1 ');
		$this->assertSame(['state' => 'ok', 'available' => 4.0, 'onHand' => 4.0, 'reserved' => 0.0, 'locations' => [['code' => 'A', 'name' => 'A', 'available' => 4.0]]], $stock);
		$this->assertCount(1, $objectService->calls);
		$this->assertSame(['filters' => ['register' => 'shillinq', 'schema' => 'InventoryStock', 'productId' => 'P-1'], 'limit' => 50], $objectService->calls[0]['config']);
		$this->assertTrue($objectService->calls[0]['_rbac']);

		[$reader, $objectService] = $this->reader([['productSku' => 'S-1', 'quantityAvailable' => '2', 'locationName' => 'Hal']]);
		$stock = $reader->stockFor('', 'S-1');
		$this->assertSame([['code' => '', 'name' => 'Hal', 'available' => 2.0]], $stock['locations']);
		$this->assertCount(1, $objectService->calls);
		$this->assertSame('S-1', $objectService->calls[0]['config']['filters']['productSku']);

		[$reader, $objectService] = $this->reader([]);
		$this->assertSame(['state' => 'ok', 'available' => 0.0, 'onHand' => 0.0, 'reserved' => 0.0, 'locations' => []], $reader->stockFor('P-9', 'S-9'));
		$this->assertCount(2, $objectService->calls);
		$this->assertSame(['state' => 'ok', 'available' => 0.0, 'onHand' => 0.0, 'reserved' => 0.0, 'locations' => []], $reader->stockFor('', ''));
		$this->assertCount(2, $objectService->calls);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('ShillinqStockReader: OpenRegister unavailable', ['exception' => 'gone']);
		$this->assertSame('no-shillinq', $this->installed($container, $logger)->stockFor('P-1')['state']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new class {
			/**
			 * A non-list answer for the id, a refusal for the SKU.
			 *
			 * @param array<string, mixed> $config Config.
			 *
			 * @return mixed
			 */
			public function findAll(array $config = []) {
				if (isset($config['filters']['productSku']) === true) {
					throw new \RuntimeException('denied');
				}

				return 'not a list';
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('ShillinqStockReader: the user cannot read shillinq stock', ['product' => 'S-1', 'exception' => 'denied']);
		$this->assertSame(['state' => 'no-access', 'available' => 0.0, 'onHand' => 0.0, 'reserved' => 0.0, 'locations' => []], $this->installed($container, $logger)->stockFor('P-1', 'S-1'));
	}//end testEveryWayAStockReadEnds()

	/**
	 * A reader that sees shillinq installed, over the given container and logger.
	 *
	 * @param ContainerInterface $container Container.
	 * @param LoggerInterface    $logger    Logger.
	 *
	 * @return ShillinqStockReader
	 */
	private function installed(ContainerInterface $container, LoggerInterface $logger): ShillinqStockReader {
		return new class ($container, $logger) extends ShillinqStockReader {
			/**
			 * Shillinq is installed.
			 *
			 * @return bool
			 */
			protected function probe(): bool {
				return true;
			}
		};
	}//end installed()
}//end class
