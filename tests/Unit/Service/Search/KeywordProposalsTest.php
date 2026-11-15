<?php

/**
 * Unit tests for KeywordProposals: one window's report, analysed with the configured impression floor.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Search;

use OCA\Pipelinq\Service\Search\KeywordAnalysisService;
use OCA\Pipelinq\Service\Search\KeywordProposals;
use OCA\Pipelinq\Service\Search\SiteContentCrawler;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Search\KeywordProposals
 */
class KeywordProposalsTest extends TestCase {

	/**
	 * The report rows of the window.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const ROWS = [
		['query' => 'crm gemeente', 'page' => 'https://a.example/crm', 'impressions' => 400, 'position' => 12.0],
		['query' => 'klantcontact', 'page' => 'https://a.example/kcc', 'impressions' => 90, 'position' => 4.0],
	];

	/**
	 * The report double.
	 *
	 * @var SearchQueryReportService&MockObject
	 */
	private SearchQueryReportService&MockObject $report;

	/**
	 * The analysis double.
	 *
	 * @var KeywordAnalysisService&MockObject
	 */
	private KeywordAnalysisService&MockObject $analysis;

	/**
	 * The crawler double.
	 *
	 * @var SiteContentCrawler&MockObject
	 */
	private SiteContentCrawler&MockObject $crawler;

	/**
	 * The app config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Build the doubles: a report of two rows and a crawl that read two of three pages.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->report   = $this->createMock(SearchQueryReportService::class);
		$this->analysis = $this->createMock(KeywordAnalysisService::class);
		$this->crawler  = $this->createMock(SiteContentCrawler::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->report->method('rows')->willReturn(['from' => '2026-10-01', 'to' => '2026-10-28', 'rows' => self::ROWS]);
		$this->crawler->method('crawl')->willReturn(
			['crawled' => 3, 'failure' => false, 'reason' => '', 'pages' => ['https://a.example/crm' => 'text', 'https://a.example/kcc' => 'text']]
		);
	}//end setUp()

	/**
	 * The proposals under test.
	 *
	 * @return KeywordProposals
	 */
	private function proposals(): KeywordProposals {
		return new KeywordProposals(report: $this->report, analysis: $this->analysis, crawler: $this->crawler, appConfig: $this->appConfig);
	}//end proposals()

	/**
	 * The window's rows are analysed with the configured floor and the crawl is summarised.
	 *
	 * @return void
	 */
	public function testTheWindowIsAnalysedWithTheConfiguredFloor(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('pipelinq', 'search.striking_min_impressions', '')
			->willReturn('250');
		$this->report->expects($this->once())->method('rows')->with('2026-10-01', null, 'sc-domain:a.example');
		$this->crawler->expects($this->once())->method('crawl')->with(self::ROWS);
		$this->analysis->expects($this->once())->method('positionBuckets')->with(self::ROWS)->willReturn(['top3' => 1]);
		$this->analysis->expects($this->once())->method('strikingDistance')->with(self::ROWS, 250)->willReturn([['query' => 'crm gemeente']]);
		$this->analysis->expects($this->once())->method('cannibalisation')->with(self::ROWS, 250)->willReturn([]);
		$this->analysis->expects($this->once())
			->method('contentGaps')
			->with(self::ROWS, ['https://a.example/crm' => 'text', 'https://a.example/kcc' => 'text'], 250)
			->willReturn([['query' => 'klantcontact']]);

		$answer = $this->proposals()->forWindow(window: ['from' => '2026-10-01', 'to' => null, 'property' => 'sc-domain:a.example']);

		$this->assertSame(
			[
				'from' => '2026-10-01',
				'to' => '2026-10-28',
				'minImpressions' => 250,
				'buckets' => ['top3' => 1],
				'strikingDistance' => [['query' => 'crm gemeente']],
				'cannibalisation' => [],
				'gaps' => [['query' => 'klantcontact']],
				'crawl' => ['crawled' => 3, 'failure' => false, 'reason' => '', 'pages' => 2],
			],
			$answer
		);
	}//end testTheWindowIsAnalysedWithTheConfiguredFloor()

	/**
	 * A floor that is unset, zero, negative or not a number falls back to the analysis default.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unusableFloors(): array {
		return [
			'unset' => [''],
			'zero' => ['0'],
			'negative' => ['-5'],
			'not a number' => ['many'],
		];
	}//end unusableFloors()

	/**
	 * An unusable floor uses the default for every analysis that takes one.
	 *
	 * @dataProvider unusableFloors
	 *
	 * @param string $configured The stored floor.
	 *
	 * @return void
	 */
	public function testAnUnusableFloorUsesTheDefault(string $configured): void {
		$this->appConfig->method('getValueString')->willReturn($configured);
		$default = KeywordAnalysisService::DEFAULT_MIN_IMPRESSIONS;
		$this->analysis->expects($this->once())->method('strikingDistance')->with(self::ROWS, $default)->willReturn([]);
		$this->analysis->expects($this->once())->method('cannibalisation')->with(self::ROWS, $default)->willReturn([]);
		$this->analysis->expects($this->once())->method('contentGaps')->with(self::ROWS, $this->anything(), $default)->willReturn([]);

		$answer = $this->proposals()->forWindow(window: ['from' => null, 'to' => null, 'property' => null]);

		$this->assertSame($default, $answer['minImpressions']);
	}//end testAnUnusableFloorUsesTheDefault()

	/**
	 * A failed crawl is reported as such, with no pages, and the gaps are still asked for.
	 *
	 * @return void
	 */
	public function testAFailedCrawlIsReported(): void {
		$this->crawler = $this->createMock(SiteContentCrawler::class);
		$this->crawler->method('crawl')->willReturn(['crawled' => 0, 'failure' => true, 'reason' => 'robots.txt refuses', 'pages' => []]);
		$this->appConfig->method('getValueString')->willReturn('1');
		$this->analysis->expects($this->once())->method('contentGaps')->with(self::ROWS, [], 1)->willReturn([]);

		$answer = $this->proposals()->forWindow(window: ['from' => null, 'to' => null, 'property' => null]);

		$this->assertSame(['crawled' => 0, 'failure' => true, 'reason' => 'robots.txt refuses', 'pages' => 0], $answer['crawl']);
		$this->assertSame(1, $answer['minImpressions']);
	}//end testAFailedCrawlIsReported()
}//end class
