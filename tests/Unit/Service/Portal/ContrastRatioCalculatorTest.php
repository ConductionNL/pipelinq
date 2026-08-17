<?php

/**
 * Unit tests for ContrastRatioCalculator.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Util\ContrastRatioCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the WCAG contrast calculator.
 */
class ContrastRatioCalculatorTest extends TestCase {
	/**
	 * The calculator under test.
	 *
	 * @var ContrastRatioCalculator
	 */
	private ContrastRatioCalculator $calc;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calc = new ContrastRatioCalculator();
	}//end setUp()

	/**
	 * Black on white is the maximum 21:1.
	 *
	 * @return void
	 */
	public function testBlackOnWhiteIsMaxContrast(): void {
		$this->assertSame(21.0, $this->calc->calculate('#000000', '#FFFFFF'));
	}//end testBlackOnWhiteIsMaxContrast()

	/**
	 * Identical colours have a 1:1 ratio.
	 *
	 * @return void
	 */
	public function testIdenticalColoursAreOneToOne(): void {
		$this->assertSame(1.0, $this->calc->calculate('#777777', '#777777'));
	}//end testIdenticalColoursAreOneToOne()

	/**
	 * Cobalt brand on white passes AA; a light orange fails.
	 *
	 * @return void
	 */
	public function testAaThreshold(): void {
		$this->assertTrue($this->calc->colorsMeetAa('#21468B', '#FFFFFF'));
		$this->assertFalse($this->calc->colorsMeetAa('#FF9900', '#FFFFFF'));
	}//end testAaThreshold()

	/**
	 * meetsAaStandard is exclusive of values just below 4.5.
	 *
	 * @return void
	 */
	public function testMeetsAaBoundary(): void {
		$this->assertTrue($this->calc->meetsAaStandard(4.5));
		$this->assertFalse($this->calc->meetsAaStandard(4.49));
	}//end testMeetsAaBoundary()

	/**
	 * Shorthand and hash-less hex are accepted.
	 *
	 * @return void
	 */
	public function testShorthandAndNoHash(): void {
		$this->assertSame(21.0, $this->calc->calculate('000', 'fff'));
	}//end testShorthandAndNoHash()

	/**
	 * An invalid colour throws.
	 *
	 * @return void
	 */
	public function testInvalidColourThrows(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->calc->calculate('not-a-colour', '#FFFFFF');
	}//end testInvalidColourThrows()
}//end class
