<?php

/**
 * Contract of ProductCatalogService
 *
 * The public surface of ProductCatalogService that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Contract of ProductCatalogService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProductCatalogServiceInterface {

	/**
	 * Look up a product by barcode within this app's product schema.
	 *
	 * The barcode is matched against the product's own barcode and against each
	 * ACTIVE variant barcode. A direct product-level match takes priority over
	 * any variant match. The query is constrained to this app's configured
	 * register + product schema, so a caller can never reach objects outside the
	 * catalogue (no IDOR). The scanned value is untrusted and is validated for
	 * length + charset before it is used. Returns null when no product matches.
	 *
	 * When a variant matches, the returned product carries a resolved
	 * `matchedVariantSku` and a zero-based `matchedVariantIndex`.
	 *
	 * @param string $barcode The scanned barcode.
	 *
	 * @return array<string, mixed>|null The matching product, or null.
	 *
	 * @throws OCSBadRequestException If the barcode is empty or malformed.
	 *
	 * @spec openspec/specs/pos-product-catalogue/spec.md
	 * @spec openspec/specs/pos-barcode-scan/spec.md
	 */
	public function lookupByBarcode(string $barcode): ?array;

	/**
	 * Resolve the server-authoritative effective unit price for a product.
	 *
	 * Resolution order:
	 *   1. If a variant SKU is supplied and matches an active variant, its
	 *      unitPrice override (when set) is the base.
	 *   2. Otherwise the product's base unitPrice is the base.
	 *   3. The applicable price tier (if any) overrides the base for the
	 *      requested quantity.
	 *
	 * The returned figure is rounded to cents and never derived from
	 * client-supplied price data.
	 *
	 * With a client, a matching price list's price wins over the product's own.
	 *
	 * @param array<string, mixed>      $product    The product object.
	 * @param float                     $quantity   The ordered quantity (clamped to >= 0).
	 * @param string|null               $variantSku Optional variant SKU.
	 * @param array<string, mixed>|null $client     The client the line is for, read with the caller's rights.
	 * @param string|null               $currency   The quote's currency; EUR when empty.
	 *
	 * @return array<string, mixed> The resolution: unitPrice, source
	 *                              (base|tier|variant|price-list), tierLabel,
	 *                              btwClass, taxRate; with a client also
	 *                              priceListId and priceListTitle.
	 *
	 * @spec openspec/specs/pos-product-catalogue/spec.md
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
	 */
	public function resolveEffectivePrice(
		array $product,
		float $quantity,
		?string $variantSku = null,
		?array $client = null,
		?string $currency = null
	): array;
}//end interface
