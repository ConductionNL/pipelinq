<?php

/**
 * Tests for FeedWatchReader.
 *
 * The parse is a pure function over a document, so both feed formats and the
 * failure case are asserted without a network. The failure case is the one
 * that matters: a site answering a feed URL with an HTML error page must be
 * reported as unparsable and never as "they published nothing".
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

use OCA\Pipelinq\Service\Competitor\FeedWatchReader;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Competitor\FeedWatchReader
 */
class FeedWatchReaderTest extends TestCase {

	/**
	 * The mocked egress seam.
	 *
	 * @var ConnectorEgress&MockObject
	 */
	private ConnectorEgress $egress;

	/**
	 * The reader under test.
	 *
	 * @var FeedWatchReader
	 */
	private FeedWatchReader $reader;

	/**
	 * Build the reader over a mocked egress seam.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->egress = $this->createMock(ConnectorEgress::class);
		$this->reader = new FeedWatchReader(egress: $this->egress);
	}//end setUp()

	/**
	 * A two-item RSS document.
	 *
	 * @return string
	 */
	private function rss(): string {
		return '<?xml version="1.0"?><rss version="2.0"><channel><title>Voorbeeld</title>'
			. '<item><title>Eerste bericht</title><link>https://example.org/1</link>'
			. '<guid>https://example.org/1</guid><pubDate>Tue, 01 Sep 2026 10:00:00 +0000</pubDate>'
			. '<description>&lt;p&gt;Een alinea.&lt;/p&gt;</description></item>'
			. '<item><title>Tweede bericht</title><link>https://example.org/2</link>'
			. '<guid>https://example.org/2</guid></item>'
			. '</channel></rss>';
	}//end rss()

	/**
	 * A two-entry Atom document.
	 *
	 * @return string
	 */
	private function atom(): string {
		return '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Voorbeeld</title>'
			. '<entry><title>Eerste</title><id>tag:example.org,2026:1</id>'
			. '<link href="https://example.org/a1"/><published>2026-09-01T10:00:00Z</published>'
			. '<summary>Samenvatting.</summary></entry>'
			. '<entry><title>Tweede</title><id>tag:example.org,2026:2</id>'
			. '<link href="https://example.org/a2"/><updated>2026-09-02T10:00:00Z</updated></entry>'
			. '</feed>';
	}//end atom()

	/**
	 * RSS items parse, with title, link and guid.
	 *
	 * @return void
	 */
	public function testParsesRssItems(): void {
		$entries = $this->reader->parse(document: $this->rss());

		$this->assertIsArray($entries);
		$this->assertCount(2, $entries);
		$this->assertSame('Eerste bericht', $entries[0]['title']);
		$this->assertSame('https://example.org/1', $entries[0]['url']);
		$this->assertSame('https://example.org/1', $entries[0]['stamp']);
		$this->assertSame('Een alinea.', $entries[0]['summary']);
	}//end testParsesRssItems()

	/**
	 * Atom entries parse, with the link href rather than the element text.
	 *
	 * @return void
	 */
	public function testParsesAtomEntries(): void {
		$entries = $this->reader->parse(document: $this->atom());

		$this->assertIsArray($entries);
		$this->assertCount(2, $entries);
		$this->assertSame('https://example.org/a1', $entries[0]['url']);
		$this->assertSame('tag:example.org,2026:1', $entries[0]['stamp']);
	}//end testParsesAtomEntries()

	/**
	 * An entry with no date takes the moment of reading, so an undated feed
	 * still produces usable events.
	 *
	 * @return void
	 */
	public function testFallsBackToNowWhenThereIsNoDate(): void {
		$entries = $this->reader->parse(document: $this->rss());

		$this->assertIsArray($entries);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)$entries[1]['publishedAt']);
	}//end testFallsBackToNowWhenThereIsNoDate()

	/**
	 * An HTML error page is unparsable, not an empty feed.
	 *
	 * @return void
	 */
	public function testANonXmlBodyIsUnparsable(): void {
		$this->assertNull($this->reader->parse(document: '<html><body>404 Not Found</body></html>'));
		$this->assertNull($this->reader->parse(document: ''));
	}//end testANonXmlBodyIsUnparsable()

	/**
	 * An unparsable body reaches the caller as `unparsable`, with the URL in
	 * the reason.
	 *
	 * @return void
	 */
	public function testReadReportsUnparsableRatherThanNoEntries(): void {
		$this->egress->method('readUrl')->willReturn(EgressResult::success(body: '<html>oops</html>'));

		$outcome = $this->reader->read(url: 'https://example.org/feed.xml');

		$this->assertFalse($outcome->ok());
		$this->assertSame(EgressResult::UNPARSABLE, $outcome->outcome);
		$this->assertStringContainsString('example.org/feed.xml', $outcome->reason);
	}//end testReadReportsUnparsableRatherThanNoEntries()

	/**
	 * A refused fetch keeps the egress seam's own failure code and reason.
	 *
	 * @return void
	 */
	public function testAFailedFetchKeepsItsReason(): void {
		$this->egress->method('readUrl')->willReturn(
			EgressResult::failed(failure: EgressResult::REFUSED, reason: 'answered 403', status: 403)
		);

		$outcome = $this->reader->read(url: 'https://example.org/feed.xml');

		$this->assertSame(EgressResult::REFUSED, $outcome->outcome);
		$this->assertSame('answered 403', $outcome->reason);
		$this->assertSame([], $outcome->items);
	}//end testAFailedFetchKeepsItsReason()

	/**
	 * A successful read hands the entries on.
	 *
	 * @return void
	 */
	public function testASuccessfulReadReturnsTheEntries(): void {
		$this->egress->method('readUrl')->willReturn(EgressResult::success(body: $this->rss()));

		$outcome = $this->reader->read(url: 'https://example.org/feed.xml');

		$this->assertTrue($outcome->ok());
		$this->assertCount(2, $outcome->items);
	}//end testASuccessfulReadReturnsTheEntries()
}//end class
