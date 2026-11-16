<?php

/**
 * Pipelinq QuoteLine.
 *
 * What a quote line asks a price for.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/pos-product-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * One line to price: product, quantity, variant and the quote's currency.
 *
 * @spec openspec/specs/pos-product-catalogue/spec.md
 */
final class QuoteLine {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $product    The product object.
	 * @param float                $quantity   The ordered quantity, at least 0.
	 * @param ?string              $variantSku Optional variant SKU.
	 * @param ?string              $currency   The quote's currency, or null for EUR.
	 *
	 * @spec openspec/specs/pos-product-catalogue/spec.md
	 */
	public function __construct(
		public readonly array $product,
		public readonly float $quantity,
		public readonly ?string $variantSku,
		public readonly ?string $currency,
	) {
	}//end __construct()
}//end class
