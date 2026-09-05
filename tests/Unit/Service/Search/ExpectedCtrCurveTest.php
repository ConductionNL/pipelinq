<?php

/**
 * Tests for ExpectedCtrCurve.
 *
 * The curve decides what "under-performing its position" means, so the two
 * things worth asserting are the interpolation between two integer positions
 * (Search Console reports fractional positions, and truncating them would
 * judge a query at 10.9 against position ten) and the two ends, where a naive
 * lookup would return null and turn every deep result into a finding.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Search
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Search;

use OCA\Pipelinq\Service\Search\ExpectedCtrCurve;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Search\ExpectedCtrCurve
 */
class ExpectedCtrCurveTest extends TestCase {

	/**
	 * Halfway between two integers is halfway between their rates.
	 *
	 * @return void
	 */
	public function testInterpolatesBetweenTwoIntegerPositions(): void {
		$expected = ((ExpectedCtrCurve::CURVE[10] + ExpectedCtrCurve::CURVE[11]) / 2);

		$this->assertEqualsWithDelta($expected, ExpectedCtrCurve::at(position: 10.5), 0.000001);
	}//end testInterpolatesBetweenTwoIntegerPositions()

	/**
	 * An integer position answers its own value exactly.
	 *
	 * @return void
	 */
	public function testAnIntegerPositionAnswersItsOwnValue(): void {
		$this->assertEqualsWithDelta(ExpectedCtrCurve::CURVE[8], ExpectedCtrCurve::at(position: 8.0), 0.000001);
		$this->assertEqualsWithDelta(ExpectedCtrCurve::CURVE[3], ExpectedCtrCurve::at(position: 3.0), 0.000001);
	}//end testAnIntegerPositionAnswersItsOwnValue()

	/**
	 * Beyond the last named position the curve is flat rather than absent.
	 *
	 * @return void
	 */
	public function testIsFlatBeyondPositionTwenty(): void {
		$this->assertSame(ExpectedCtrCurve::TAIL_RATE, ExpectedCtrCurve::at(position: 20.0));
		$this->assertSame(ExpectedCtrCurve::TAIL_RATE, ExpectedCtrCurve::at(position: 47.3));
	}//end testIsFlatBeyondPositionTwenty()

	/**
	 * A position below one uses the first value, so a rounding artefact
	 * cannot fall off the front of the table.
	 *
	 * @return void
	 */
	public function testAPositionBelowOneUsesTheFirstValue(): void {
		$this->assertSame(ExpectedCtrCurve::CURVE[1], ExpectedCtrCurve::at(position: 0.4));
		$this->assertSame(ExpectedCtrCurve::CURVE[1], ExpectedCtrCurve::at(position: 1.0));
	}//end testAPositionBelowOneUsesTheFirstValue()

	/**
	 * The curve descends. A table edited into a non-monotonic shape would
	 * make "below what this position earns" mean nothing.
	 *
	 * @return void
	 */
	public function testTheCurveNeverRises(): void {
		$previous = 1.0;
		foreach (ExpectedCtrCurve::CURVE as $rate) {
			$this->assertLessThan($previous, $rate);
			$previous = $rate;
		}
	}//end testTheCurveNeverRises()
}//end class
