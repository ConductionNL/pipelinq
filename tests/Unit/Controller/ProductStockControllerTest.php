<?php

/**
 * ProductStockController: the four states of the Stock tracked line.
 *
 * Drives the real controller over the real ShillinqStockReader; only
 * OpenRegister's ObjectService (find/findAll with OR's signatures) and the
 * shillinq probe are substituted.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-untracked-products-and-missing-stock-read-plainly-req-pst-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ProductStockController;
use OCA\Pipelinq\Service\ShillinqStockReader;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ProductStockController coverage.
 */
class ProductStockControllerTest extends TestCase {

	/**
	 * In-memory OpenRegister.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Build the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new class {
			/** @var array<string, array<string, mixed>> */
			public array $products = [];

			/** @var array<int, array<string, mixed>> */
			public array $stock = [];

			/** @var bool */
			public bool $denyStock = false;

			/** @var int */
			public int $stockReads = 0;

			/**
			 * OR's find(): null for an object the caller may not read.
			 *
			 * @param string $id Id.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->products[$id] ?? null);
			}

			/**
			 * OR's findAll(array $config).
			 *
			 * @param array<string, mixed> $config Config.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$this->stockReads++;
				if ($this->denyStock === true) {
					throw new \RuntimeException('not authorized');
				}

				return $this->stock;
			}
		};
		$this->store->products['p-1'] = ['id' => 'p-1', 'name' => 'Afvalpas ondergrondse containers', 'productId' => 'AFV-PAS-001', 'stockTracked' => true, 'unitOfMeasure' => 'pieces'];
		$this->store->products['p-2'] = ['id' => 'p-2', 'name' => 'Content workshop', 'stockTracked' => false];
	}//end setUp()

	/**
	 * The controller over the real reader.
	 *
	 * @param bool $shillinq Whether shillinq is installed.
	 *
	 * @return ProductStockController
	 */
	private function controller(bool $shillinq = true): ProductStockController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);
		$reader = new class ($shillinq, $container, $this->createMock(LoggerInterface::class)) extends ShillinqStockReader {
			/**
			 * Constructor.
			 *
			 * @param bool $installed Probe answer.
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
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new ProductStockController($this->createMock(IRequest::class), $reader, $container, $appConfig, $session);
	}//end controller()

	/**
	 * A tracked product with stock in one location answers ok and the sum.
	 *
	 * @return void
	 */
	public function testATrackedProductAnswersItsStock(): void {
		$this->store->stock = [['productId' => 'AFV-PAS-001', 'locationName' => 'Amsterdam', 'quantityOnHand' => 1860, 'quantityReserved' => 0, 'quantityAvailable' => 1860]];

		$data = $this->controller()->show(id: 'p-1')->getData();

		$this->assertSame('ok', $data['state']);
		$this->assertTrue($data['tracked']);
		$this->assertSame(1860.0, $data['available']);
		$this->assertSame('pieces', $data['unit']);
	}//end testATrackedProductAnswersItsStock()

	/**
	 * An untracked product asks shillinq nothing.
	 *
	 * @return void
	 */
	public function testAnUntrackedProductAsksShillinqNothing(): void {
		$data = $this->controller()->show(id: 'p-2')->getData();

		$this->assertSame('untracked', $data['state']);
		$this->assertSame(0, $this->store->stockReads);
	}//end testAnUntrackedProductAsksShillinqNothing()

	/**
	 * Without shillinq the state says so.
	 *
	 * @return void
	 */
	public function testWithoutShillinq(): void {
		$this->assertSame('no-shillinq', $this->controller(shillinq: false)->show(id: 'p-1')->getData()['state']);
	}//end testWithoutShillinq()

	/**
	 * A refused stock read is no access, with no quantity.
	 *
	 * @return void
	 */
	public function testARefusedStockReadIsNoAccess(): void {
		$this->store->denyStock = true;

		$data = $this->controller()->show(id: 'p-1')->getData();

		$this->assertSame('no-access', $data['state']);
		$this->assertSame(0.0, $data['available']);
	}//end testARefusedStockReadIsNoAccess()

	/**
	 * A product the caller cannot read answers 404 and shillinq is not asked.
	 *
	 * @return void
	 */
	public function testAnUnreadableProductIsNotFound(): void {
		$response = $this->controller()->show(id: 'p-unknown');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(0, $this->store->stockReads);
	}//end testAnUnreadableProductIsNotFound()
}//end class
