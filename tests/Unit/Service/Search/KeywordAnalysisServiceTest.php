<?php

/**
 * Tests for KeywordAnalysisService.
 *
 * Every derivation is a predicate that can be wrong in a way no integration
 * test would notice, so every boundary is asserted from BOTH sides: a row just
 * inside and a row just outside. The rows are hand written rather than
 * fixtures, so the arithmetic under test is visible in the test itself.
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
use OCA\Pipelinq\Service\Search\KeywordAnalysisService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Search\KeywordAnalysisService
 */
class KeywordAnalysisServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var KeywordAnalysisService
	 */
	private KeywordAnalysisService $analysis;

	/**
	 * Build the service. It has no dependencies at all, which is the point.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->analysis = new KeywordAnalysisService();
	}//end setUp()

	/**
	 * One `searchQueryDaily` row.
	 *
	 * @param string $query The query.
	 * @param string $page The page.
	 * @param int $clicks Clicks.
	 * @param int $impressions Impressions.
	 * @param float $position Average position.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $query, string $page, int $clicks, int $impressions, float $position): array {
		return [
			'query' => $query,
			'page' => $page,
			'clicks' => $clicks,
			'impressions' => $impressions,
			'position' => $position,
			'date' => '2026-09-01',
		];
	}//end row()

	/**
	 * The bucket a query landed in, by label.
	 *
	 * @param array<int, array<string, mixed>> $buckets The buckets.
	 * @param string $label The label.
	 *
	 * @return array<string, mixed>
	 */
	private function bucket(array $buckets, string $label): array {
		foreach ($buckets as $bucket) {
			if ($bucket['bucket'] === $label) {
				return $bucket;
			}
		}

		$this->fail('No bucket ' . $label);
	}//end bucket()

	/**
	 * Exactly 3.0 is the top bucket, not the second one.
	 *
	 * @return void
	 */
	public function testPositionExactlyThreeIsInTheTopBucket(): void {
		$buckets = $this->analysis->positionBuckets(rows: [$this->row('a', '/a', 1, 10, 3.0)]);

		$this->assertSame(1, $this->bucket($buckets, '1-3')['queries']);
		$this->assertSame(0, $this->bucket($buckets, '4-10')['queries']);
	}//end testPositionExactlyThreeIsInTheTopBucket()

	/**
	 * Exactly 10.0 is the second bucket, not the third.
	 *
	 * @return void
	 */
	public function testPositionExactlyTenIsInTheSecondBucket(): void {
		$buckets = $this->analysis->positionBuckets(rows: [$this->row('a', '/a', 1, 10, 10.0)]);

		$this->assertSame(1, $this->bucket($buckets, '4-10')['queries']);
		$this->assertSame(0, $this->bucket($buckets, '11-20')['queries']);
	}//end testPositionExactlyTenIsInTheSecondBucket()

	/**
	 * Exactly 20.0 is the third bucket, not the tail.
	 *
	 * @return void
	 */
	public function testPositionExactlyTwentyIsInTheThirdBucket(): void {
		$buckets = $this->analysis->positionBuckets(rows: [$this->row('a', '/a', 1, 10, 20.0)]);

		$this->assertSame(1, $this->bucket($buckets, '11-20')['queries']);
		$this->assertSame(0, $this->bucket($buckets, '21+')['queries']);
	}//end testPositionExactlyTwentyIsInTheThirdBucket()

	/**
	 * Just above the last bound is the tail.
	 *
	 * @return void
	 */
	public function testPositionJustAboveTwentyIsInTheTail(): void {
		$buckets = $this->analysis->positionBuckets(rows: [$this->row('a', '/a', 1, 10, 20.1)]);

		$this->assertSame(1, $this->bucket($buckets, '21+')['queries']);
	}//end testPositionJustAboveTwentyIsInTheTail()

	/**
	 * A query with no impressions has no position, so it is counted nowhere.
	 *
	 * @return void
	 */
	public function testAQueryWithNoImpressionsIsInNoBucket(): void {
		$buckets = $this->analysis->positionBuckets(rows: [$this->row('a', '/a', 0, 0, 0.0)]);

		foreach ($buckets as $bucket) {
			$this->assertSame(0, $bucket['queries'], 'bucket ' . $bucket['bucket']);
		}
	}//end testAQueryWithNoImpressionsIsInNoBucket()

	/**
	 * Clicks and impressions are summed per bucket, and a query's rows are
	 * aggregated before it is bucketed.
	 *
	 * @return void
	 */
	public function testABucketSumsTheClicksAndImpressionsOfItsQueries(): void {
		$buckets = $this->analysis->positionBuckets(
			rows: [
				$this->row('a', '/a', 2, 100, 12.0),
				$this->row('a', '/a', 3, 100, 12.0),
				$this->row('b', '/b', 1, 50, 15.0),
			]
		);

		$third = $this->bucket($buckets, '11-20');
		$this->assertSame(2, $third['queries']);
		$this->assertSame(6, $third['clicks']);
		$this->assertSame(250, $third['impressions']);
	}//end testABucketSumsTheClicksAndImpressionsOfItsQueries()

	/**
	 * At the floor a query qualifies for striking distance.
	 *
	 * @return void
	 */
	public function testAQueryAtTheImpressionFloorQualifies(): void {
		$found = $this->analysis->strikingDistance(rows: [$this->row('woo', '/woo', 0, 100, 12.0)], minImpressions: 100);

		$this->assertCount(1, $found);
		$this->assertSame('woo', $found[0]['query']);
	}//end testAQueryAtTheImpressionFloorQualifies()

	/**
	 * One impression below the floor it does not.
	 *
	 * @return void
	 */
	public function testAQueryBelowTheImpressionFloorIsIgnored(): void {
		$found = $this->analysis->strikingDistance(rows: [$this->row('woo', '/woo', 0, 99, 12.0)], minImpressions: 100);

		$this->assertSame([], $found);
	}//end testAQueryBelowTheImpressionFloorIsIgnored()

	/**
	 * Position 8.0 is inside the band.
	 *
	 * @return void
	 */
	public function testPositionEightIsInsideTheBand(): void {
		$found = $this->analysis->strikingDistance(rows: [$this->row('woo', '/woo', 0, 500, 8.0)]);

		$this->assertCount(1, $found);
	}//end testPositionEightIsInsideTheBand()

	/**
	 * Position 20.0 is inside the band.
	 *
	 * @return void
	 */
	public function testPositionTwentyIsInsideTheBand(): void {
		$found = $this->analysis->strikingDistance(rows: [$this->row('woo', '/woo', 0, 500, 20.0)]);

		$this->assertCount(1, $found);
	}//end testPositionTwentyIsInsideTheBand()

	/**
	 * Just outside either end it is not a finding.
	 *
	 * @return void
	 */
	public function testAPositionJustOutsideTheBandIsIgnored(): void {
		$this->assertSame([], $this->analysis->strikingDistance(rows: [$this->row('a', '/a', 0, 500, 7.9)]));
		$this->assertSame([], $this->analysis->strikingDistance(rows: [$this->row('a', '/a', 0, 500, 20.1)]));
	}//end testAPositionJustOutsideTheBandIsIgnored()

	/**
	 * A query already earning what its position earns is not a finding; the
	 * same query with fewer clicks is.
	 *
	 * @return void
	 */
	public function testAQueryAtOrAboveTheExpectedRateIsNotAFinding(): void {
		$expected = ExpectedCtrCurve::at(position: 10.0);
		$earning = (int)ceil((1000 * $expected));

		$this->assertSame([], $this->analysis->strikingDistance(rows: [$this->row('a', '/a', $earning, 1000, 10.0)]));

		$under = $this->analysis->strikingDistance(rows: [$this->row('a', '/a', 1, 1000, 10.0)]);
		$this->assertCount(1, $under);
		$this->assertGreaterThan(0.0, $under[0]['shortfall']);
	}//end testAQueryAtOrAboveTheExpectedRateIsNotAFinding()

	/**
	 * Findings come back with the most impressions first.
	 *
	 * @return void
	 */
	public function testFindingsAreOrderedByImpressions(): void {
		$found = $this->analysis->strikingDistance(
			rows: [
				$this->row('small', '/s', 0, 200, 12.0),
				$this->row('large', '/l', 0, 900, 12.0),
			]
		);

		$this->assertSame(['large', 'small'], array_column($found, 'query'));
	}//end testFindingsAreOrderedByImpressions()

	/**
	 * A second page below the share floor does not make a query a
	 * cannibalisation finding; at the floor it does.
	 *
	 * @return void
	 */
	public function testASecondPageBelowTheShareFloorIsNotAFinding(): void {
		$rows = [
			$this->row('woo', '/a', 20, 950, 4.0),
			$this->row('woo', '/b', 0, 50, 30.0),
		];

		$this->assertSame([], $this->analysis->cannibalisation(rows: $rows, minImpressions: 100, minPageShare: 0.20));
	}//end testASecondPageBelowTheShareFloorIsNotAFinding()

	/**
	 * At the share floor the same shape is a finding.
	 *
	 * @return void
	 */
	public function testASecondPageAtTheShareFloorIsAFinding(): void {
		$rows = [
			$this->row('woo', '/a', 40, 800, 4.0),
			$this->row('woo', '/b', 0, 200, 30.0),
		];

		$found = $this->analysis->cannibalisation(rows: $rows, minImpressions: 100, minPageShare: 0.20);

		$this->assertCount(1, $found);
		$this->assertSame('woo', $found[0]['query']);
		$this->assertSame('/a', $found[0]['bestPage']);
		$this->assertCount(2, $found[0]['pages']);
	}//end testASecondPageAtTheShareFloorIsAFinding()

	/**
	 * A loss inside the materiality margin is not a finding, and one at the
	 * margin is. Two pages at 100 and 90 clicks per 1000 give a combined rate
	 * exactly ten percent below the better page, which is the boundary.
	 *
	 * @return void
	 */
	public function testALossInsideTheMarginIsNotAFinding(): void {
		$rows = [
			$this->row('woo', '/a', 100, 1000, 4.0),
			$this->row('woo', '/b', 98, 1000, 5.0),
		];

		$this->assertSame([], $this->analysis->cannibalisation(rows: $rows, minImpressions: 100, materiality: 0.10));
	}//end testALossInsideTheMarginIsNotAFinding()

	/**
	 * At the margin it is a finding.
	 *
	 * @return void
	 */
	public function testALossAtTheMarginIsAFinding(): void {
		$rows = [
			$this->row('woo', '/a', 100, 1000, 4.0),
			$this->row('woo', '/b', 80, 1000, 5.0),
		];

		$found = $this->analysis->cannibalisation(rows: $rows, minImpressions: 100, materiality: 0.10);

		$this->assertCount(1, $found);
		$this->assertSame(0.09, $found[0]['combinedCtr']);
		$this->assertSame(0.1, $found[0]['bestPageCtr']);
	}//end testALossAtTheMarginIsAFinding()

	/**
	 * A query served by one page is never cannibalisation, whatever its rate.
	 *
	 * @return void
	 */
	public function testASinglePageQueryIsNeverAFinding(): void {
		$rows = [
			$this->row('woo', '/a', 0, 500, 4.0),
			$this->row('woo', '/a', 0, 500, 4.0),
		];

		$this->assertSame([], $this->analysis->cannibalisation(rows: $rows, minImpressions: 100));
	}//end testASinglePageQueryIsNeverAFinding()

	/**
	 * Below the impression floor a query is not examined at all.
	 *
	 * @return void
	 */
	public function testAQuietQueryIsNotCannibalisation(): void {
		$rows = [
			$this->row('woo', '/a', 5, 50, 4.0),
			$this->row('woo', '/b', 0, 40, 9.0),
		];

		$this->assertSame([], $this->analysis->cannibalisation(rows: $rows, minImpressions: 100));
	}//end testAQuietQueryIsNotCannibalisation()

	/**
	 * A page carrying only some of the query's tokens leaves it a gap; one
	 * carrying all of them closes it.
	 *
	 * @return void
	 */
	public function testAPageCarryingOnlySomeTokensIsAGap(): void {
		$rows = [$this->row('woo verzoek indienen', '/woo', 0, 400, 30.0)];

		$partial = $this->analysis->contentGaps(
			rows: $rows,
			pages: [['url' => '/verzoek', 'text' => 'Verzoek indienen']]
		);
		$this->assertCount(1, $partial);
		$this->assertSame('woo verzoek indienen', $partial[0]['query']);

		$complete = $this->analysis->contentGaps(
			rows: $rows,
			pages: [['url' => '/woo', 'text' => 'Woo verzoek indienen bij de gemeente']]
		);
		$this->assertSame([], $complete);
	}//end testAPageCarryingOnlySomeTokensIsAGap()

	/**
	 * A page carrying every token is not a gap even when another page
	 * carries none of them.
	 *
	 * @return void
	 */
	public function testAPageCarryingEveryTokenIsNotAGap(): void {
		$gaps = $this->analysis->contentGaps(
			rows: [$this->row('woo verzoek indienen', '/woo', 0, 400, 30.0)],
			pages: [
				['url' => '/contact', 'text' => 'Contact'],
				['url' => '/woo', 'text' => 'Een woo verzoek indienen'],
			]
		);

		$this->assertSame([], $gaps);
	}//end testAPageCarryingEveryTokenIsNotAGap()

	/**
	 * Stop words and short tokens do not decide a gap, so a long natural
	 * question is answered by a page carrying only its topic words.
	 *
	 * @return void
	 */
	public function testStopWordsAndShortTokensAreIgnored(): void {
		$gaps = $this->analysis->contentGaps(
			rows: [$this->row('hoe kan ik een woo verzoek indienen', '/woo', 0, 400, 30.0)],
			pages: [['url' => '/woo', 'text' => 'Woo verzoek indienen']]
		);

		$this->assertSame([], $gaps);
	}//end testStopWordsAndShortTokensAreIgnored()

	/**
	 * Diacritics and case are folded on both sides.
	 *
	 * @return void
	 */
	public function testDiacriticsAndCaseAreFolded(): void {
		$gaps = $this->analysis->contentGaps(
			rows: [$this->row('subsidie régeling', '/s', 0, 400, 30.0)],
			pages: [['url' => '/s', 'text' => 'SUBSIDIE Regeling 2026']]
		);

		$this->assertSame([], $gaps);
	}//end testDiacriticsAndCaseAreFolded()

	/**
	 * With no crawled pages nothing is reported as a gap, because "we did not
	 * look" is not "there are none". The caller reports the crawl status.
	 *
	 * @return void
	 */
	public function testNoCrawledPagesMeansNoGapsAreClaimed(): void {
		$gaps = $this->analysis->contentGaps(
			rows: [$this->row('woo verzoek indienen', '/woo', 0, 400, 30.0)],
			pages: []
		);

		$this->assertSame([], $gaps);
	}//end testNoCrawledPagesMeansNoGapsAreClaimed()

	/**
	 * A query below the floor is not reported as a gap either.
	 *
	 * @return void
	 */
	public function testAQuietQueryIsNotAGap(): void {
		$gaps = $this->analysis->contentGaps(
			rows: [$this->row('zeer zeldzame zoekterm', '/x', 0, 3, 40.0)],
			pages: [['url' => '/x', 'text' => 'Iets anders']]
		);

		$this->assertSame([], $gaps);
	}//end testAQuietQueryIsNotAGap()

	/**
	 * Tokenisation drops short words and stop words, and deduplicates.
	 *
	 * @return void
	 */
	public function testSignificantTokensDropsStopWordsAndShortWords(): void {
		$this->assertSame(
			['woo', 'verzoek', 'indienen'],
			$this->analysis->significantTokens(value: 'Hoe kan ik een woo verzoek indienen, woo?')
		);
	}//end testSignificantTokensDropsStopWordsAndShortWords()
}//end class
