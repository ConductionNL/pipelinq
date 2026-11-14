<?php

/**
 * Pipelinq ProductCategoryNames.
 *
 * The category a product's POS line revenue is reported under.
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
 * @spec openspec/specs/commercial-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The category name of each product.
 *
 * Product.category is a UUID reference to a productCategory, resolved to its
 * display name. A legacy free-text category (no matching productCategory
 * row) stays as written, and a product without a category is "Other".
 *
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
final class ProductCategoryNames {
	/**
	 * Constructor.
	 *
	 * @param array<string, string> $categoryByProduct Product id => category reference.
	 * @param array<string, string> $categoryNames     ProductCategory id => display name.
	 */
	public function __construct(
		public readonly array $categoryByProduct,
		public readonly array $categoryNames,
	) {
	}//end __construct()

	/**
	 * The category name a product's revenue is reported under.
	 *
	 * @param string $productId The product id of a POS line.
	 *
	 * @return string The category name, "Other" when the product has none.
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	public function nameOf(string $productId): string {
		$categoryRef = ($this->categoryByProduct[$productId] ?? '');
		$category = ($this->categoryNames[$categoryRef] ?? $categoryRef);
		if ($category === '') {
			return 'Other';
		}

		return $category;
	}//end nameOf()
}//end class
