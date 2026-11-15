<?php

/**
 * Unit tests for the IngestProductVendorMaster repair step.
 *
 * Covers the shillinq → pipelinq master-data ingest (REQ-PVM-007, 008):
 *   - a new product is created and its FK map ({shillinqRef → productId}) returned
 *   - a re-ingest of the same export is idempotent: no duplicate product is created
 *   - fill-only semantics: an existing product's pricing is never overwritten,
 *     but empty supply-master fields are filled
 *   - a new vendor resolves a contactsUid and is returned in the vendor FK map,
 *     with financial AP fields kept off the supplier commercial profile
 *
 * The OpenRegister ObjectService and the Contacts IManager are replaced with
 * in-memory fakes; the ContactVcardService is stubbed.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
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

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\Pipelinq\Repair\IngestProductVendorMaster;
use OCA\Pipelinq\Service\ContactVcardService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService double supporting filter-aware findAll + saveObject.
 */
class FakeIngestObjectService {
	/**
	 * Stored objects keyed by id.
	 *
	 * @var array<string, array<string,mixed>>
	 */
	public array $store = [];

	/**
	 * Where each save went: register, schema and uuid.
	 *
	 * @var array<int, array<string,mixed>>
	 */
	public array $saves = [];

	/**
	 * Auto-increment id counter.
	 *
	 * @var int
	 */
	private int $seq = 0;

	/**
	 * Filter-aware lookup — mirrors OR's real ObjectService::findAll(array $config).
	 *
	 * The register/schema context travels INSIDE $config['filters']; OR treats
	 * both as reserved params, never as object-field filters, so they are
	 * stripped before the equality match. Pagination lives at the top level.
	 *
	 * @param array<string,mixed> $config Config with `filters`, `limit`, `offset`.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function findAll(array $config = []): array {
		$filters = $config['filters'] ?? [];
		unset($filters['register'], $filters['schema']);
		$limit = (int)($config['limit'] ?? 100);
		$offset = (int)($config['offset'] ?? 0);

		$matches = [];
		foreach ($this->store as $obj) {
			$ok = true;
			foreach ($filters as $field => $value) {
				if (($obj[$field] ?? null) !== $value) {
					$ok = false;
					break;
				}
			}

			if ($ok === true) {
				$matches[] = $obj;
			}
		}

		return array_slice($matches, $offset, $limit);
	}//end findAll()

	/**
	 * Create (uuid null) or update an object, as OpenRegister's
	 * ObjectService::saveObject does: same parameters, and the saved object
	 * comes back as an ObjectEntity.
	 *
	 * @param array<string,mixed>|ObjectEntity $object The object data.
	 * @param array<int,string>|null $extend Unused.
	 * @param Register|string|int|null $register The register.
	 * @param Schema|string|int|null $schema The schema.
	 * @param string|null $uuid The object to update, or null to create.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 * @param bool $silent Unused.
	 * @param bool $_validation Unused.
	 * @param array<string,mixed>|null $uploadedFiles Unused.
	 * @param IUser|null $currentUser Unused.
	 * @param bool $failIfExists Unused.
	 * @param bool $_unowned Unused.
	 * @param bool $_dedupOverride Unused.
	 *
	 * @return ObjectEntity The stored object.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function saveObject(
		array|ObjectEntity $object,
		?array $extend=[],
		Register|string|int|null $register=null,
		Schema|string|int|null $schema=null,
		?string $uuid=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $silent=false,
		bool $_validation=true,
		?array $uploadedFiles=null,
		?IUser $currentUser=null,
		bool $failIfExists=false,
		bool $_unowned=false,
		bool $_dedupOverride=false,
	): ObjectEntity {
		$this->saves[] = ['register' => $register, 'schema' => $schema, 'uuid' => $uuid];
		$data = $object;
		if ($object instanceof ObjectEntity) {
			$data = $object->getObject();
		}

		if ($uuid === null || $uuid === '') {
			$this->seq++;
			$uuid = 'obj-' . $this->seq;
		}

		$data['id'] = $uuid;
		$this->store[$uuid] = $data;

		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister((string)$register);
		$entity->setSchema((string)$schema);
		$entity->setObject($data);
		return $entity;
	}//end saveObject()
}//end class

/**
 * Contacts IManager double that finds nothing (forces the create path).
 */
class FakeContactsManager {
	/**
	 * Always enabled.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * Never matches an existing contact.
	 *
	 * @param string $pattern Ignored.
	 * @param array<string> $properties Ignored.
	 * @param array<string> $options Ignored.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function search(string $pattern, array $properties = [], array $options = []): array {
		return [];
	}//end search()
}//end class

/**
 * Test suite for IngestProductVendorMaster.
 */
class IngestProductVendorMasterTest extends TestCase {
	/**
	 * Build the repair step wired to in-memory fakes.
	 *
	 * @param FakeIngestObjectService $os                 The fake object service.
	 * @param string                  $sourceRecordSchema The source record schema, '' for none.
	 *
	 * @return IngestProductVendorMaster
	 */
	private function makeRepair(FakeIngestObjectService $os, string $sourceRecordSchema = ''): IngestProductVendorMaster {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($sourceRecordSchema) {
				$vals = [
					'register' => 'reg-1',
					'product_schema' => 'prod-schema',
					'supplier_schema' => 'supp-schema',
					'sourceRecord_schema' => $sourceRecordSchema,
				];
				return $vals[$key] ?? $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($os) {
				if ($id === 'OCP\Contacts\IManager') {
					return new FakeContactsManager();
				}
				return $os;
			}
		);

		$vcard = $this->createMock(ContactVcardService::class);
		$vcard->method('syncToContacts')->willReturn('');

		return new IngestProductVendorMaster($this->createMock(IAppManager::class),
			$appConfig,
			$vcard,
			$container,
			$this->createMock(LoggerInterface::class),
		);
	}//end makeRepair()

	/**
	 * A new product is created and returned in the product FK map.
	 *
	 * @return void
	 */
	public function testIngestCreatesProductAndReturnsFkMap(): void {
		$os = new FakeIngestObjectService();
		$repair = $this->makeRepair($os);

		$maps = $repair->ingest(
			[
				'products' => [
					['ref' => 'shil-p-1', 'id' => 'shil-p-1', 'sku' => 'SKU-1', 'name' => 'Bolt', 'gtin' => '12345'],
				],
				'vendors' => [],
			]
		);

		$this->assertArrayHasKey('shil-p-1', $maps['products']);
		$this->assertNotSame('', $maps['products']['shil-p-1']);
		// Exactly one product object was created.
		$products = array_filter($os->store, static fn ($o) => isset($o['sku']));
		$this->assertCount(1, $products);
		// productId was fixed up to equal the object UUID (REQ-PVM-002).
		$created = reset($products);
		$this->assertSame($created['id'], $created['productId']);
	}//end testIngestCreatesProductAndReturnsFkMap()

	/**
	 * Re-ingesting the same export does not create a duplicate product.
	 *
	 * @return void
	 */
	public function testIngestIsIdempotentForProducts(): void {
		$os = new FakeIngestObjectService();
		$repair = $this->makeRepair($os);

		$export = [
			'products' => [['ref' => 'shil-p-1', 'id' => 'shil-p-1', 'sku' => 'SKU-1', 'name' => 'Bolt']],
			'vendors' => [],
		];

		$first = $repair->ingest($export);
		$second = $repair->ingest($export);

		$products = array_filter($os->store, static fn ($o) => isset($o['sku']));
		$this->assertCount(1, $products, 're-ingest must not duplicate the product');
		$this->assertSame($first['products']['shil-p-1'], $second['products']['shil-p-1']);
	}//end testIngestIsIdempotentForProducts()

	/**
	 * Fill-only: an existing product's pricing is never overwritten, but an
	 * empty supply-master field is filled.
	 *
	 * @return void
	 */
	public function testIngestFillsEmptyFieldsWithoutOverwritingPricing(): void {
		$os = new FakeIngestObjectService();
		// Seed an existing pipelinq product with a price and an empty gtin.
		$os->store['p-existing'] = [
			'id' => 'p-existing',
			'productId' => 'p-existing',
			'sku' => 'SKU-1',
			'name' => 'Bolt',
			'unitPrice' => 9.99,
			'gtin' => '',
		];

		$repair = $this->makeRepair($os);
		$repair->ingest(
			[
				'products' => [
					['ref' => 'shil-p-1', 'id' => 'shil-p-1', 'sku' => 'SKU-1', 'unitPrice' => 1.00, 'gtin' => '99999'],
				],
				'vendors' => [],
			]
		);

		$updated = $os->store['p-existing'];
		$this->assertSame(9.99, $updated['unitPrice'], 'pricing must never be overwritten by ingest');
		$this->assertSame('99999', $updated['gtin'], 'empty supply-master field must be filled');
	}//end testIngestFillsEmptyFieldsWithoutOverwritingPricing()

	/**
	 * A new vendor is created, returns a contactsUid in the vendor FK map, and
	 * the financial AP fields are not stored on the supplier commercial profile.
	 *
	 * @return void
	 */
	public function testIngestCreatesSupplierAndKeepsFinancialFieldsOff(): void {
		$os = new FakeIngestObjectService();
		$repair = $this->makeRepair($os);

		$maps = $repair->ingest(
			[
				'products' => [],
				'vendors' => [
					[
						'ref' => 'shil-v-1',
						'id' => 'shil-v-1',
						'name' => 'Acme Supplies',
						'kvkNumber' => '12345678',
						'category' => 'goods',
						'iban' => 'NL00BANK0123456789',
						'paymentMethod' => 'transfer',
						'creditLimit' => 5000,
					],
				],
			]
		);

		$this->assertArrayHasKey('shil-v-1', $maps['vendors']);
		$contactsUid = $maps['vendors']['shil-v-1'];
		$this->assertNotSame('', $contactsUid);

		$suppliers = array_filter($os->store, static fn ($o) => isset($o['displayName']));
		$this->assertCount(1, $suppliers);
		$supplier = reset($suppliers);
		$this->assertSame('Acme Supplies', $supplier['displayName']);
		$this->assertSame($contactsUid, $supplier['contactsUid']);
		// Financial AP fields must NOT live on the supplier commercial profile.
		$this->assertArrayNotHasKey('iban', $supplier);
		$this->assertArrayNotHasKey('paymentMethod', $supplier);
		$this->assertArrayNotHasKey('creditLimit', $supplier);
	}//end testIngestCreatesSupplierAndKeepsFinancialFieldsOff()

	/**
	 * Every save names the register, the schema and, for an update, the
	 * object, the way OpenRegister's saveObject takes them: a new product,
	 * its productId fix-up, a filled existing product, a new and a filled
	 * existing supplier, and each one's source record.
	 *
	 * @return void
	 */
	public function testEverySaveGoesToTheConfiguredRegisterAndSchema(): void {
		$os = new FakeIngestObjectService();
		$os->store['p-existing'] = ['id' => 'p-existing', 'productId' => 'p-existing', 'sku' => 'SKU-2', 'gtin' => ''];
		$os->store['sup-1'] = ['id' => 'sup-1', 'contactsUid' => 'pvm-' . md5('Bouw BV87654321'), 'displayName' => 'Bouw BV', 'category' => ''];
		$repair = $this->makeRepair(os: $os, sourceRecordSchema: 'src-schema');

		$maps = $repair->ingest(
			[
				'products' => [
					['ref' => 'p-new', 'id' => 'p-new', 'sku' => 'SKU-1', 'name' => 'Bolt'],
					['ref' => 'p-old', 'id' => 'p-old', 'sku' => 'SKU-2', 'gtin' => '99999'],
				],
				'vendors' => [
					['ref' => 'v-1', 'id' => 'v-1', 'name' => 'Acme', 'kvkNumber' => '12345678'],
					['ref' => 'v-2', 'id' => 'v-2', 'name' => 'Bouw BV', 'kvkNumber' => '87654321', 'category' => 'goods'],
				],
			]
		);

		$this->assertSame(expected: ['p-new' => 'obj-1', 'p-old' => 'p-existing'], actual: $maps['products']);
		$this->assertSame(
			expected: [
				['register' => 'reg-1', 'schema' => 'prod-schema', 'uuid' => null],
				['register' => 'reg-1', 'schema' => 'prod-schema', 'uuid' => 'obj-1'],
				['register' => 'reg-1', 'schema' => 'src-schema', 'uuid' => null],
				['register' => 'reg-1', 'schema' => 'prod-schema', 'uuid' => 'p-existing'],
				['register' => 'reg-1', 'schema' => 'src-schema', 'uuid' => null],
				['register' => 'reg-1', 'schema' => 'supp-schema', 'uuid' => null],
				['register' => 'reg-1', 'schema' => 'src-schema', 'uuid' => null],
				['register' => 'reg-1', 'schema' => 'supp-schema', 'uuid' => 'sup-1'],
				['register' => 'reg-1', 'schema' => 'src-schema', 'uuid' => null],
			],
			actual: $os->saves
		);
		$this->assertSame(expected: '99999', actual: $os->store['p-existing']['gtin']);
		$this->assertSame(expected: 'obj-1', actual: $os->store['obj-1']['productId']);
		$this->assertSame(expected: 'goods', actual: $os->store['sup-1']['category']);
	}//end testEverySaveGoesToTheConfiguredRegisterAndSchema()

	/**
	 * A supplier register or schema read that throws is logged as a failed vendor
	 * ingest and costs only that vendor; the ingest itself does not throw.
	 *
	 * @return void
	 */
	public function testAThrowingSupplierConfigReadIsLoggedAndSkipsTheVendor(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(exception: new \RuntimeException('config down'));

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn(new FakeIngestObjectService());

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('PVM ingest: vendor ingest failed', ['vendor' => 'Acme Supplies', 'exception' => 'config down']);

		$repair = new IngestProductVendorMaster(
			appManager: $this->createMock(originalClassName: IAppManager::class),
			appConfig: $appConfig,
			contactVcardService: $this->createMock(originalClassName: ContactVcardService::class),
			container: $container,
			logger: $logger,
		);

		$maps = $repair->ingest(export: ['vendors' => [['ref' => 'shil-v-1', 'name' => 'Acme Supplies']]]);

		$this->assertSame(expected: ['products' => [], 'vendors' => []], actual: $maps);
	}//end testAThrowingSupplierConfigReadIsLoggedAndSkipsTheVendor()
}//end class
