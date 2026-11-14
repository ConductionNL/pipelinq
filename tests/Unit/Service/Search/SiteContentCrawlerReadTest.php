<?php

/**
 * Tests for how SiteContentCrawler reads a window's pages.
 *
 * The existing crawler test covers the refusal paths one page at a time. These
 * cover what happens across several pages: rows without a page are skipped,
 * negative impressions never push a page down the order, a page that fails
 * does not cost the pages that succeed, and a failure without words still
 * explains itself.
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

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Search\HtmlTextExtractor;
use OCA\Pipelinq\Service\Search\SiteContentCrawler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Search\SiteContentCrawler
 * @uses \OCA\Pipelinq\Service\Egress\EgressResult
 * @uses \OCA\Pipelinq\Service\Search\HtmlTextExtractor
 */
class SiteContentCrawlerReadTest extends TestCase {

	/**
	 * The mocked egress seam.
	 *
	 * @var ConnectorEgress&MockObject
	 */
	private ConnectorEgress $egress;

	/**
	 * The crawler under test.
	 *
	 * @var SiteContentCrawler
	 */
	private SiteContentCrawler $crawler;

	/**
	 * Build the crawler over a mocked egress seam and the real extractor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->egress = $this->createMock(ConnectorEgress::class);
		$this->crawler = new SiteContentCrawler(egress: $this->egress, extractor: new HtmlTextExtractor());
	}//end setUp()

	/**
	 * Rows with no page, a blank page or only whitespace are not crawled.
	 *
	 * @return void
	 */
	public function testRowsWithoutAPageAreSkipped(): void {
		$order = $this->crawler->pagesToCrawl(
			rows: [
				['query' => 'q', 'impressions' => 500],
				['query' => 'q', 'page' => '', 'impressions' => 400],
				['query' => 'q', 'page' => '   ', 'impressions' => 300],
				['query' => 'q', 'page' => ' /kept ', 'impressions' => 10],
			]
		);

		$this->assertSame(['/kept'], $order);
	}//end testRowsWithoutAPageAreSkipped()

	/**
	 * Negative impressions count as none, so they cannot sink a page's total.
	 *
	 * @return void
	 */
	public function testNegativeImpressionsCountAsNone(): void {
		$order = $this->crawler->pagesToCrawl(
			rows: [
				['page' => '/a', 'impressions' => 20],
				['page' => '/a', 'impressions' => -100],
				['page' => '/b', 'impressions' => 15],
			]
		);

		$this->assertSame(['/a', '/b'], $order);
	}//end testNegativeImpressionsCountAsNone()

	/**
	 * A cap of zero or below crawls nothing, and a crawl with nothing to read
	 * never reaches the egress plane.
	 *
	 * @return void
	 */
	public function testANonPositiveCapCrawlsNothing(): void {
		$rows = [['page' => '/a', 'impressions' => 10]];

		$this->assertSame([], $this->crawler->pagesToCrawl(rows: $rows, limit: 0));
		$this->assertSame([], $this->crawler->pagesToCrawl(rows: $rows, limit: -5));

		$this->egress->method('isConfigured')->willReturn(true);
		$this->egress->expects($this->never())->method('readUrl');

		$result = $this->crawler->crawl(rows: $rows, limit: 0);

		$this->assertFalse($result['crawled']);
		$this->assertSame(EgressResult::NOT_CONFIGURED, $result['failure']);
		$this->assertSame('No page has been shown in search yet, so there is nothing to crawl.', $result['reason']);
	}//end testANonPositiveCapCrawlsNothing()

	/**
	 * One page failing does not cost the others: the crawl succeeds with the
	 * pages that were read, in crawl order, and reports no failure.
	 *
	 * @return void
	 */
	public function testAFailedPageDoesNotCostTheOthers(): void {
		$this->egress->method('isConfigured')->willReturn(true);
		$this->egress->expects($this->exactly(3))
			->method('readUrl')
			->willReturnCallback(
				function (string $configKey, string $url): EgressResult {
					$this->assertSame(SiteContentCrawler::SOURCE_KEY, $configKey);
					return match ($url) {
						'/first' => EgressResult::success(body: '<title>Eerste</title>'),
						'/second' => EgressResult::failed(failure: EgressResult::REFUSED, reason: 'The site answered 403.'),
						default => EgressResult::success(body: '<h1>Derde</h1>'),
					};
				}
			);

		$result = $this->crawler->crawl(
			rows: [
				['page' => '/third', 'impressions' => 10],
				['page' => '/first', 'impressions' => 300],
				['page' => '/second', 'impressions' => 200],
			]
		);

		$this->assertTrue($result['crawled']);
		$this->assertNull($result['failure']);
		$this->assertSame('', $result['reason']);
		$this->assertSame(
			[
				['url' => '/first', 'text' => 'Eerste'],
				['url' => '/third', 'text' => 'Derde'],
			],
			$result['pages']
		);
	}//end testAFailedPageDoesNotCostTheOthers()

	/**
	 * When every page fails, the last failure is the one reported.
	 *
	 * @return void
	 */
	public function testTheLastFailureIsReported(): void {
		$this->egress->method('isConfigured')->willReturn(true);
		$this->egress->method('readUrl')->willReturnOnConsecutiveCalls(
			EgressResult::failed(failure: EgressResult::REFUSED, reason: 'The site answered 403.'),
			EgressResult::failed(failure: EgressResult::UNAVAILABLE, reason: 'The site did not answer.')
		);

		$result = $this->crawler->crawl(
			rows: [
				['page' => '/a', 'impressions' => 20],
				['page' => '/b', 'impressions' => 10],
			]
		);

		$this->assertFalse($result['crawled']);
		$this->assertSame(EgressResult::UNAVAILABLE, $result['failure']);
		$this->assertSame('The site did not answer.', $result['reason']);
		$this->assertSame([], $result['pages']);
	}//end testTheLastFailureIsReported()

	/**
	 * A failure that carries no words is still explained to the reader.
	 *
	 * @return void
	 */
	public function testAFailureWithoutAReasonGetsTheGeneralOne(): void {
		$this->egress->method('isConfigured')->willReturn(true);
		$this->egress->method('readUrl')->willReturn(
			EgressResult::failed(failure: EgressResult::REFUSED, reason: '')
		);

		$result = $this->crawler->crawl(rows: [['page' => '/a', 'impressions' => 20]]);

		$this->assertFalse($result['crawled']);
		$this->assertSame(EgressResult::REFUSED, $result['failure']);
		$this->assertSame('No page could be read, so the content gap check did not run.', $result['reason']);
	}//end testAFailureWithoutAReasonGetsTheGeneralOne()
}//end class
