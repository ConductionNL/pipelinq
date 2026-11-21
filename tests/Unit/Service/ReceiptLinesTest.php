<?php

/**
 * Unit tests for ReceiptLines.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ReceiptLines;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReceiptLines: the VAT lines and item lines a receipt prints.
 *
 * @covers \OCA\Pipelinq\Service\ReceiptLines
 */
class ReceiptLinesTest extends TestCase {

	/**
	 * Build the lines helper with a translating IL10N mock.
	 *
	 * @return ReceiptLines
	 */
	private function lines(): ReceiptLines {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(callback: static fn (string $text): string => 'tr:'.$text);

		return new ReceiptLines(l10n: $l10n);
	}//end lines()

	/**
	 * Each VAT row becomes a float line; a missing description is labelled from the rate.
	 *
	 * @return void
	 */
	public function testVatLines(): void {
		$result = $this->lines()->vatLines(
			vatRows: [
				['rate' => '21', 'base' => '100', 'tax' => '21', 'description' => 'High rate'],
				['rate' => 9.5, 'base' => 10, 'tax' => 0.95],
				['rate' => 0],
				'skip',
			]
		);

		self::assertSame(
			expected: [
				['rate' => 21.0, 'base' => 100.0, 'tax' => 21.0, 'label' => 'High rate'],
				['rate' => 9.5, 'base' => 10.0, 'tax' => 0.95, 'label' => '9.5% tr:VAT'],
				['rate' => 0.0, 'base' => 0.0, 'tax' => 0.0, 'label' => '% tr:VAT'],
			],
			actual: $result
		);
	}//end testVatLines()

	/**
	 * Non-array VAT input gives no lines.
	 *
	 * @return void
	 */
	public function testVatLinesOfANonArrayIsEmpty(): void {
		self::assertSame(expected: [], actual: $this->lines()->vatLines(vatRows: null));
		self::assertSame(expected: [], actual: $this->lines()->vatLines(vatRows: 'x'));
	}//end testVatLinesOfANonArrayIsEmpty()

	/**
	 * Item lines keep description, quantity and total, cast, and skip non-arrays.
	 *
	 * @return void
	 */
	public function testItemLines(): void {
		self::assertSame(
			expected: [
				['description' => 'Coffee', 'quantity' => 2.0, 'lineTotal' => 5.5],
				['description' => '', 'quantity' => 0.0, 'lineTotal' => 0.0],
			],
			actual: $this->lines()->itemLines(lines: [['description' => 'Coffee', 'quantity' => '2', 'lineTotal' => '5.50', 'sku' => 'c1'], [], 7])
		);
	}//end testItemLines()
}//end class
