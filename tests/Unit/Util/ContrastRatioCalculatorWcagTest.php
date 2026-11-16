<?php

/**
 * Unit tests for ContrastRatioCalculator against WCAG reference ratios.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use InvalidArgumentException;
use OCA\Pipelinq\Util\ContrastRatioCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Known WCAG ratios either side of the AA line, argument order, the linear sRGB segment and input parsing.
 *
 * @covers \OCA\Pipelinq\Util\ContrastRatioCalculator
 */
class ContrastRatioCalculatorWcagTest extends TestCase {

	/**
	 * Reference pairs and their published ratios.
	 *
	 * @return array<string, array{0: string, 1: string, 2: float}>
	 */
	public static function referencePairs(): array {
		return [
			'#767676 on white, the lightest AA grey' => ['#767676', '#FFFFFF', 4.54],
			'#777777 on white, just below AA' => ['#777777', '#FFFFFF', 4.48],
			'pure red on white' => ['#FF0000', '#FFFFFF', 4.0],
			'brand blue on white' => ['#21468B', '#FFFFFF', 9.08],
			'near-black on black, the linear segment' => ['#0A0A0A', '#000000', 1.06],
		];
	}//end referencePairs()

	/**
	 * Each pair yields its reference ratio, rounded to two decimals.
	 *
	 * @param string $foreground The first colour.
	 * @param string $background The second colour.
	 * @param float  $expected   The reference ratio.
	 *
	 * @return void
	 *
	 * @dataProvider referencePairs
	 */
	public function testReferenceRatios(string $foreground, string $background, float $expected): void {
		$this->assertSame($expected, (new ContrastRatioCalculator())->calculate(color1: $foreground, color2: $background));
	}//end testReferenceRatios()

	/**
	 * The ratio does not depend on which colour is the lighter one.
	 *
	 * @return void
	 */
	public function testTheRatioIsSymmetric(): void {
		$calculator = new ContrastRatioCalculator();

		$this->assertSame(
			$calculator->calculate(color1: '#21468B', color2: '#FFFFFF'),
			$calculator->calculate(color1: '#FFFFFF', color2: '#21468B')
		);
	}//end testTheRatioIsSymmetric()

	/**
	 * The AA verdict on two colours follows the 4.5 line: 4.54 passes, 4.48 fails.
	 *
	 * @return void
	 */
	public function testColoursEitherSideOfTheAaLine(): void {
		$calculator = new ContrastRatioCalculator();

		$this->assertTrue($calculator->colorsMeetAa(color1: '#767676', color2: '#FFFFFF'));
		$this->assertFalse($calculator->colorsMeetAa(color1: '#777777', color2: '#FFFFFF'));
		$this->assertSame(4.5, ContrastRatioCalculator::AA_MINIMUM);
	}//end testColoursEitherSideOfTheAaLine()

	/**
	 * Surrounding whitespace is trimmed and lower-case shorthand expands per digit.
	 *
	 * @return void
	 */
	public function testWhitespaceAndLowerCaseShorthandAreAccepted(): void {
		$calculator = new ContrastRatioCalculator();

		$this->assertSame(
			$calculator->calculate(color1: '#AABBCC', color2: '#000000'),
			$calculator->calculate(color1: ' #abc ', color2: '000')
		);
	}//end testWhitespaceAndLowerCaseShorthandAreAccepted()

	/**
	 * Malformed colours are refused, naming the input.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malformedColours(): array {
		return [
			'four digits' => ['#abcd'],
			'non-hex digit' => ['#GGGGGG'],
			'empty' => [''],
			'eight digits with alpha' => ['#000000FF'],
		];
	}//end malformedColours()

	/**
	 * A malformed second colour is refused too.
	 *
	 * @param string $colour The malformed colour.
	 *
	 * @return void
	 *
	 * @dataProvider malformedColours
	 */
	public function testMalformedColoursAreRefused(string $colour): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid hex colour: ' . $colour);

		(new ContrastRatioCalculator())->calculate(color1: '#FFFFFF', color2: $colour);
	}//end testMalformedColoursAreRefused()
}//end class
