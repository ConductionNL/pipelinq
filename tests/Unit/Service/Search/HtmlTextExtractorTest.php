<?php

/**
 * Tests for HtmlTextExtractor.
 *
 * Both reads are pure functions over a document string, so both are asserted
 * against hand-written HTML rather than against anything fetched. The two
 * things that would go wrong silently are script content leaking into the text
 * a gap is decided on, and a selector that matches nothing being reported as
 * an empty fragment rather than as no match.
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

use OCA\Pipelinq\Service\Search\HtmlTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Search\HtmlTextExtractor
 */
class HtmlTextExtractorTest extends TestCase {

	/**
	 * The extractor under test.
	 *
	 * @var HtmlTextExtractor
	 */
	private HtmlTextExtractor $extractor;

	/**
	 * Build the extractor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->extractor = new HtmlTextExtractor();
	}//end setUp()

	/**
	 * A document with a title, headings, a script, a style and an entity.
	 *
	 * @return string
	 */
	private function document(): string {
		return '<html><head><title>Woo &amp; verzoek</title><style>h1 { color: red }</style></head>'
			. '<body><h1>Verzoek indienen</h1><script>var termijn = "vier weken";</script>'
			. '<div id="news" class="teaser highlight"><p>Nieuwe pagina</p><script>var x = 1;</script></div>'
			. '<h3>Termijn</h3><p>Body copy that is not a heading.</p></body></html>';
	}//end document()

	/**
	 * The title and every heading are extracted.
	 *
	 * @return void
	 */
	public function testExtractsTitleAndHeadings(): void {
		$text = $this->extractor->headline(html: $this->document());

		$this->assertStringContainsString('Woo & verzoek', $text);
		$this->assertStringContainsString('Verzoek indienen', $text);
		$this->assertStringContainsString('Termijn', $text);
	}//end testExtractsTitleAndHeadings()

	/**
	 * Script and style content never reaches the text a gap is decided on.
	 *
	 * @return void
	 */
	public function testIgnoresScriptAndStyleContent(): void {
		$text = $this->extractor->headline(html: $this->document());

		$this->assertStringNotContainsString('vier weken', $text);
		$this->assertStringNotContainsString('color', $text);
	}//end testIgnoresScriptAndStyleContent()

	/**
	 * Body copy is deliberately not part of the headline: a term mentioned
	 * once in a paragraph does not make a page about that term.
	 *
	 * @return void
	 */
	public function testIgnoresBodyCopy(): void {
		$this->assertStringNotContainsString('Body copy', $this->extractor->headline(html: $this->document()));
	}//end testIgnoresBodyCopy()

	/**
	 * Entities come back decoded.
	 *
	 * @return void
	 */
	public function testDecodesEntities(): void {
		$this->assertStringContainsString('&', $this->extractor->headline(html: $this->document()));
		$this->assertStringNotContainsString('&amp;', $this->extractor->headline(html: $this->document()));
	}//end testDecodesEntities()

	/**
	 * An empty or unparsable document is an empty string, not a warning.
	 *
	 * @return void
	 */
	public function testAnEmptyDocumentIsAnEmptyString(): void {
		$this->assertSame('', $this->extractor->headline(html: ''));
		$this->assertSame('', $this->extractor->headline(html: '   '));
	}//end testAnEmptyDocumentIsAnEmptyString()

	/**
	 * The three selector shapes select the same element.
	 *
	 * @return void
	 */
	public function testSelectsByIdClassAndTag(): void {
		$this->assertSame('Nieuwe pagina', $this->extractor->fragment(html: $this->document(), selector: '#news'));
		$this->assertSame('Nieuwe pagina', $this->extractor->fragment(html: $this->document(), selector: '.teaser'));
		$this->assertSame('Nieuwe pagina', $this->extractor->fragment(html: $this->document(), selector: 'div#news'));
	}//end testSelectsByIdClassAndTag()

	/**
	 * A class selector matches one class of several without matching a
	 * longer class name that merely contains it.
	 *
	 * @return void
	 */
	public function testAClassSelectorMatchesAWholeClassOnly(): void {
		$html = '<div class="teaser-large">Wrong</div><div class="teaser">Right</div>';

		$this->assertSame('Right', $this->extractor->fragment(html: $html, selector: '.teaser'));
	}//end testAClassSelectorMatchesAWholeClassOnly()

	/**
	 * A selector that matches nothing answers null, not an empty fragment.
	 *
	 * @return void
	 */
	public function testASelectorThatMatchesNothingIsNull(): void {
		$this->assertNull($this->extractor->fragment(html: $this->document(), selector: '#absent'));
	}//end testASelectorThatMatchesNothingIsNull()

	/**
	 * An unsupported selector is refused rather than silently mistranslated.
	 *
	 * @return void
	 */
	public function testAnUnsupportedSelectorIsRefused(): void {
		$this->assertNull($this->extractor->toXPath(selector: 'div > p'));
		$this->assertNull($this->extractor->toXPath(selector: 'a[href^="http"]'));
		$this->assertNull($this->extractor->toXPath(selector: ''));
	}//end testAnUnsupportedSelectorIsRefused()

	/**
	 * A descendant chain becomes a descendant XPath expression.
	 *
	 * @return void
	 */
	public function testADescendantChainIsTranslated(): void {
		$html = '<div id="main"><span class="teaser">Deep</span></div><span class="teaser">Shallow</span>';

		$this->assertSame('Deep', $this->extractor->fragment(html: $html, selector: 'div#main .teaser'));
	}//end testADescendantChainIsTranslated()

	/**
	 * Script content inside a selected fragment is dropped too, so a build
	 * hash in an inline script cannot make a page watch fire every night.
	 *
	 * @return void
	 */
	public function testAFragmentDropsItsOwnScripts(): void {
		$fragment = $this->extractor->fragment(html: $this->document(), selector: '#news');

		$this->assertNotNull($fragment);
		$this->assertStringNotContainsString('var x', (string)$fragment);
	}//end testAFragmentDropsItsOwnScripts()
}//end class
