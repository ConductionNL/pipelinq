<?php

/**
 * Unit tests for price list pricing in ProductCatalogService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ProductCatalogService;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A quote line for a client is priced from its own list, its segment's or its
 * country's, in the currency and valid today; else from the product itself.
 */
class ProductCatalogServicePriceListTest extends TestCase {

	/**
	 * Product Licentie: base 120, 110 from 10, with a variant.
	 *
	 * @var array<string, mixed>
	 */
	private const LICENTIE = [
		'id'         => 'prod-licentie',
		'unitPrice'  => 120,
		'vatClass'   => 'high',
		'priceTiers' => [['minQuantity' => 10, 'unitPrice' => 110, 'label' => '10+']],
		'variants'   => [['sku' => 'LIC-PRO', 'unitPrice' => 150, 'isActive' => true]],
	];

	/**
	 * The price lists the register holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $lists = [];

	/**
	 * The findAll configs the service asked for.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The service over the recorded lists, with the price list schema set up or not.
	 *
	 * @param bool $configured Whether the price list schema is configured.
	 * @param bool $failing    Whether the read fails.
	 *
	 * @return ProductCatalogService The service.
	 */
	private function service(bool $configured=true, bool $failing=false): ProductCatalogService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default='') use ($configured): string {
				return match ($key) {
					'register' => '7',
					'priceList_schema' => ($configured === true ? '61' : ''),
					default => $default,
				};
			}
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($failing): array {
				$this->queries[] = $config;
				if ($failing === true) {
					throw new RuntimeException('database gone');
				}

				return $this->lists;
			}
		);

		return new ProductCatalogService($appConfig, $this->createMock(LoggerInterface::class), objectService: $objects);
	}//end service()

	/**
	 * A price list.
	 *
	 * @param string               $id     Its id.
	 * @param array<string, mixed> $fields Its fields.
	 * @param float                $price  Its price for Licentie from 1.
	 *
	 * @return array<string, mixed> The list.
	 */
	private static function list(string $id, array $fields, float $price): array {
		return $fields + [
			'id'       => $id,
			'title'    => $id,
			'currency' => 'EUR',
			'isActive' => true,
			'entries'  => [['product' => 'prod-licentie', 'unitPrice' => $price]],
		];
	}//end list()

	/**
	 * A public sector client gets the public sector list's price, and the line names the list.
	 *
	 * @return void
	 */
	public function testAPublicSectorClientGetsTheListPrice(): void {
		$this->lists = [self::list('Overheid 2027', ['segments' => ['public-sector']], 95)];

		$price = $this->service()->resolveEffectivePrice(product: self::LICENTIE, quantity: 1, client: ['segment' => 'public-sector']);

		self::assertSame(
			[
				'unitPrice'      => 95.0,
				'source'         => 'price-list',
				'tierLabel'      => '',
				'quantity'       => 1.0,
				'vatClass'       => 'high',
				'taxRate'        => 21,
				'priceListId'    => 'Overheid 2027',
				'priceListTitle' => 'Overheid 2027',
			],
			$price
		);
		self::assertSame(['filters' => ['register' => '7', 'schema' => '61']], $this->queries[0]);
	}//end testAPublicSectorClientGetsTheListPrice()

	/**
	 * The client's own list goes before its segment's, and its segment's before its country's.
	 *
	 * @return void
	 */
	public function testOwnListThenSegmentThenCountry(): void {
		$this->lists = [
			self::list('NL', ['countries' => ['NL'], 'priority' => 99], 100),
			self::list('Overheid', ['segments' => ['public-sector']], 95),
			self::list('Zeist', [], 90),
		];
		$client      = ['segment' => 'public-sector', 'country' => 'NL', 'priceList' => 'Zeist'];

		self::assertSame(90.0, $this->service()->resolveEffectivePrice(product: self::LICENTIE, quantity: 1, client: $client)['unitPrice']);

		unset($client['priceList']);
		self::assertSame(95.0, $this->service()->resolveEffectivePrice(product: self::LICENTIE, quantity: 1, client: $client)['unitPrice']);

		$client['segment'] = 'SMB';
		self::assertSame('NL', $this->service()->resolveEffectivePrice(product: self::LICENTIE, quantity: 1, client: $client)['priceListTitle']);
	}//end testOwnListThenSegmentThenCountry()

	/**
	 * A list in another currency, an expired or future list and an inactive list price nothing.
	 *
	 * @return void
	 */
	public function testIgnoresOtherCurrenciesExpiredAndInactiveLists(): void {
		$this->lists = [
			self::list('Dollar', ['segments' => ['public-sector'], 'currency' => 'USD', 'priority' => 9], 50),
			self::list('Vorig jaar', ['segments' => ['public-sector'], 'validUntil' => '2000-12-31', 'priority' => 8], 60),
			self::list('Volgend jaar', ['segments' => ['public-sector'], 'validFrom' => '2999-01-01', 'priority' => 7], 70),
			self::list('Uit', ['segments' => ['public-sector'], 'isActive' => false, 'priority' => 6], 80),
			self::list('Geldig', ['segments' => ['public-sector'], 'validFrom' => '2000-01-01', 'validUntil' => '2999-12-31'], 95),
		];

		$price = $this->service()->resolveEffectivePrice(product: self::LICENTIE, quantity: 1, client: ['segment' => 'public-sector'], currency: 'eur');

		self::assertSame('Geldig', $price['priceListTitle']);
		self::assertSame(
			'Dollar',
			$this->service()->resolveEffectivePrice(product: self::LICENTIE, quantity: 1, client: ['segment' => 'public-sector'], currency: 'USD')['priceListTitle']
		);
	}//end testIgnoresOtherCurrenciesExpiredAndInactiveLists()

	/**
	 * Without a matching list the product's own tier prices the line, and the answer names no list.
	 *
	 * @return void
	 */
	public function testFallsBackToTheProductsOwnPrice(): void {
		$this->lists = [self::list('Overheid', ['segments' => ['public-sector']], 95)];

		$price = $this->service()->resolveEffectivePrice(product: self::LICENTIE, quantity: 12, client: ['segment' => 'SMB', 'country' => 'BE']);

		self::assertSame(110.0, $price['unitPrice']);
		self::assertSame('tier', $price['source']);
		self::assertSame('10+', $price['tierLabel']);
		self::assertNull($price['priceListId']);
		self::assertSame('', $price['priceListTitle']);
	}//end testFallsBackToTheProductsOwnPrice()

	/**
	 * Without a client the answer is the product's own, with no price list keys, and no list is read.
	 *
	 * @return void
	 */
	public function testWithoutAClientTheAnswerIsUnchanged(): void {
		$this->lists = [self::list('Overheid', ['segments' => ['public-sector']], 95)];

		$price = $this->service()->resolveEffectivePrice(product: self::LICENTIE, quantity: 1);

		self::assertSame(
			['unitPrice' => 120.0, 'source' => 'base', 'tierLabel' => '', 'quantity' => 1.0, 'vatClass' => 'high', 'taxRate' => 21],
			$price
		);
		self::assertSame([], $this->queries);
	}//end testWithoutAClientTheAnswerIsUnchanged()

	/**
	 * Without the price list schema, or when the read fails, the product's own price applies.
	 *
	 * @return void
	 */
	public function testFallsBackWhenListsCannotBeRead(): void {
		$this->lists = [self::list('Overheid', ['segments' => ['public-sector']], 95)];
		$client      = ['segment' => 'public-sector'];

		self::assertSame('base', $this->service(configured: false)->resolveEffectivePrice(product: self::LICENTIE, quantity: 1, client: $client)['source']);
		self::assertSame([], $this->queries);
		self::assertSame('base', $this->service(failing: true)->resolveEffectivePrice(product: self::LICENTIE, quantity: 1, client: $client)['source']);
	}//end testFallsBackWhenListsCannotBeRead()

	/**
	 * A list's variant price prices that variant, and an unknown variant is still refused.
	 *
	 * @return void
	 */
	public function testPricesAVariantAndStillRefusesAnUnknownOne(): void {
		$list            = self::list('Overheid', ['segments' => ['public-sector']], 95);
		$list['entries'][] = ['product' => 'prod-licentie', 'variantSku' => 'LIC-PRO', 'unitPrice' => 130];
		$this->lists    = [$list];
		$client          = ['segment' => 'public-sector'];

		self::assertSame(130.0, $this->service()->resolveEffectivePrice(product: self::LICENTIE, quantity: 1, variantSku: 'LIC-PRO', client: $client)['unitPrice']);

		$this->expectException(OCSNotFoundException::class);
		$this->service()->resolveEffectivePrice(product: self::LICENTIE, quantity: 1, variantSku: 'LIC-NOPE', client: ['segment' => 'public-sector', 'priceList' => 'none']);
	}//end testPricesAVariantAndStillRefusesAnUnknownOne()
}//end class
