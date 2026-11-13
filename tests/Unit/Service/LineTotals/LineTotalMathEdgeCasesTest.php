<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\LineTotals;

use OCA\Pipelinq\Service\LineTotals\LineTotalMath;
use PHPUnit\Framework\TestCase;

/**
 * The line arithmetic with missing fields, rounding and the rules for when a
 * lead's value follows its lines.
 *
 * @covers \OCA\Pipelinq\Service\LineTotals\LineTotalMath
 */
class LineTotalMathEdgeCasesTest extends TestCase {
	private LineTotalMath $math;

	protected function setUp(): void {
		$this->math = new LineTotalMath();
	}

	public function testALineWithoutQuantityOrPriceIsZero(): void {
		$this->assertSame(0.0, $this->math->quoteLineTotal(line: []));
		$this->assertSame(0.0, $this->math->quoteLineTotal(line: ['quantity' => 3]));
	}

	public function testALineTotalIsRoundedToCents(): void {
		$this->assertSame(6.67, $this->math->quoteLineTotal(line: ['quantity' => 3, 'unitPrice' => 3.333, 'discount' => 33.3]));
	}

	public function testAFullDiscountMakesTheLineFree(): void {
		$this->assertSame(0.0, $this->math->quoteLineTotal(line: ['quantity' => 4, 'unitPrice' => 25, 'discount' => 100]));
	}

	public function testQuoteVatIsRoundedToCents(): void {
		$totals = $this->math->quoteTotals(lines: [['quantity' => 1, 'unitPrice' => 9.99]], vatRate: 21.0);
		$this->assertSame(['subtotal' => 9.99, 'vatAmount' => 2.1, 'total' => 12.09], $totals);
	}

	public function testALeadValueTreatsAMissingTotalAsZero(): void {
		$this->assertSame(7.25, $this->math->leadValue(lines: [['total' => '7.25'], ['quantity' => 3]]));
	}

	public function testALeadWithoutAValueFollowsTheLines(): void {
		$this->assertSame(['calculatedValue' => 40.0, 'value' => 40.0], $this->math->leadPatch(lines: [['total' => 40]], lead: []));
	}

	public function testAZeroLeadValueFollowsTheLines(): void {
		$lead = ['value' => 0, 'calculatedValue' => 10];
		$this->assertSame(['calculatedValue' => 40.0, 'value' => 40.0], $this->math->leadPatch(lines: [['total' => 40]], lead: $lead));
	}

	public function testATypedValueWithoutAPreviousCalculationFollowsTheLines(): void {
		$lead = ['value' => 900, 'calculatedValue' => null];
		$this->assertSame(['calculatedValue' => 40.0, 'value' => 40.0], $this->math->leadPatch(lines: [['total' => 40]], lead: $lead));
	}

	public function testAValueEqualToThePreviousCalculationUpToCentsFollowsTheLines(): void {
		$lead = ['value' => '99.999', 'calculatedValue' => 100];
		$this->assertSame(['calculatedValue' => 40.0, 'value' => 40.0], $this->math->leadPatch(lines: [['total' => 40]], lead: $lead));
	}

	public function testANonNumericValueFollowsTheLines(): void {
		$lead = ['value' => 'n/a', 'calculatedValue' => 10];
		$this->assertSame(['calculatedValue' => 40.0, 'value' => 40.0], $this->math->leadPatch(lines: [['total' => 40]], lead: $lead));
	}
}
