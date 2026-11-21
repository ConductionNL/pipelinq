<?php

/**
 * Pipelinq VariantCombinations.
 *
 * Finds the first combination of attribute values, or the first code, that
 * two variants of one product share.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Product
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-29.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Product;

/**
 * Two variants of one product never name the same values or carry the same code.
 *
 * A variant names its values in `attributes`, a map of attribute to value
 * (`{"Maat": "S"}`). Two maps with the same pairs in another order are the
 * same combination; a value is compared without the case and the spaces
 * around it. A variant that names no values is no combination.
 *
 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
 */
final class VariantCombinations {

	/**
	 * The first combination two variants share, as the user reads it ("Maat S"), or null.
	 *
	 * @param array<int, mixed> $variants The product's variants.
	 *
	 * @return string|null The combination, as the first of the two variants names it.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
	 */
	public function duplicateCombination(array $variants): ?string {
		$seen = [];
		foreach ($variants as $variant) {
			$values = $this->values(variant: $variant);
			if ($values === []) {
				continue;
			}

			$key = $this->key(values: $values);
			if (isset($seen[$key]) === true) {
				return $seen[$key];
			}

			$seen[$key] = $this->label(values: $values);
		}

		return null;
	}//end duplicateCombination()

	/**
	 * The first code two variants share, as the first of them writes it, or null.
	 *
	 * @param array<int, mixed> $variants The product's variants.
	 *
	 * @return string|null The code.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
	 */
	public function duplicateSku(array $variants): ?string {
		$seen = [];
		foreach ($variants as $variant) {
			if (is_array($variant) === false) {
				continue;
			}

			$sku = trim((string)($variant['sku'] ?? ''));
			if ($sku === '') {
				continue;
			}

			$key = mb_strtolower($sku);
			if (isset($seen[$key]) === true) {
				return $seen[$key];
			}

			$seen[$key] = $sku;
		}

		return null;
	}//end duplicateSku()

	/**
	 * The attribute values a variant names, without empty ones.
	 *
	 * @param mixed $variant One variant.
	 *
	 * @return array<string, string> Attribute to value, in the variant's own order.
	 */
	private function values(mixed $variant): array {
		if (is_array($variant) === false || is_array($variant['attributes'] ?? null) === false) {
			return [];
		}

		$values = [];
		foreach ($variant['attributes'] as $attribute => $value) {
			if (is_scalar($value) === false || trim((string)$value) === '') {
				continue;
			}

			$values[trim((string)$attribute)] = trim((string)$value);
		}

		return $values;
	}//end values()

	/**
	 * One comparable key for a combination: the pairs sorted, without case.
	 *
	 * @param array<string, string> $values Attribute to value.
	 *
	 * @return string The key.
	 */
	private function key(array $values): string {
		$pairs = [];
		foreach ($values as $attribute => $value) {
			$pairs[] = mb_strtolower((string)$attribute) . '=' . mb_strtolower($value);
		}

		sort($pairs);
		return implode("\n", $pairs);
	}//end key()

	/**
	 * The combination as the user reads it: "Maat S, Kleur Wit".
	 *
	 * @param array<string, string> $values Attribute to value.
	 *
	 * @return string The label.
	 */
	private function label(array $values): string {
		$parts = [];
		foreach ($values as $attribute => $value) {
			$parts[] = $attribute . ' ' . $value;
		}

		return implode(', ', $parts);
	}//end label()
}//end class
