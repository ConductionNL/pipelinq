<?php

/**
 * Tests for the shape of a cannibalisation finding.
 *
 * The existing analysis test decides whether a query is a finding. These pin
 * down what the finding then says: the query's totals include pages too small
 * to contend, a contender's rate and position are aggregated across days, and
 * the contest's pages come back in order with their numbers intact.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Search
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Search;

use OCA\Pipelinq\Service\Search\KeywordAnalysisService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Search\KeywordAnalysisService
 * @covers \OCA\Pipelinq\Service\Search\QueryContest
 */
class KeywordAnalysisCannibalisationContestTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var KeywordAnalysisService
	 */
	private KeywordAnalysisService $analysis;

	/**
	 * Build the service.
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
	 * @param string $query       The query.
	 * @param string $page        The page.
	 * @param int    $clicks      Clicks.
	 * @param int    $impressions Impressions.
	 * @param float  $position    Average position.
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
		];
	}//end row()

	/**
	 * The finding carries the query's totals over every page, the combined
	 * and best rates, and each contender with its days aggregated.
	 *
	 * @return void
	 */
	public function testTheFindingCarriesTheWholeContest(): void {
		$found = $this->analysis->cannibalisation(
			rows: [
				$this->row('woo', '/a', 20, 400, 2.0),
				$this->row('woo', '/a', 20, 400, 6.0),
				$this->row('woo', '/b', 0, 250, 30.0),
				$this->row('woo', '/c', 0, 10, 50.0),
			],
			minImpressions: 100,
			minPageShare: 0.20,
			materiality: 0.10
		);

		$this->assertSame(
			[
				[
					'query' => 'woo',
					'clicks' => 40,
					'impressions' => 1060,
					'combinedCtr' => 0.0377,
					'bestPage' => '/a',
					'bestPageCtr' => 0.05,
					'pages' => [
						['page' => '/a', 'clicks' => 40, 'impressions' => 800, 'ctr' => 0.05, 'position' => 4.0],
						['page' => '/b', 'clicks' => 0, 'impressions' => 250, 'ctr' => 0.0, 'position' => 30.0],
					],
				],
			],
			$found
		);
	}//end testTheFindingCarriesTheWholeContest()

	/**
	 * The best page is the one with the highest rate, not the most traffic.
	 *
	 * @return void
	 */
	public function testTheBestPageIsTheHighestRate(): void {
		$found = $this->analysis->cannibalisation(
			rows: [
				$this->row('vergunning', '/big', 10, 700, 5.0),
				$this->row('vergunning', '/small', 30, 300, 8.0),
			],
			minImpressions: 100,
			minPageShare: 0.20,
			materiality: 0.10
		);

		$this->assertCount(1, $found);
		$this->assertSame('/small', $found[0]['bestPage']);
		$this->assertSame(0.1, $found[0]['bestPageCtr']);
		$this->assertSame(0.04, $found[0]['combinedCtr']);
		$this->assertSame(['/big', '/small'], array_column($found[0]['pages'], 'page'));
	}//end testTheBestPageIsTheHighestRate()

	/**
	 * Rows without a query or a page take no part in any contest.
	 *
	 * @return void
	 */
	public function testRowsWithoutAQueryOrPageAreIgnored(): void {
		$found = $this->analysis->cannibalisation(
			rows: [
				$this->row('woo', '/a', 40, 800, 4.0),
				$this->row('', '/b', 0, 900, 30.0),
				$this->row('woo', '  ', 0, 900, 30.0),
			],
			minImpressions: 100,
			minPageShare: 0.20,
			materiality: 0.10
		);

		$this->assertSame([], $found);
	}//end testRowsWithoutAQueryOrPageAreIgnored()
}//end class
