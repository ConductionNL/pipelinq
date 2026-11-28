<?php

/**
 * Pipelinq LeadLineVariantCheck.
 *
 * Says why a lead line that names a variant code its product does not have
 * cannot be stored.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\LinePricing
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
 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\LinePricing;

use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCP\IL10N;

/**
 * The variant check of a lead line.
 *
 * A lead line may name one of its product's variants by code (`variantSku`),
 * which the price resolver then prices. A code the product does not carry
 * would silently price the product itself, so such a line is refused. A line
 * without a code and a line whose product the caller cannot read pass.
 *
 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
 */
class LeadLineVariantCheck {
	/**
	 * Constructor.
	 *
	 * @param CallerObjectReaderInterface $reader Reads the product with the caller's rights.
	 * @param IL10N                       $l10n   Words the refusal in the user's language.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
	 */
	public function __construct(
		private readonly CallerObjectReaderInterface $reader,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Why the line cannot be stored, or null when it can.
	 *
	 * @param array<string, mixed> $line The line as it will be stored.
	 *
	 * @return string|null The refusal, naming the code.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
	 */
	public function refusal(array $line): ?string {
		$code    = self::text(value: $line['variantSku'] ?? null);
		$product = self::text(value: $line['product'] ?? null);
		if ($code === '' || $product === '') {
			return null;
		}

		$stored = $this->reader->read(id: $product, schemaKey: 'product_schema');
		if ($stored === null || self::hasVariant(product: $stored, code: $code) === true) {
			return null;
		}

		return $this->l10n->t('This product has no variant with the code %s', [$code]);
	}//end refusal()

	/**
	 * Whether the product carries a variant with this code.
	 *
	 * @param array<string, mixed> $product The product.
	 * @param string               $code    The variant code.
	 *
	 * @return bool True when one of its variants has the code.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
	 */
	private static function hasVariant(array $product, string $code): bool {
		$variants = $product['variants'] ?? [];
		if (is_array($variants) === false) {
			return false;
		}

		foreach ($variants as $variant) {
			if (is_array($variant) === true && self::text(value: $variant['sku'] ?? null) === $code) {
				return true;
			}
		}

		return false;
	}//end hasVariant()

	/**
	 * A scalar as trimmed text, '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
