<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\LineTotals;

use OCA\Pipelinq\Service\LineTotals\LineTotalMath;
use PHPUnit\Framework\TestCase;

/**
 * The quote and lead arithmetic.
 */
class LineTotalMathTest extends TestCase {
	public function testQuoteLineTotalTakesOffThePercentDiscount(): void {
		$this->assertSame(180.0, (new LineTotalMath())->quoteLineTotal(line: ['quantity' => 2, 'unitPrice' => 100, 'discount' => 10]));
	}

	public function testQuoteLineTotalIgnoresAStoredTotal(): void {
		$this->assertSame(45.0, (new LineTotalMath())->quoteLineTotal(line: ['quantity' => 1, 'unitPrice' => 45, 'total' => 999]));
	}

	public function testQuoteTotalsAtTheQuotesVatRate(): void {
		$lines  = [['quantity' => 2, 'unitPrice' => 100, 'discount' => 10], ['quantity' => 1, 'unitPrice' => 45]];
		$totals = (new LineTotalMath())->quoteTotals(lines: $lines, vatRate: 21);
		$this->assertSame(['subtotal' => 225.0, 'vatAmount' => 47.25, 'total' => 272.25], $totals);
	}

	public function testQuoteWithoutLinesIsZero(): void {
		$this->assertSame(['subtotal' => 0.0, 'vatAmount' => 0.0, 'total' => 0.0], (new LineTotalMath())->quoteTotals(lines: [], vatRate: 9));
	}

	public function testLeadValueSumsTheServerLineTotals(): void {
		$this->assertSame(3150.5, (new LineTotalMath())->leadValue(lines: [['total' => 3000], ['total' => '150.5'], []]));
	}

	public function testALeadValueFollowsTheLinesUntilItIsTypedOver(): void {
		$math = new LineTotalMath();
		$this->assertSame(['calculatedValue' => 3000.0, 'value' => 3000.0], $math->leadPatch(lines: [['total' => 3000]], lead: []));
		$this->assertSame(['calculatedValue' => 3200.0, 'value' => 3200.0], $math->leadPatch(lines: [['total' => 3200]], lead: ['value' => 3000, 'calculatedValue' => 3000]));
		$this->assertSame(['calculatedValue' => 3200.0, 'value' => 3200.0], $math->leadPatch(lines: [['total' => 3200]], lead: ['value' => 5000]));
	}

	public function testATypedOverValueStays(): void {
		$patch = (new LineTotalMath())->leadPatch(lines: [['total' => 3200]], lead: ['value' => 6000, 'calculatedValue' => 3000]);
		$this->assertSame(['calculatedValue' => 3200.0], $patch);
	}
}
