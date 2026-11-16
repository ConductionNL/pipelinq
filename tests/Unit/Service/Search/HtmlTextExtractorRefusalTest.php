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

use OCA\Pipelinq\Service\Search\HtmlTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * What the extractor answers when there is nothing to read or the selector is unusable.
 *
 * @covers \OCA\Pipelinq\Service\Search\HtmlTextExtractor
 */
class HtmlTextExtractorRefusalTest extends TestCase {

	/**
	 * The extractor under test.
	 *
	 * @var HtmlTextExtractor
	 */
	private HtmlTextExtractor $extractor;

	/**
	 * Set up the extractor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->extractor = new HtmlTextExtractor();
	}//end setUp()

	/**
	 * A blank document has no headline.
	 *
	 * @return void
	 */
	public function testABlankDocumentHasNoHeadline(): void {
		$this->assertSame('', $this->extractor->headline(html: ''));
		$this->assertSame('', $this->extractor->headline(html: "  \n\t "));
	}//end testABlankDocumentHasNoHeadline()

	/**
	 * Empty headings are skipped, so only text-bearing ones are joined.
	 *
	 * @return void
	 */
	public function testEmptyHeadingsAreSkipped(): void {
		$html = '<html><head><title> </title></head><body><h1></h1><h2>Prijzen</h2><h3>  Kassa  </h3></body></html>';

		$this->assertSame('Prijzen Kassa', $this->extractor->headline(html: $html));
	}//end testEmptyHeadingsAreSkipped()

	/**
	 * A blank document has no fragment, even for a supported selector.
	 *
	 * @return void
	 */
	public function testABlankDocumentHasNoFragment(): void {
		$this->assertNull($this->extractor->fragment(html: '', selector: '#main'));
	}//end testABlankDocumentHasNoFragment()

	/**
	 * An unsupported selector yields no fragment even when the document has the element.
	 *
	 * @return void
	 */
	public function testAnUnsupportedSelectorYieldsNoFragment(): void {
		$html = '<div id="main"><p>Tekst</p></div>';

		$this->assertSame('Tekst', $this->extractor->fragment(html: $html, selector: 'div#main p'));
		$this->assertNull($this->extractor->fragment(html: $html, selector: 'div > p'));
		$this->assertNull($this->extractor->fragment(html: $html, selector: '   '));
	}//end testAnUnsupportedSelectorYieldsNoFragment()
}//end class
