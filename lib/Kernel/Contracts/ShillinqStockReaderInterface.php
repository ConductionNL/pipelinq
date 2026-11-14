<?php

/**
 * Contract of ShillinqStockReader
 *
 * The public surface of ShillinqStockReader that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of ShillinqStockReader.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShillinqStockReaderInterface {

	/**
	 * The stock of one product, summed over every shillinq location.
	 *
	 * Shillinq keys a stock row on `productId`; its seeded rows carry only
	 * `productSku`. So the read asks for `productId` first and, when that finds
	 * nothing, for the product's SKU.
	 *
	 * @param string $productId The product's `productId`, the key shillinq stock is kept on.
	 * @param string $sku The product's SKU, matched against `productSku`.
	 *
	 * @return array{state: string, available: float, onHand: float, reserved: float,
	 *     locations: array<int, array{code: string, name: string, available: float}>}
	 *         `state` is `ok`, `no-shillinq` or `no-access`.
	 *
	 * @spec openspec/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
	 */
	public function stockFor(string $productId, string $sku = ''): array;
}//end interface
