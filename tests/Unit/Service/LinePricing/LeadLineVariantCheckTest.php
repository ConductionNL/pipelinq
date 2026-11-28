<?php

/**
 * Tests for LeadLineVariantCheck.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\LinePricing;

use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Service\LinePricing\LeadLineVariantCheck;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A lead line that names a variant code its product lacks is refused, naming
 * the code; a known code, no code and an unreadable product pass.
 *
 * @covers \OCA\Pipelinq\Service\LinePricing\LeadLineVariantCheck
 *
 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
 */
class LeadLineVariantCheckTest extends TestCase {
	/**
	 * The products the caller may read, by id: the shirt in S and M, and coffee without variants.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $products = [
		'p-shirt' => [
			'id'        => 'p-shirt',
			'name'      => 'Werkshirt',
			'unitPrice' => 25,
			'variants'  => [
				['sku' => 'WS-S', 'attributes' => ['Maat' => 'S']],
				['sku' => 'WS-M', 'attributes' => ['Maat' => 'M'], 'unitPrice' => 27.5],
			],
		],
		'p-plain' => ['id' => 'p-plain', 'name' => 'Koffie'],
	];

	/**
	 * The check over the products above.
	 *
	 * @return LeadLineVariantCheck The check.
	 */
	private function check(): LeadLineVariantCheck {
		$reader = $this->createMock(originalClassName: CallerObjectReaderInterface::class);
		$reader->method('read')->willReturnCallback(
			fn (string $id, string $schemaKey): ?array => match ($schemaKey) {
				'product_schema' => ($this->products[$id] ?? null),
				default => null,
			}
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		return new LeadLineVariantCheck(reader: $reader, l10n: $l10n);
	}//end check()

	/**
	 * The shirt in M is a known code.
	 *
	 * @return void
	 */
	public function testAKnownVariantCodePasses(): void {
		$this->assertNull(actual: $this->check()->refusal(line: ['product' => 'p-shirt', 'quantity' => 2, 'variantSku' => 'WS-M']));
	}//end testAKnownVariantCodePasses()

	/**
	 * The shirt in L is refused with the code named; surrounding spaces do not count.
	 *
	 * @return void
	 */
	public function testAnUnknownVariantCodeIsRefusedNamingIt(): void {
		$this->assertSame(
			expected: 'This product has no variant with the code WS-L',
			actual: $this->check()->refusal(line: ['product' => 'p-shirt', 'variantSku' => ' WS-L '])
		);
	}//end testAnUnknownVariantCodeIsRefusedNamingIt()

	/**
	 * A code on a product without variants is refused too.
	 *
	 * @return void
	 */
	public function testACodeOnAProductWithoutVariantsIsRefused(): void {
		$this->assertSame(
			expected: 'This product has no variant with the code K-1',
			actual: $this->check()->refusal(line: ['product' => 'p-plain', 'variantSku' => 'K-1'])
		);
	}//end testACodeOnAProductWithoutVariantsIsRefused()

	/**
	 * No code, a blank code, no product, an unreadable product and a code that is not text pass.
	 *
	 * @return void
	 */
	public function testEverythingElsePasses(): void {
		$lines = [
			['product' => 'p-shirt', 'quantity' => 1],
			['product' => 'p-shirt', 'variantSku' => '  '],
			['variantSku' => 'WS-L'],
			['product' => 'p-hidden', 'variantSku' => 'WS-L'],
			['product' => 'p-shirt', 'variantSku' => ['WS-L']],
		];

		foreach ($lines as $index => $line) {
			$this->assertNull(actual: $this->check()->refusal(line: $line), message: 'line '.$index);
		}
	}//end testEverythingElsePasses()
}//end class
