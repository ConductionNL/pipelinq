<?php

/**
 * Unit tests for PriceListResolver.
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

use DateTimeImmutable;
use OCA\Pipelinq\Service\PriceListResolver;
use PHPUnit\Framework\TestCase;

/**
 * Which list and which entry price a product for a client.
 */
class PriceListResolverTest extends TestCase {

	/**
	 * Pick a price for product p-1 on 2027-03-01 in EUR.
	 *
	 * @param array<int, array<string, mixed>> $lists      The lists.
	 * @param array<string, mixed>             $client     The client.
	 * @param float                            $quantity   The quantity.
	 * @param string|null                      $variantSku The variant.
	 *
	 * @return array<string, mixed>|null The price.
	 */
	private static function pick(array $lists, array $client, float $quantity=1, ?string $variantSku=null): ?array {
		return (new PriceListResolver())->select(
			lists: $lists,
			client: $client,
			productId: 'p-1',
			variantSku: $variantSku,
			quantity: $quantity,
			currency: 'EUR',
			day: new DateTimeImmutable('2027-03-01')
		);
	}//end pick()

	/**
	 * A segment list.
	 *
	 * @param string                           $id      Its id.
	 * @param array<string, mixed>             $fields  More fields.
	 * @param array<int, array<string, mixed>> $entries Its entries.
	 *
	 * @return array<string, mixed> The list.
	 */
	private static function segmentList(string $id, array $fields=[], array $entries=[['product' => 'p-1', 'unitPrice' => 10]]): array {
		return $fields + ['id' => $id, 'title' => 'List '.$id, 'currency' => 'EUR', 'segments' => ['public-sector'], 'entries' => $entries];
	}//end segmentList()

	/**
	 * The highest priority list wins; equal priorities go to the newest validFrom, then the lowest id.
	 *
	 * @return void
	 */
	public function testOrdersByPriorityThenNewestThenLowestId(): void {
		$client = ['segment' => 'Public-Sector '];

		self::assertSame('b', self::pick([self::segmentList('a', ['priority' => 1]), self::segmentList('b', ['priority' => 5])], $client)['priceListId']);
		self::assertSame(
			'a',
			self::pick([self::segmentList('b', ['validFrom' => '2026-01-01']), self::segmentList('a', ['validFrom' => '2027-01-01'])], $client)['priceListId']
		);
		self::assertSame('a', self::pick([self::segmentList('b'), self::segmentList('a')], $client)['priceListId']);
	}//end testOrdersByPriorityThenNewestThenLowestId()

	/**
	 * A higher-priority list without the product gives way to the next one that has it.
	 *
	 * @return void
	 */
	public function testSkipsAListWithoutTheProduct(): void {
		$lists = [
			self::segmentList('other', ['priority' => 9], [['product' => 'p-2', 'unitPrice' => 1]]),
			self::segmentList('this', ['priority' => 1], [['product' => ['id' => 'p-1'], 'unitPrice' => '12.345']]),
		];

		self::assertSame(
			['unitPrice' => 12.35, 'priceListId' => 'this', 'priceListTitle' => 'List this', 'minQuantity' => 1.0],
			self::pick($lists, ['segment' => 'public-sector'])
		);
	}//end testSkipsAListWithoutTheProduct()

	/**
	 * Within a list the entry with the highest minimum quantity at most the quantity wins.
	 *
	 * @return void
	 */
	public function testPicksTheEntryForTheQuantity(): void {
		$list = self::segmentList(
			'tiers',
			[],
			[
				['product' => 'p-1', 'unitPrice' => 10],
				['product' => 'p-1', 'minQuantity' => 50, 'unitPrice' => 8],
				['product' => 'p-1', 'minQuantity' => 20, 'unitPrice' => 9],
			]
		);

		self::assertSame(10.0, self::pick([$list], ['segment' => 'public-sector'], 19)['unitPrice']);
		self::assertSame(9.0, self::pick([$list], ['segment' => 'public-sector'], 20)['unitPrice']);
		self::assertSame(8.0, self::pick([$list], ['segment' => 'public-sector'], 75)['unitPrice']);
		self::assertNull(self::pick([$list], ['segment' => 'public-sector'], 0.5));
	}//end testPicksTheEntryForTheQuantity()

	/**
	 * A variant entry goes before the product entry; without a variant only product entries count.
	 *
	 * @return void
	 */
	public function testPrefersTheVariantEntry(): void {
		$list = self::segmentList(
			'v',
			[],
			[
				['product' => 'p-1', 'variantSku' => 'L', 'unitPrice' => 14],
				['product' => 'p-1', 'unitPrice' => 10],
			]
		);

		self::assertSame(14.0, self::pick([$list], ['segment' => 'public-sector'], 1, 'L')['unitPrice']);
		self::assertSame(10.0, self::pick([$list], ['segment' => 'public-sector'], 1, 'M')['unitPrice']);
		self::assertSame(10.0, self::pick([$list], ['segment' => 'public-sector'])['unitPrice']);
		self::assertNull(self::pick([self::segmentList('only-l', [], [['product' => 'p-1', 'variantSku' => 'L', 'unitPrice' => 14]])], ['segment' => 'public-sector']));
	}//end testPrefersTheVariantEntry()

	/**
	 * Validity counts whole days: a list ending on the day still applies, one starting the next day does not.
	 *
	 * @return void
	 */
	public function testValidityIsInclusiveOfTheDay(): void {
		$client = ['segment' => 'public-sector'];

		self::assertNotNull(self::pick([self::segmentList('end', ['validUntil' => '2027-03-01T00:00:00Z'])], $client));
		self::assertNotNull(self::pick([self::segmentList('start', ['validFrom' => '2027-03-01'])], $client));
		self::assertNull(self::pick([self::segmentList('late', ['validFrom' => '2027-03-02'])], $client));
	}//end testValidityIsInclusiveOfTheDay()

	/**
	 * A client without segment, country or own list, a product without id and
	 * an entry without a price get nothing.
	 *
	 * @return void
	 */
	public function testNothingMatchesWithoutGrounds(): void {
		self::assertNull(self::pick([self::segmentList('a')], ['segment' => '', 'country' => null]));
		self::assertNull(self::pick([self::segmentList('a', [], [['product' => 'p-1']])], ['segment' => 'public-sector']));
		self::assertNull(
			(new PriceListResolver())->select(
				lists: [self::segmentList('a')],
				client: ['segment' => 'public-sector'],
				productId: '',
				variantSku: null,
				quantity: 1,
				currency: 'EUR',
				day: new DateTimeImmutable('2027-03-01')
			)
		);
	}//end testNothingMatchesWithoutGrounds()

	/**
	 * A country list matches the client's country, upper or lower case.
	 *
	 * @return void
	 */
	public function testMatchesTheCountry(): void {
		$list = ['id' => 'be', 'title' => 'België', 'currency' => 'EUR', 'countries' => ['BE'], 'entries' => [['product' => 'p-1', 'unitPrice' => 11]]];

		self::assertSame('be', self::pick([$list], ['country' => 'be'])['priceListId']);
		self::assertNull(self::pick([$list], ['country' => 'NL']));
	}//end testMatchesTheCountry()
}//end class
