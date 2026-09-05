<?php

/**
 * Tests for PageWatchReader.
 *
 * Three properties are asserted, and each of them is a defect this watch would
 * otherwise ship: a change outside the selector must not fire, a selector that
 * matches nothing must be reported rather than treated as "no change", and the
 * stored state must never contain the competitor's text.
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

use OCA\Pipelinq\Service\Competitor\PageWatchReader;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Search\HtmlTextExtractor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Competitor\PageWatchReader
 */
class PageWatchReaderTest extends TestCase {

	/**
	 * A sentence that must never appear in the stored state.
	 *
	 * @var string
	 */
	private const DISTINCTIVE = 'Voorbeeld B.V. wint de aanbesteding bij gemeente Voorbeeld.';

	/**
	 * The mocked egress seam.
	 *
	 * @var ConnectorEgress&MockObject
	 */
	private ConnectorEgress $egress;

	/**
	 * The reader under test.
	 *
	 * @var PageWatchReader
	 */
	private PageWatchReader $reader;

	/**
	 * Build the reader over a mocked egress seam and the real extractor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->egress = $this->createMock(ConnectorEgress::class);
		$this->reader = new PageWatchReader(egress: $this->egress, extractor: new HtmlTextExtractor());
	}//end setUp()

	/**
	 * A document whose watched fragment and whose surrounding page can be
	 * varied independently.
	 *
	 * @param string $inside What the watched fragment says.
	 * @param string $outside What the rest of the page says.
	 *
	 * @return string
	 */
	private function page(string $inside, string $outside): string {
		return '<html><body><header>' . $outside . '</header>'
			. '<div id="news">' . $inside . '</div></body></html>';
	}//end page()

	/**
	 * Read the page once and hand back the outcome.
	 *
	 * @param string $body The document.
	 * @param string $fingerprint The fingerprint as last seen.
	 * @param array<int, string> $lines The line fingerprints as last seen.
	 *
	 * @return \OCA\Pipelinq\Service\Competitor\WatchOutcome
	 */
	private function readOnce(string $body, string $fingerprint = '', array $lines = []) {
		$this->egress->method('readUrl')->willReturn(EgressResult::success(body: $body));

		return $this->reader->read(
			url: 'https://example.org/nieuws',
			selector: '#news',
			fingerprint: $fingerprint,
			lineFingerprints: $lines
		);
	}//end readOnce()

	/**
	 * A change outside the selected fragment is not a change.
	 *
	 * @return void
	 */
	public function testAChangeOutsideTheSelectorIsNotAChange(): void {
		$first = $this->reader->diff(fingerprint: '', lineFingerprints: [], fragment: 'Nieuws van deze week.');
		$second = $this->reader->diff(
			fingerprint: $first['fingerprint'],
			lineFingerprints: $first['lineFingerprints'],
			fragment: 'Nieuws van deze week.'
		);

		$this->assertTrue($first['changed'], 'a first reading is a change');
		$this->assertFalse($second['changed']);
	}//end testAChangeOutsideTheSelectorIsNotAChange()

	/**
	 * A change inside the fragment is a change.
	 *
	 * @return void
	 */
	public function testAChangeInsideTheSelectorIsAChange(): void {
		$first = $this->reader->diff(fingerprint: '', lineFingerprints: [], fragment: 'Nieuws van deze week.');
		$second = $this->reader->diff(
			fingerprint: $first['fingerprint'],
			lineFingerprints: $first['lineFingerprints'],
			fragment: 'Nieuws van deze week. En nog iets.'
		);

		$this->assertTrue($second['changed']);
	}//end testAChangeInsideTheSelectorIsAChange()

	/**
	 * A selector matching nothing is reported, with the selector named.
	 *
	 * @return void
	 */
	public function testASelectorThatMatchesNothingIsUnparsable(): void {
		$this->egress->method('readUrl')->willReturn(
			EgressResult::success(body: '<html><body><div id="other">x</div></body></html>')
		);

		$outcome = $this->reader->read(
			url: 'https://example.org/nieuws',
			selector: '#news',
			fingerprint: '',
			lineFingerprints: []
		);

		$this->assertFalse($outcome->succeeded());
		$this->assertSame(EgressResult::UNPARSABLE, $outcome->outcome);
		$this->assertStringContainsString('#news', $outcome->reason);
	}//end testASelectorThatMatchesNothingIsUnparsable()

	/**
	 * The stored state holds fingerprints and never the competitor's words.
	 *
	 * @return void
	 */
	public function testStoresFingerprintsAndNotTheFragment(): void {
		$outcome = $this->readOnce(body: $this->page(inside: self::DISTINCTIVE, outside: 'Menu'));

		$this->assertTrue($outcome->succeeded());
		$this->assertArrayHasKey('fingerprint', $outcome->state);
		$this->assertStringNotContainsString(self::DISTINCTIVE, json_encode($outcome->state, JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString('aanbesteding', json_encode($outcome->state, JSON_THROW_ON_ERROR));
	}//end testStoresFingerprintsAndNotTheFragment()

	/**
	 * Added lines are quoted from the fresh fetch; removed ones are counted,
	 * because the text they held was deliberately not kept.
	 *
	 * @return void
	 */
	public function testQuotesAddedLinesAndCountsRemovedOnes(): void {
		$first = $this->reader->diff(
			fingerprint: '',
			lineFingerprints: [],
			fragment: 'Eerste regel. Tweede regel.'
		);

		$second = $this->reader->diff(
			fingerprint: $first['fingerprint'],
			lineFingerprints: $first['lineFingerprints'],
			fragment: 'Eerste regel. Derde regel.'
		);

		$this->assertTrue($second['changed']);
		$this->assertContains('Derde regel.', $second['added']);
		$this->assertSame(1, $second['removed']);
		$this->assertStringContainsString('Derde regel.', $second['summary']);
		$this->assertStringNotContainsString('Tweede regel.', $second['summary']);
	}//end testQuotesAddedLinesAndCountsRemovedOnes()

	/**
	 * The first reading says so rather than claiming the whole fragment was
	 * added, which would be true but useless.
	 *
	 * @return void
	 */
	public function testTheFirstReadingSaysItIsTheFirst(): void {
		$first = $this->reader->diff(fingerprint: '', lineFingerprints: [], fragment: 'Nieuws.');

		$this->assertStringContainsString('First reading', $first['summary']);
	}//end testTheFirstReadingSaysItIsTheFirst()

	/**
	 * An unchanged fragment produces no watch event at all, so the page does
	 * not fill up with "nothing happened" rows.
	 *
	 * @return void
	 */
	public function testAnUnchangedFragmentProducesNoEvent(): void {
		$body = $this->page(inside: 'Nieuws van deze week.', outside: 'Menu');
		$known = $this->reader->diff(fingerprint: '', lineFingerprints: [], fragment: 'Nieuws van deze week.');

		$outcome = $this->readOnce(
			body: $body,
			fingerprint: $known['fingerprint'],
			lines: $known['lineFingerprints']
		);

		$this->assertTrue($outcome->succeeded());
		$this->assertSame([], $outcome->items);
	}//end testAnUnchangedFragmentProducesNoEvent()
}//end class
