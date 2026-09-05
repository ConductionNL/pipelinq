<?php

/**
 * Tests for SiteContentCrawler.
 *
 * The crawler's important property is that "the crawl did not run" and "the
 * site has no content" are different answers. Everything else it does is an
 * ordering and a cap, both of which are asserted without a network because the
 * egress seam is mocked.
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

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Search\HtmlTextExtractor;
use OCA\Pipelinq\Service\Search\SiteContentCrawler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Search\SiteContentCrawler
 */
class SiteContentCrawlerTest extends TestCase {

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
	 * A row for one page.
	 *
	 * @param string $page The page.
	 * @param int $impressions Impressions.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $page, int $impressions): array {
		return ['query' => 'q', 'page' => $page, 'clicks' => 0, 'impressions' => $impressions, 'position' => 10.0];
	}//end row()

	/**
	 * Without a configured source nothing is fetched and the reason says so.
	 *
	 * @return void
	 */
	public function testReturnsNotConfiguredWithoutASource(): void {
		$this->egress->method('isConfigured')->willReturn(false);
		$this->egress->expects($this->never())->method('readUrl');

		$result = $this->crawler->crawl(rows: [$this->row('/a', 100)]);

		$this->assertFalse($result['crawled']);
		$this->assertSame(EgressResult::NOT_CONFIGURED, $result['failure']);
		$this->assertSame([], $result['pages']);
		$this->assertNotSame('', $result['reason']);
	}//end testReturnsNotConfiguredWithoutASource()

	/**
	 * A configured source that refuses every page is still not a crawl.
	 *
	 * @return void
	 */
	public function testAFailedFetchIsNotASuccessfulEmptyCrawl(): void {
		$this->egress->method('isConfigured')->willReturn(true);
		$this->egress->method('readUrl')->willReturn(
			EgressResult::failed(failure: EgressResult::REFUSED, reason: 'The site answered 403.')
		);

		$result = $this->crawler->crawl(rows: [$this->row('/a', 100)]);

		$this->assertFalse($result['crawled']);
		$this->assertSame(EgressResult::REFUSED, $result['failure']);
		$this->assertSame('The site answered 403.', $result['reason']);
	}//end testAFailedFetchIsNotASuccessfulEmptyCrawl()

	/**
	 * The pages with the most impressions are crawled first.
	 *
	 * @return void
	 */
	public function testCrawlsTheHighestImpressionPagesFirst(): void {
		$order = $this->crawler->pagesToCrawl(
			rows: [
				$this->row('/small', 5),
				$this->row('/large', 900),
				$this->row('/medium', 100),
			]
		);

		$this->assertSame(['/large', '/medium', '/small'], $order);
	}//end testCrawlsTheHighestImpressionPagesFirst()

	/**
	 * A page appearing on several rows is counted once, with its total.
	 *
	 * @return void
	 */
	public function testAPageIsCountedOnceWithItsTotalImpressions(): void {
		$order = $this->crawler->pagesToCrawl(
			rows: [
				$this->row('/a', 10),
				$this->row('/a', 10),
				$this->row('/b', 15),
			]
		);

		$this->assertSame(['/a', '/b'], $order);
	}//end testAPageIsCountedOnceWithItsTotalImpressions()

	/**
	 * The run stops at the cap.
	 *
	 * @return void
	 */
	public function testStopsAtTheCap(): void {
		$rows = [];
		for ($index = 0; $index < 80; $index++) {
			$rows[] = $this->row('/page-' . $index, (100 - $index));
		}

		$this->assertCount(50, $this->crawler->pagesToCrawl(rows: $rows, limit: 50));
		$this->assertCount(3, $this->crawler->pagesToCrawl(rows: $rows, limit: 3));
	}//end testStopsAtTheCap()

	/**
	 * A successful crawl carries the title and headings of each page.
	 *
	 * @return void
	 */
	public function testKeepsTheTitleAndHeadingsOfEachPage(): void {
		$this->egress->method('isConfigured')->willReturn(true);
		$this->egress->method('readUrl')->willReturn(
			EgressResult::success(body: '<html><head><title>Woo</title></head><body><h1>Verzoek</h1></body></html>')
		);

		$result = $this->crawler->crawl(rows: [$this->row('/woo', 100)]);

		$this->assertTrue($result['crawled']);
		$this->assertCount(1, $result['pages']);
		$this->assertSame('/woo', $result['pages'][0]['url']);
		$this->assertStringContainsString('Woo', $result['pages'][0]['text']);
		$this->assertStringContainsString('Verzoek', $result['pages'][0]['text']);
	}//end testKeepsTheTitleAndHeadingsOfEachPage()

	/**
	 * With no page in the window there is nothing to crawl, which is again
	 * not a successful empty crawl.
	 *
	 * @return void
	 */
	public function testNoPagesInTheWindowIsReportedAsNotCrawled(): void {
		$this->egress->method('isConfigured')->willReturn(true);

		$result = $this->crawler->crawl(rows: []);

		$this->assertFalse($result['crawled']);
		$this->assertSame([], $result['pages']);
	}//end testNoPagesInTheWindowIsReportedAsNotCrawled()

	/**
	 * The crawler declares no HTTP client of its own: every outbound read in
	 * this change leaves through the egress seam (ADR-067).
	 *
	 * @return void
	 */
	public function testConstructsNoHttpClient(): void {
		$parameters = (new \ReflectionClass(SiteContentCrawler::class))->getConstructor()?->getParameters() ?? [];
		foreach ($parameters as $parameter) {
			$this->assertNotSame(
				'OCP\Http\Client\IClientService',
				(string)$parameter->getType(),
				'no service in this change may construct its own HTTP client'
			);
		}
	}//end testConstructsNoHttpClient()
}//end class
