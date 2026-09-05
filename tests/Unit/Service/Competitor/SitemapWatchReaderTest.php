<?php

/**
 * Tests for SitemapWatchReader.
 *
 * The diff is where this can go quietly wrong. New and changed have to stay
 * apart, and a sitemap without `lastmod` values must not report its entire
 * contents on every run, which is the failure that makes a watch useless
 * without ever looking broken.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Competitor
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Competitor;

use OCA\Pipelinq\Service\Competitor\SitemapWatchReader;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Competitor\SitemapWatchReader
 */
class SitemapWatchReaderTest extends TestCase {

	/**
	 * The mocked egress seam.
	 *
	 * @var ConnectorEgress&MockObject
	 */
	private ConnectorEgress $egress;

	/**
	 * The reader under test.
	 *
	 * @var SitemapWatchReader
	 */
	private SitemapWatchReader $reader;

	/**
	 * Build the reader over a mocked egress seam.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->egress = $this->createMock(ConnectorEgress::class);
		$this->reader = new SitemapWatchReader(egress: $this->egress);
	}//end setUp()

	/**
	 * A sitemap document over the given locations.
	 *
	 * @param array<string, string> $locations Location to lastmod.
	 *
	 * @return string
	 */
	private function sitemap(array $locations): string {
		$body = '';
		foreach ($locations as $loc => $lastmod) {
			$body .= ('<url><loc>' . $loc . '</loc>');
			if ($lastmod !== '') {
				$body .= ('<lastmod>' . $lastmod . '</lastmod>');
			}

			$body .= '</url>';
		}

		return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $body . '</urlset>';
	}//end sitemap()

	/**
	 * A location absent from the previous state is new.
	 *
	 * @return void
	 */
	public function testReportsNewLocations(): void {
		$diff = $this->reader->diff(
			previous: ['https://example.org/a' => '2026-08-01'],
			current: ['https://example.org/a' => '2026-08-01', 'https://example.org/b' => '2026-09-01']
		);

		$this->assertSame(['https://example.org/b' => '2026-09-01'], $diff['new']);
		$this->assertSame([], $diff['changed']);
	}//end testReportsNewLocations()

	/**
	 * A location whose lastmod moved is changed, not new.
	 *
	 * @return void
	 */
	public function testReportsChangedLastmod(): void {
		$diff = $this->reader->diff(
			previous: ['https://example.org/a' => '2026-08-01'],
			current: ['https://example.org/a' => '2026-09-01']
		);

		$this->assertSame([], $diff['new']);
		$this->assertSame(['https://example.org/a' => '2026-09-01'], $diff['changed']);
	}//end testReportsChangedLastmod()

	/**
	 * A location present in both with the same lastmod is in neither list.
	 *
	 * @return void
	 */
	public function testAnUnchangedLocationIsInNeitherList(): void {
		$diff = $this->reader->diff(
			previous: ['https://example.org/a' => '2026-08-01'],
			current: ['https://example.org/a' => '2026-08-01']
		);

		$this->assertSame([], $diff['new']);
		$this->assertSame([], $diff['changed']);
	}//end testAnUnchangedLocationIsInNeitherList()

	/**
	 * A location with no lastmod is unchanged once it has been seen, so a
	 * dateless sitemap does not re-report itself every night.
	 *
	 * @return void
	 */
	public function testALocationWithoutLastmodIsUnchangedOnceSeen(): void {
		$diff = $this->reader->diff(
			previous: ['https://example.org/a' => ''],
			current: ['https://example.org/a' => '']
		);

		$this->assertSame([], $diff['new']);
		$this->assertSame([], $diff['changed']);
	}//end testALocationWithoutLastmodIsUnchangedOnceSeen()

	/**
	 * A location that gains a lastmod is not reported as changed either: it
	 * is the sitemap that grew a field, not the page that moved.
	 *
	 * @return void
	 */
	public function testGainingALastmodIsNotAChange(): void {
		$diff = $this->reader->diff(
			previous: ['https://example.org/a' => ''],
			current: ['https://example.org/a' => '2026-09-01']
		);

		$this->assertSame([], $diff['changed']);
	}//end testGainingALastmodIsNotAChange()

	/**
	 * A plain sitemap parses into locations and is not an index.
	 *
	 * @return void
	 */
	public function testParsesAPlainSitemap(): void {
		$parsed = $this->reader->parse(
			document: $this->sitemap(['https://example.org/a' => '2026-08-01', 'https://example.org/b' => ''])
		);

		$this->assertIsArray($parsed);
		$this->assertFalse($parsed['index']);
		$this->assertSame(
			['https://example.org/a' => '2026-08-01', 'https://example.org/b' => ''],
			$parsed['locations']
		);
	}//end testParsesAPlainSitemap()

	/**
	 * A sitemap index is recognised as one.
	 *
	 * @return void
	 */
	public function testRecognisesASitemapIndex(): void {
		$document = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
			. '<sitemap><loc>https://example.org/sitemap-1.xml</loc></sitemap></sitemapindex>';

		$parsed = $this->reader->parse(document: $document);

		$this->assertIsArray($parsed);
		$this->assertTrue($parsed['index']);
	}//end testRecognisesASitemapIndex()

	/**
	 * Something that is not a sitemap is unparsable, not an empty sitemap.
	 *
	 * @return void
	 */
	public function testANonSitemapIsUnparsable(): void {
		$this->assertNull($this->reader->parse(document: '<html><body>404</body></html>'));
		$this->assertNull($this->reader->parse(document: ''));

		$this->egress->method('readUrl')->willReturn(EgressResult::success(body: '<html>404</html>'));
		$outcome = $this->reader->read(url: 'https://example.org/sitemap.xml', previous: []);

		$this->assertSame(EgressResult::UNPARSABLE, $outcome->outcome);
	}//end testANonSitemapIsUnparsable()

	/**
	 * A read reports new and changed locations as items and hands the fresh
	 * state back for the next run.
	 *
	 * @return void
	 */
	public function testAReadReportsItemsAndKeepsTheState(): void {
		$this->egress->method('readUrl')->willReturn(
			EgressResult::success(
				body: $this->sitemap(['https://example.org/a' => '2026-09-01', 'https://example.org/b' => '2026-09-02'])
			)
		);

		$outcome = $this->reader->read(
			url: 'https://example.org/sitemap.xml',
			previous: ['https://example.org/a' => '2026-08-01']
		);

		$this->assertTrue($outcome->ok());
		$this->assertCount(2, $outcome->items);
		$this->assertArrayHasKey('locations', $outcome->state);
		$this->assertCount(2, $outcome->state['locations']);
	}//end testAReadReportsItemsAndKeepsTheState()

	/**
	 * A second read over an unchanged sitemap produces nothing.
	 *
	 * @return void
	 */
	public function testASecondReadOverAnUnchangedSitemapProducesNothing(): void {
		$locations = ['https://example.org/a' => '2026-09-01'];
		$this->egress->method('readUrl')->willReturn(EgressResult::success(body: $this->sitemap($locations)));

		$outcome = $this->reader->read(url: 'https://example.org/sitemap.xml', previous: $locations);

		$this->assertTrue($outcome->ok());
		$this->assertSame([], $outcome->items);
	}//end testASecondReadOverAnUnchangedSitemapProducesNothing()
}//end class
