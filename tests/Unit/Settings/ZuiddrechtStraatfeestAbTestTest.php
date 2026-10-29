<?php
/**
 * Example data for PqNieuwsbriefABTest: the Straatfeestseizoen newsletter is an
 * A/B test whose two arms are large enough, and far enough apart, for the page
 * to show its verdict and the "Hogere klikratio" pill on variant B.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Straatfeestseizoen A/B test.
 */
class ZuiddrechtStraatfeestAbTestTest extends TestCase {

	/**
	 * The fewest delivered per arm before the page computes a verdict
	 * (AB_MIN_DELIVERED in PerformanceDashboard.vue).
	 */
	private const MIN_DELIVERED = 500;

	/**
	 * The part as an array.
	 *
	 * @return array<string, mixed> The part.
	 */
	private function part(): array {
		$part = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/83-straatfeest-ab-test.json'), true);
		$this->assertIsArray(actual: $part);

		return $part;
	}//end part()

	/**
	 * Variant B points at the sent Straatfeestseizoen newsletter and went out
	 * with it, more than 24 hours before the install day.
	 *
	 * @return void
	 */
	public function testVariantBBelongsToTheStraatfeestNewsletter(): void {
		$variant = array_column($this->part()['objects'], 'data', 'key')['nieuwsbrief-straatfeest-variant-b'];
		$this->assertSame(expected: '{{object:nieuwsbrief-straatfeest.id}}', actual: $variant['abVariantOf']);
		$this->assertSame(expected: 'sent', actual: $variant['status']);
		$this->assertSame(expected: '{{datetime:-6 09:00}}', actual: $variant['sentAt']);
	}//end testVariantBBelongsToTheStraatfeestNewsletter()

	/**
	 * Both arms reach the minimum, and B clicks significantly more (p < 0.05).
	 *
	 * @return void
	 */
	public function testBothArmsAreLargeEnoughAndBWins(): void {
		$part = $this->part();
		$a    = $part['changes'][0]['set']['totals'];
		$b    = array_column($part['objects'], 'data', 'key')['nieuwsbrief-straatfeest-variant-b']['totals'];
		$this->assertSame(expected: 'Straatfeestseizoen: wat regel je', actual: $part['changes'][0]['where']['name']);
		$this->assertGreaterThanOrEqual(expected: self::MIN_DELIVERED, actual: $a['delivered']);
		$this->assertGreaterThanOrEqual(expected: self::MIN_DELIVERED, actual: $b['delivered']);
		$this->assertGreaterThan(expected: ($a['clicked'] / $a['delivered']), actual: ($b['clicked'] / $b['delivered']));
		// Chi-square on the 2x2 click table, one degree of freedom: 3.841 is p = 0.05.
		$this->assertGreaterThan(expected: 3.841, actual: $this->chiSquare(a: $a, b: $b));
	}//end testBothArmsAreLargeEnoughAndBWins()

	/**
	 * Each arm sends its own template, so variant A reads "Straatfeest deze zomer?
	 * Dit moet je regelen" and variant B "Je straatfeest, geregeld in 4 stappen".
	 *
	 * @return void
	 */
	public function testEachArmSendsItsOwnSubject(): void {
		$part    = $this->part();
		$objects = array_column($part['objects'], 'data', 'key');
		$this->assertSame(expected: 'Straatfeest deze zomer? Dit moet je regelen', actual: $objects['sjabloon-straatfeest-variant-a']['subject']);
		$this->assertSame(expected: '{{object:sjabloon-straatfeest-variant-a.id}}', actual: $part['changes'][0]['set']['templateId']);
		$this->assertSame(expected: '{{object:sjabloon-straatfeest.id}}', actual: $objects['nieuwsbrief-straatfeest-variant-b']['templateId']);

		$marketing = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/40-marketing.json'), true);
		$this->assertIsArray(actual: $marketing);
		$this->assertSame(expected: 'Je straatfeest, geregeld in 4 stappen', actual: array_column($marketing['objects'], 'data', 'key')['sjabloon-straatfeest']['subject']);
	}//end testEachArmSendsItsOwnSubject()

	/**
	 * The chi-square statistic of clicked against not clicked for two arms.
	 *
	 * @param array<string, int> $a Arm A totals.
	 * @param array<string, int> $b Arm B totals.
	 *
	 * @return float The statistic.
	 */
	private function chiSquare(array $a, array $b): float {
		$cells = [
			[$a['clicked'], ($a['delivered'] - $a['clicked'])],
			[$b['clicked'], ($b['delivered'] - $b['clicked'])],
		];
		$total = ($a['delivered'] + $b['delivered']);
		$chi   = 0.0;
		foreach ([0, 1] as $row) {
			foreach ([0, 1] as $col) {
				$expected = ((($cells[$row][0] + $cells[$row][1]) * ($cells[0][$col] + $cells[1][$col])) / $total);
				$chi     += ((($cells[$row][$col] - $expected) ** 2) / $expected);
			}
		}

		return $chi;
	}//end chiSquare()
}//end class
