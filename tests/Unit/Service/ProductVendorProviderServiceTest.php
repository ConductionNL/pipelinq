<?php

/**
 * Unit tests for ProductVendorProviderService.
 *
 * Covers the integration-registry read surface (REQ-PVM-005, 006):
 *   - getProduct() masks the CRM-private `cost` field for unauthorised consumers
 *   - getProduct() returns the full record (incl. cost) for an explicitly
 *     authorised consumer (both the boolean flag and the app-config allowlist)
 *   - resolveSupplier() returns the commercial profile as-is
 *   - graceful degradation: a missing register/schema config or an object-service
 *     failure returns null so the consumer falls back to its cached FK value
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ProductVendorProviderService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A fake OpenRegister ObjectService returning a canned result set.
 */
class FakePvmObjectService {
	/**
	 * Result rows returned by findAll().
	 *
	 * @var array<int, array<string,mixed>>
	 */
	public array $rows = [];

	/**
	 * Whether findAll should throw to simulate an OR failure.
	 *
	 * @var bool
	 */
	public bool $throw = false;

	/**
	 * Return the canned rows (ignores filters — the test controls the dataset).
	 *
	 * Mirrors OR's real ObjectService::findAll(array $config).
	 *
	 * @param array<string,mixed> $config Ignored (config with `filters`, `limit`, `offset`).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function findAll(array $config = []): array {
		if ($this->throw === true) {
			throw new \RuntimeException('object service unavailable');
		}

		return $this->rows;
	}//end findAll()
}//end class

/**
 * Test suite for ProductVendorProviderService.
 */
class ProductVendorProviderServiceTest extends TestCase {
	/**
	 * Build a service wired to a fake ObjectService and a configurable app-config.
	 *
	 * @param FakePvmObjectService $os The fake object service.
	 * @param array<string,string> $configVals App-config key => value overrides.
	 *
	 * @return ProductVendorProviderService
	 */
	private function makeService(FakePvmObjectService $os, array $configVals = []): ProductVendorProviderService {
		$defaults = [
			'register' => 'reg-1',
			'product_schema' => 'prod-schema',
			'supplier_schema' => 'supp-schema',
			'product_vendor_cost_consumers' => '',
		];
		$vals = array_merge($defaults, $configVals);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($vals): string {
				return $vals[$key] ?? $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		return new ProductVendorProviderService(
			$appConfig,
			$container,
			$this->createMock(LoggerInterface::class),
			objectService: $os,
		);
	}//end makeService()

	/**
	 * getProduct() strips the CRM-private cost field for an unauthorised consumer.
	 *
	 * @return void
	 */
	public function testGetProductMasksCostForUnauthorisedConsumer(): void {
		$os = new FakePvmObjectService();
		$os->rows = [['productId' => 'p-1', 'name' => 'Widget', 'unitPrice' => 10.0, 'cost' => 4.0]];

		$service = $this->makeService($os);
		$result = $service->getProduct('p-1', 'shillinq', false);

		$this->assertIsArray($result);
		$this->assertArrayNotHasKey('cost', $result, 'cost must be masked for unauthorised consumers');
		$this->assertSame(10.0, $result['unitPrice']);
		$this->assertSame('Widget', $result['name']);
	}//end testGetProductMasksCostForUnauthorisedConsumer()

	/**
	 * getProduct() returns cost when the boolean authorised flag is set.
	 *
	 * @return void
	 */
	public function testGetProductReturnsCostForAuthorisedFlag(): void {
		$os = new FakePvmObjectService();
		$os->rows = [['productId' => 'p-1', 'name' => 'Widget', 'cost' => 4.0]];

		$service = $this->makeService($os);
		$result = $service->getProduct('p-1', 'shillinq', true);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('cost', $result);
		$this->assertSame(4.0, $result['cost']);
	}//end testGetProductReturnsCostForAuthorisedFlag()

	/**
	 * getProduct() returns cost when the consumer slug is in the config allowlist.
	 *
	 * @return void
	 */
	public function testGetProductReturnsCostForAllowlistedConsumer(): void {
		$os = new FakePvmObjectService();
		$os->rows = [['productId' => 'p-1', 'cost' => 4.0]];

		$service = $this->makeService($os, ['product_vendor_cost_consumers' => 'foo, shillinq, bar']);
		$result = $service->getProduct('p-1', 'shillinq', false);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('cost', $result);
	}//end testGetProductReturnsCostForAllowlistedConsumer()

	/**
	 * getProduct() returns null (graceful degradation) when the product is absent.
	 *
	 * @return void
	 */
	public function testGetProductReturnsNullWhenNotFound(): void {
		$os = new FakePvmObjectService();
		$os->rows = [];

		$service = $this->makeService($os);
		$this->assertNull($service->getProduct('missing', 'shillinq', false));
	}//end testGetProductReturnsNullWhenNotFound()

	/**
	 * getProduct() returns null when the register/schema config is unset.
	 *
	 * @return void
	 */
	public function testGetProductReturnsNullWhenUnconfigured(): void {
		$os = new FakePvmObjectService();
		$os->rows = [['productId' => 'p-1']];

		$service = $this->makeService($os, ['register' => '', 'product_schema' => '']);
		$this->assertNull($service->getProduct('p-1', 'shillinq', false));
	}//end testGetProductReturnsNullWhenUnconfigured()

	/**
	 * A thrown ObjectService failure degrades gracefully to null.
	 *
	 * @return void
	 */
	public function testGetProductReturnsNullOnObjectServiceFailure(): void {
		$os = new FakePvmObjectService();
		$os->throw = true;

		$service = $this->makeService($os);
		$this->assertNull($service->getProduct('p-1', 'shillinq', false));
	}//end testGetProductReturnsNullOnObjectServiceFailure()

	/**
	 * resolveSupplier() returns the commercial profile unchanged.
	 *
	 * @return void
	 */
	public function testResolveSupplierReturnsProfile(): void {
		$os = new FakePvmObjectService();
		$os->rows = [
			[
				'contactsUid' => 'uid-1',
				'displayName' => 'Acme Supplies',
				'category' => 'hardware',
				'leadTimeDays' => 5,
			],
		];

		$service = $this->makeService($os);
		$result = $service->resolveSupplier('uid-1', 'shillinq');

		$this->assertIsArray($result);
		$this->assertSame('Acme Supplies', $result['displayName']);
		$this->assertSame(5, $result['leadTimeDays']);
	}//end testResolveSupplierReturnsProfile()

	/**
	 * resolveSupplier() returns null when the supplier is absent.
	 *
	 * @return void
	 */
	public function testResolveSupplierReturnsNullWhenNotFound(): void {
		$os = new FakePvmObjectService();
		$os->rows = [];

		$service = $this->makeService($os);
		$this->assertNull($service->resolveSupplier('missing', 'shillinq'));
	}//end testResolveSupplierReturnsNullWhenNotFound()
}//end class
