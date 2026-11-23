<?php

/**
 * Tests for VariantCombinations.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-29.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Product;

use OCA\Pipelinq\Service\Product\VariantCombinations;
use PHPUnit\Framework\TestCase;

/**
 * Two variants of one product share no combination of values and no code.
 *
 * @covers \OCA\Pipelinq\Service\Product\VariantCombinations
 *
 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
 */
class VariantCombinationsTest extends TestCase {
	/**
	 * A shirt in S and M is two combinations; a second S is the first one again.
	 *
	 * @return void
	 */
	public function testTheSameSizeTwiceIsNamed(): void {
		$check = new VariantCombinations();
		$sizes = [
			['sku' => 'WS-S', 'attributes' => ['Maat' => 'S']],
			['sku' => 'WS-M', 'attributes' => ['Maat' => 'M'], 'unitPrice' => 27.5],
		];

		$this->assertNull(actual: $check->duplicateCombination(variants: $sizes));

		$sizes[] = ['sku' => 'WS-S2', 'attributes' => ['Maat' => ' s ']];
		$this->assertSame(expected: 'Maat S', actual: $check->duplicateCombination(variants: $sizes));
	}//end testTheSameSizeTwiceIsNamed()

	/**
	 * The order of the pairs does not make another combination; one value more does.
	 *
	 * @return void
	 */
	public function testThePairsCountNotTheirOrder(): void {
		$check = new VariantCombinations();

		$this->assertSame(
			expected: 'Maat S, Kleur Wit',
			actual: $check->duplicateCombination(
				variants: [
					['attributes' => ['Maat' => 'S', 'Kleur' => 'Wit']],
					['attributes' => ['kleur' => 'wit', 'maat' => 'S']],
				]
			)
		);
		$this->assertNull(
			actual: $check->duplicateCombination(
				variants: [
					['attributes' => ['Maat' => 'S']],
					['attributes' => ['Maat' => 'S', 'Kleur' => 'Wit']],
				]
			)
		);
	}//end testThePairsCountNotTheirOrder()

	/**
	 * A variant without values, with empty values, or not a list entry at all is no combination.
	 *
	 * @return void
	 */
	public function testAVariantWithoutValuesIsNoCombination(): void {
		$check = new VariantCombinations();

		$this->assertNull(
			actual: $check->duplicateCombination(
				variants: [
					['sku' => 'A'],
					['sku' => 'B', 'attributes' => []],
					['sku' => 'C', 'attributes' => ['Maat' => '']],
					'not a variant',
					['sku' => 'D', 'attributes' => 'Maat S'],
				]
			)
		);
	}//end testAVariantWithoutValuesIsNoCombination()

	/**
	 * Two variants with one code are named by that code, whatever the case.
	 *
	 * @return void
	 */
	public function testTheSameCodeTwiceIsNamed(): void {
		$check = new VariantCombinations();

		$this->assertNull(actual: $check->duplicateSku(variants: [['sku' => 'WS-S'], ['sku' => 'WS-M'], ['sku' => ''], []]));
		$this->assertSame(expected: 'WS-S', actual: $check->duplicateSku(variants: [['sku' => 'WS-S'], ['sku' => 'ws-s ']]));
	}//end testTheSameCodeTwiceIsNamed()

	/**
	 * Against a shirt that declares Maat (S, M): a variant without a size, a size
	 * L, and a colour the shirt does not declare are each named; S and M fit.
	 *
	 * @return void
	 */
	public function testAVariantNamesOneAllowedValuePerAttribute(): void {
		$check = new VariantCombinations();
		$declared = [['name' => 'Maat', 'values' => ['S', 'M']]];

		$this->assertNull(
			actual: $check->attributeMismatch(
				declared: $declared,
				variants: [['sku' => 'WS-S', 'attributes' => ['maat' => 's']], ['sku' => 'WS-M', 'attributes' => ['Maat' => 'M']]]
			)
		);
		$this->assertSame(
			expected: ['missing', ['WS-X', 'Maat']],
			actual: $check->attributeMismatch(declared: $declared, variants: [['sku' => 'WS-X', 'attributes' => []]])
		);
		$this->assertSame(
			expected: ['missing', ['#2', 'Maat']],
			actual: $check->attributeMismatch(declared: $declared, variants: [['attributes' => ['Maat' => 'S']], []])
		);
		$this->assertSame(
			expected: ['value', ['L', 'Maat']],
			actual: $check->attributeMismatch(declared: $declared, variants: [['sku' => 'WS-L', 'attributes' => ['Maat' => 'L']]])
		);
		$this->assertSame(
			expected: ['attribute', ['Kleur']],
			actual: $check->attributeMismatch(declared: $declared, variants: [['sku' => 'WS-S', 'attributes' => ['Maat' => 'S', 'Kleur' => 'Wit']]])
		);
	}//end testAVariantNamesOneAllowedValuePerAttribute()

	/**
	 * A product that declares no attributes, or only nameless ones, checks no variant.
	 *
	 * @return void
	 */
	public function testWithoutDeclaredAttributesNothingIsChecked(): void {
		$check = new VariantCombinations();
		$variants = [['sku' => 'A', 'attributes' => ['Maat' => 'XXL']]];

		$this->assertNull(actual: $check->attributeMismatch(declared: [], variants: $variants));
		$this->assertNull(actual: $check->attributeMismatch(declared: 'Maat', variants: $variants));
		$this->assertNull(actual: $check->attributeMismatch(declared: [['values' => ['S']], 'Maat'], variants: $variants));
	}//end testWithoutDeclaredAttributesNothingIsChecked()
}//end class
