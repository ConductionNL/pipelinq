<?php

/**
 * Pipelinq PriceListLine.
 *
 * The quote line a price list is asked to price.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;

/**
 * The product, variant, quantity, currency and day of one quote line.
 *
 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
 */
final class PriceListLine {
	/**
	 * Constructor.
	 *
	 * @param string            $productId The product's id.
	 * @param string            $sku       The variant SKU; empty for none.
	 * @param float             $quantity  The quantity.
	 * @param string            $currency  The quote's currency.
	 * @param DateTimeImmutable $day       The day the price is for.
	 */
	public function __construct(
		public readonly string $productId,
		public readonly string $sku,
		public readonly float $quantity,
		public readonly string $currency,
		public readonly DateTimeImmutable $day,
	) {
	}//end __construct()

	/**
	 * The same line for another SKU.
	 *
	 * @param string $sku The variant SKU; empty for the product itself.
	 *
	 * @return self The line.
	 *
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
	 */
	public function withSku(string $sku): self {
		return new self(
			productId: $this->productId,
			sku: $sku,
			quantity: $this->quantity,
			currency: $this->currency,
			day: $this->day
		);
	}//end withSku()
}//end class
