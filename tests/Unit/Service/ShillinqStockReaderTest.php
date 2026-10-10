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
 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
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
}//end class
