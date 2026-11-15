<?php

/**
 * Pins how a sitemap and a sitemap index are collected: requests, failures, depth and merge order.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Competitor
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Competitor;

use OCA\Pipelinq\Service\Competitor\SitemapWatchReader;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use PHPUnit\Framework\TestCase;

/**
 * A served set of documents, the URLs asked for and the outcome.
 *
 * @covers \OCA\Pipelinq\Service\Competitor\SitemapWatchReader
 * @uses \OCA\Pipelinq\Service\Competitor\QuietXmlParser
 * @uses \OCA\Pipelinq\Service\Competitor\SitemapHop
 * @uses \OCA\Pipelinq\Service\Competitor\WatchOutcome
 * @uses \OCA\Pipelinq\Service\Egress\EgressResult
 */
class SitemapCollectGoldenTest extends TestCase {

	/**
	 * Reads the given URL over the served documents.
	 *
	 * @param array<string, EgressResult> $served URL to answer.
	 * @param string                      $url    The sitemap URL.
	 *
	 * @return array{0: \OCA\Pipelinq\Service\Competitor\WatchOutcome, 1: array<int, array<int, string>>}
	 */
	private function read(array $served, string $url): array {
		$asked  = [];
		$egress = $this->createMock(ConnectorEgress::class);
		$egress->method('readUrl')->willReturnCallback(
			function (string $configKey, string $url, array $config = [], string $sourceId = '') use (&$asked, $served): EgressResult {
				$asked[] = [$url, $sourceId];
				return $served[$url] ?? EgressResult::failed(failure: EgressResult::UNAVAILABLE, reason: 'gone');
			}
		);
		return [(new SitemapWatchReader(egress: $egress))->read(url: $url, previous: [], sourceId: 'src-1'), $asked];
	}//end read()

	/**
	 * A urlset document.
	 *
	 * @param array<string, string> $locations Location to lastmod.
	 *
	 * @return EgressResult
	 */
	private static function urlset(array $locations): EgressResult {
		$body = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		foreach ($locations as $loc => $mod) {
			$body .= '<url><loc>'.$loc.'</loc><lastmod>'.$mod.'</lastmod></url>';
		}

		return EgressResult::success(body: $body.'</urlset>');
	}//end urlset()

	/**
	 * A sitemap index document.
	 *
	 * @param array<int, string> $children Child sitemap URLs.
	 *
	 * @return EgressResult
	 */
	private static function index(array $children): EgressResult {
		$body = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		foreach ($children as $child) {
			$body .= '<sitemap><loc>'.$child.'</loc></sitemap>';
		}

		return EgressResult::success(body: $body.'</sitemapindex>');
	}//end index()

	/**
	 * Every collection shape reads exactly as pinned.
	 *
	 * @return void
	 */
	public function testCollection(): void {
		$served = [
			'https://s.example/index.xml'  => self::index(['https://s.example/a.xml', 'https://s.example/missing.xml', 'https://s.example/inner.xml', 'https://s.example/b.xml', 'https://s.example/junk.xml']),
			'https://s.example/a.xml'      => self::urlset(['https://s.example/1' => '2026-09-01', 'https://s.example/2' => '2026-09-02']),
			'https://s.example/b.xml'      => self::urlset(['https://s.example/2' => '2026-10-09', 'https://s.example/3' => '2026-09-03']),
			'https://s.example/inner.xml'  => self::index(['https://s.example/a.xml']),
			'https://s.example/junk.xml'   => EgressResult::success(body: 'not xml'),
			'https://s.example/plain.xml'  => self::urlset(['https://s.example/9' => '2026-09-09']),
		];

		[$outcome, $asked] = $this->read(served: $served, url: 'https://s.example/index.xml');
		$this->assertSame('ok', $outcome->outcome);
		$this->assertSame(['https://s.example/1', 'https://s.example/2', 'https://s.example/3'], array_keys($outcome->state['locations']));
		$this->assertSame('2026-09-02', (string)$outcome->state['locations']['https://s.example/2']);
		$this->assertSame(
			array_map(static fn (string $u): array => [$u, 'src-1'], ['https://s.example/index.xml', 'https://s.example/a.xml', 'https://s.example/missing.xml', 'https://s.example/inner.xml', 'https://s.example/b.xml', 'https://s.example/junk.xml']),
			$asked
		);

		[$outcome] = $this->read(served: $served, url: 'https://s.example/plain.xml');
		$this->assertSame(['https://s.example/9'], array_keys($outcome->state['locations']));

		[$outcome, $asked] = $this->read(served: $served, url: 'https://s.example/missing.xml');
		$this->assertSame(['unavailable', 'gone'], [$outcome->outcome, $outcome->reason]);
		[$outcome] = $this->read(served: $served, url: 'https://s.example/junk.xml');
		$this->assertSame(['unparsable', 'The answer from https://s.example/junk.xml is not a sitemap.'], [$outcome->outcome, $outcome->reason]);
		[$outcome] = $this->read(served: $served, url: 'https://s.example/inner.xml');
		$this->assertSame('ok', $outcome->outcome);
	}//end testCollection()
}//end class
