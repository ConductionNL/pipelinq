<?php

/**
 * Pipelinq VariantCombinations.
 *
 * Finds the first combination of attribute values, or the first code, that
 * two variants of one product share, and the first variant that does not
 * name one allowed value for each attribute the product declares.
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
	 * The first variant that does not fit the product's attributes, or null.
	 *
	 * Only a product that declares attributes is checked. The answer is a
	 * kind and the words the refusal names: `missing` (the variant and the
	 * attribute it names no value for), `value` (the value and its
	 * attribute) or `attribute` (an attribute the product does not declare).
	 *
	 * @param mixed             $declared The product's `variantAttributes`: name and allowed values.
	 * @param array<int, mixed> $variants The product's variants.
	 *
	 * @return array{0: string, 1: array<int, string>}|null The kind and its words.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
	 */
	public function attributeMismatch(mixed $declared, array $variants): ?array {
		$allowed = $this->allowed(declared: $declared);
		if ($allowed === []) {
			return null;
		}

		foreach ($variants as $index => $variant) {
			$mismatch = $this->variantMismatch(allowed: $allowed, variant: $variant, position: ((int)$index + 1));
			if ($mismatch !== null) {
				return $mismatch;
			}
		}

		return null;
	}//end attributeMismatch()

	/**
	 * Where one variant does not fit the declared attributes, or null.
	 *
	 * @param array<string, array{0: string, 1: array<string, true>}> $allowed  Per attribute key: its name and allowed values.
	 * @param mixed                                                    $variant  One variant.
	 * @param int                                                      $position Its place in the list, from 1.
	 *
	 * @return array{0: string, 1: array<int, string>}|null The kind and its words.
	 */
	private function variantMismatch(array $allowed, mixed $variant, int $position): ?array {
		$values = $this->values(variant: $variant);
		$named = [];
		foreach ($values as $attribute => $value) {
			$key = mb_strtolower((string)$attribute);
			if (isset($allowed[$key]) === false) {
				return ['attribute', [(string)$attribute]];
			}

			if (isset($allowed[$key][1][mb_strtolower($value)]) === false) {
				return ['value', [$value, $allowed[$key][0]]];
			}

			$named[$key] = true;
		}

		foreach ($allowed as $key => [$name]) {
			if (isset($named[$key]) === false) {
				return ['missing', [$this->variantLabel(variant: $variant, position: $position), $name]];
			}
		}

		return null;
	}//end variantMismatch()

	/**
	 * The declared attributes by lower-case name: the name as written and its allowed values.
	 *
	 * @param mixed $declared The product's `variantAttributes`.
	 *
	 * @return array<string, array{0: string, 1: array<string, true>}>
	 */
	private function allowed(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		$allowed = [];
		foreach ($declared as $attribute) {
			if (is_array($attribute) === false) {
				continue;
			}

			$name = trim((string)($attribute['name'] ?? ''));
			if ($name === '') {
				continue;
			}

			$values = [];
			foreach ((array)($attribute['values'] ?? []) as $value) {
				if (is_scalar($value) === true && trim((string)$value) !== '') {
					$values[mb_strtolower(trim((string)$value))] = true;
				}
			}

			$allowed[mb_strtolower($name)] = [$name, $values];
		}

		return $allowed;
	}//end allowed()

	/**
	 * How the refusal names a variant: its code, else its name, else its place.
	 *
	 * @param mixed $variant  One variant.
	 * @param int   $position Its place in the list, from 1.
	 *
	 * @return string The label.
	 */
	private function variantLabel(mixed $variant, int $position): string {
		if (is_array($variant) === true) {
			foreach (['sku', 'name'] as $field) {
				$text = trim((string)($variant[$field] ?? ''));
				if ($text !== '') {
					return $text;
				}
			}
		}

		return '#' . $position;
	}//end variantLabel()

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
