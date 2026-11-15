<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Search
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Search;

use OCA\Pipelinq\Service\Search\KeywordAnalysisService;
use PHPUnit\Framework\TestCase;

/**
 * Content gap ordering and the rows the per-page aggregation ignores.
 *
 * @covers \OCA\Pipelinq\Service\Search\KeywordAnalysisService
 * @uses \OCA\Pipelinq\Service\Search\ExpectedCtrCurve
 * @uses \OCA\Pipelinq\Service\Search\QueryContest
 */
class KeywordAnalysisServiceOrderingTest extends TestCase {

	/**
	 * One searchQueryDaily row.
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
		return ['query' => $query, 'page' => $page, 'clicks' => $clicks, 'impressions' => $impressions, 'position' => $position];
	}//end row()

	/**
	 * Several gaps come back most impressions first, ties broken by query.
	 *
	 * @return void
	 */
	public function testContentGapsAreOrderedByImpressionsThenQuery(): void {
		$gaps = (new KeywordAnalysisService())->contentGaps(
			rows: [
				$this->row('parkeervergunning aanvragen', '/a', 0, 300, 25.0),
				$this->row('afvalkalender ophalen', '/b', 0, 900, 25.0),
				$this->row('bouwvergunning aanvragen', '/c', 0, 300, 25.0),
			],
			pages: [['url' => '/contact', 'text' => 'Contact met de gemeente']]
		);

		$this->assertSame(
			['afvalkalender ophalen', 'bouwvergunning aanvragen', 'parkeervergunning aanvragen'],
			array_column($gaps, 'query')
		);
		$this->assertSame([900, 300, 300], array_column($gaps, 'impressions'));
	}//end testContentGapsAreOrderedByImpressionsThenQuery()

	/**
	 * A row with a blank page does not count as a competing page, so it cannot
	 * turn a single-page query into a cannibalisation finding.
	 *
	 * @return void
	 */
	public function testRowsWithABlankPageOrQueryAreIgnoredByCannibalisation(): void {
		$analysis = new KeywordAnalysisService();

		$competing = [
			$this->row('woo', '/a', 40, 800, 4.0),
			$this->row('woo', '/b', 0, 200, 30.0),
		];
		$this->assertCount(1, $analysis->cannibalisation(rows: $competing, minImpressions: 100, minPageShare: 0.20));

		$blank = [
			$this->row('woo', '/a', 40, 800, 4.0),
			$this->row('woo', '  ', 0, 200, 30.0),
			$this->row(' ', '/b', 0, 200, 30.0),
		];
		$this->assertSame([], $analysis->cannibalisation(rows: $blank, minImpressions: 100, minPageShare: 0.20));
	}//end testRowsWithABlankPageOrQueryAreIgnoredByCannibalisation()
}//end class
