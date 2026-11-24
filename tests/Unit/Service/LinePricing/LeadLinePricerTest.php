<?php

/**
 * Unit tests for LeadLinePricer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/products-price-lists/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\LinePricing;

use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\ProductCatalogServiceInterface;
use OCA\Pipelinq\Service\LinePricing\LeadLinePricer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A lead line is priced on the server from the client's lists, and a typed price stays.
 *
 * @covers \OCA\Pipelinq\Service\LinePricing\LeadLinePricer
 */
class LeadLinePricerTest extends TestCase {

	/** @var ProductCatalogServiceInterface&MockObject */
	private $catalog;

	/** @var CallerObjectReaderInterface&MockObject */
	private $reader;

	/**
	 * The objects the reader answers, by schema key and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $objects = [
		'product_schema' => ['p-1' => ['id' => 'p-1', 'name' => 'Licentie', 'unitPrice' => 120]],
		'lead_schema'    => ['l-1' => ['id' => 'l-1', 'client' => 'c-1', 'currency' => 'EUR'], 'l-2' => ['id' => 'l-2']],
		'client_schema'  => ['c-1' => ['id' => 'c-1', 'segment' => 'public-sector']],
	];

	/**
	 * Build the subject and its collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->catalog = $this->createMock(originalClassName: ProductCatalogServiceInterface::class);
		$this->reader  = $this->createMock(originalClassName: CallerObjectReaderInterface::class);
		$this->reader->method('read')->willReturnCallback(
			fn (string $id, string $schemaKey): ?array => $this->objects[$schemaKey][$id] ?? null
		);
	}//end setUp()

	/**
	 * Stand-in for pricer().
	 *
	 * @return LeadLinePricer
	 */
	private function pricer(): LeadLinePricer {
		return new LeadLinePricer(catalog: $this->catalog, reader: $this->reader);
	}//end pricer()

	/**
	 * A resolver answer: the own price without a client, the list price with one.
	 *
	 * @param array<string, mixed> $own       The answer without a client.
	 * @param array<string, mixed> $forClient The answer with the lead's client.
	 *
	 * @return callable
	 */
	private function ownOrClientPrice(array $own, array $forClient): callable {
		return static function (array $product, float $quantity, ?string $variantSku=null, ?array $client=null) use ($own, $forClient): array {
			if ($client === null) {
				return $own;
			}

			return $forClient;
		};
	}//end ownOrClientPrice()

	/**
	 * Scenario "A public sector client gets the list price".
	 *
	 * @return void
	 */
	public function testANewLineWithoutAPriceIsPricedForTheLeadsClientAndCurrency(): void {
		$this->catalog->expects($this->once())->method('resolveEffectivePrice')
			->with(
				$this->objects['product_schema']['p-1'],
				2.0,
				'V-1',
				$this->objects['client_schema']['c-1'],
				'EUR'
			)
			->willReturn(['unitPrice' => 95.0, 'source' => 'price-list', 'priceListId' => 'pl-1', 'priceListTitle' => 'Overheid 2027']);

		$figures = $this->pricer()->figures(line: ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 2, 'variantSku' => 'V-1'], old: null);

		$this->assertSame(expected: ['unitPrice' => 95.0, 'priceSource' => 'price-list', 'priceListTitle' => 'Overheid 2027'], actual: $figures);
	}//end testANewLineWithoutAPriceIsPricedForTheLeadsClientAndCurrency()

	/**
	 * Scenario "No list matches": without a client the product's own price, no list title.
	 *
	 * @return void
	 */
	public function testALeadWithoutAClientGetsTheProductsOwnPrice(): void {
		$this->catalog->expects($this->once())->method('resolveEffectivePrice')
			->with($this->objects['product_schema']['p-1'], 1.0, null, null, null)
			->willReturn(['unitPrice' => 120.0, 'source' => 'base']);

		$figures = $this->pricer()->figures(line: ['lead' => 'l-2', 'product' => 'p-1', 'unitPrice' => ''], old: null);

		$this->assertSame(expected: ['unitPrice' => 120.0, 'priceSource' => 'base', 'priceListTitle' => null], actual: $figures);
	}//end testALeadWithoutAClientGetsTheProductsOwnPrice()

	/**
	 * A new line with a typed price is marked as set by hand.
	 *
	 * @return void
	 */
	public function testANewLineWithATypedPriceIsMarkedAsSetByHand(): void {
		$this->catalog->expects($this->once())->method('resolveEffectivePrice')
			->with($this->objects['product_schema']['p-1'], 1.0, null, null, null)
			->willReturn(['unitPrice' => 120.0, 'source' => 'base']);

		$figures = $this->pricer()->figures(line: ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 1, 'unitPrice' => 88], old: null);

		$this->assertSame(expected: ['priceSource' => 'manual', 'priceListTitle' => null], actual: $figures);
	}//end testANewLineWithATypedPriceIsMarkedAsSetByHand()

	/**
	 * Scenario "A negotiated price": typing over a list price marks the line.
	 *
	 * @return void
	 */
	public function testTypingOverAServerPriceMarksTheLineAsSetByHand(): void {
		$this->catalog->expects($this->once())->method('resolveEffectivePrice')
			->willReturn(['unitPrice' => 120.0, 'source' => 'base']);
		$old = ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 1, 'unitPrice' => 95, 'priceSource' => 'price-list'];

		$figures = $this->pricer()->figures(line: ['unitPrice' => 88] + $old, old: $old);

		$this->assertSame(expected: ['priceSource' => 'manual', 'priceListTitle' => null], actual: $figures);
	}//end testTypingOverAServerPriceMarksTheLineAsSetByHand()

	/**
	 * Scenario "A negotiated price": then changing the quantity keeps 88.
	 *
	 * @return void
	 */
	public function testAQuantityChangeKeepsAPriceSetByHand(): void {
		$this->catalog->expects($this->never())->method('resolveEffectivePrice');
		$old = ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 1, 'unitPrice' => 88, 'priceSource' => 'manual'];

		$this->assertSame(expected: [], actual: $this->pricer()->figures(line: ['quantity' => 3] + $old, old: $old));
	}//end testAQuantityChangeKeepsAPriceSetByHand()

	/**
	 * A quantity change reprices a server priced line.
	 *
	 * @return void
	 */
	public function testAQuantityChangeRepricesAServerPricedLine(): void {
		$this->catalog->expects($this->once())->method('resolveEffectivePrice')
			->with($this->anything(), 10.0, null, $this->anything(), 'EUR')
			->willReturn(['unitPrice' => 90.0, 'source' => 'price-list', 'priceListTitle' => 'Overheid 2027']);
		$old = ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 1, 'unitPrice' => 95, 'priceSource' => 'price-list'];

		$figures = $this->pricer()->figures(line: ['quantity' => 10] + $old, old: $old);

		$this->assertSame(expected: 90.0, actual: $figures['unitPrice']);
	}//end testAQuantityChangeRepricesAServerPricedLine()

	/**
	 * An edit that touches no price input stores the line as it is.
	 *
	 * @return void
	 */
	public function testAnEditThatTouchesNoPriceInputStoresTheLineAsItIs(): void {
		$this->catalog->expects($this->never())->method('resolveEffectivePrice');
		$old = ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 2, 'unitPrice' => 95.0, 'priceSource' => 'price-list'];

		$this->assertSame(
			expected: [],
			actual: $this->pricer()->figures(line: ['notes' => 'x', 'quantity' => '2', 'unitPrice' => '95.00'] + $old, old: $old)
		);
	}//end testAnEditThatTouchesNoPriceInputStoresTheLineAsItIs()

	/**
	 * A new line whose price the form filled in from the product (its own
	 * price for the quantity) was not typed: it is priced for the lead's
	 * client, price list included.
	 *
	 * @spec openspec/changes/products-quote-from-lead/tasks.md
	 *
	 * @return void
	 */
	public function testANewLineWithTheProductsOwnPriceIsPricedForTheClient(): void {
		$this->catalog->expects($this->once())->method('resolveEffectivePrice')
			->willReturnCallback(
				$this->ownOrClientPrice(own: ['unitPrice' => 120.0, 'source' => 'base'], forClient: ['unitPrice' => 95.0, 'source' => 'price-list', 'priceListTitle' => 'Overheid 2027'])
			);

		$figures = $this->pricer()->figures(line: ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 1, 'unitPrice' => 120], old: null);

		$this->assertSame(expected: ['unitPrice' => 95.0, 'priceSource' => 'price-list', 'priceListTitle' => 'Overheid 2027'], actual: $figures);
	}//end testANewLineWithTheProductsOwnPriceIsPricedForTheClient()

	/**
	 * The form fills in the tier price after a quantity change: that is not
	 * typed, so a server-priced line is priced again for the new quantity.
	 *
	 * @spec openspec/changes/products-quote-from-lead/tasks.md
	 *
	 * @return void
	 */
	public function testAQuantityChangeWithTheFormsTierPriceRepricesTheLine(): void {
		$this->catalog->expects($this->exactly(count: 2))->method('resolveEffectivePrice')
			->willReturnCallback(
				$this->ownOrClientPrice(own: ['unitPrice' => 80.0, 'source' => 'tier'], forClient: ['unitPrice' => 78.0, 'source' => 'price-list', 'priceListTitle' => 'Overheid 2027'])
			);
		$old = ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 1, 'unitPrice' => 95, 'priceSource' => 'price-list'];

		$figures = $this->pricer()->figures(line: ['quantity' => 12, 'unitPrice' => 80] + $old, old: $old);

		$this->assertSame(expected: 78.0, actual: $figures['unitPrice']);
	}//end testAQuantityChangeWithTheFormsTierPriceRepricesTheLine()

	/**
	 * Twelve of a product picked from the catalogue: the form copied the
	 * product's stored price (120), which is not typed, so the line takes the
	 * tier price the server works out for twelve.
	 *
	 * @spec openspec/changes/products-quote-from-lead/tasks.md
	 *
	 * @return void
	 */
	public function testAPickedProductsCopiedPriceIsPricedForTheQuantity(): void {
		$this->catalog->expects($this->once())->method('resolveEffectivePrice')
			->with($this->objects['product_schema']['p-1'], 12.0, null, null, null)
			->willReturn(['unitPrice' => 100.0, 'source' => 'tier']);

		$figures = $this->pricer()->figures(line: ['lead' => 'l-2', 'product' => 'p-1', 'quantity' => 12, 'unitPrice' => 120], old: null);

		$this->assertSame(expected: ['unitPrice' => 100.0, 'priceSource' => 'tier', 'priceListTitle' => null], actual: $figures);
	}//end testAPickedProductsCopiedPriceIsPricedForTheQuantity()

	/**
	 * A line without a product or with an unreadable one is left alone.
	 *
	 * @return void
	 */
	public function testALineWithoutAProductOrWithAnUnreadableOneIsLeftAlone(): void {
		$this->catalog->expects($this->never())->method('resolveEffectivePrice');

		$this->assertSame(expected: [], actual: $this->pricer()->figures(line: ['lead' => 'l-1', 'quantity' => 1], old: null));
		$this->assertSame(expected: [], actual: $this->pricer()->figures(line: ['lead' => 'l-1', 'product' => 'gone', 'quantity' => 1], old: null));
	}//end testALineWithoutAProductOrWithAnUnreadableOneIsLeftAlone()
}//end class
