<?php

/**
 * Tests for QuietXmlParser: a document parses or answers null, and never leaves libxml errors behind.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Competitor
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Competitor;

use OCA\Pipelinq\Service\Competitor\QuietXmlParser;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * @covers \OCA\Pipelinq\Service\Competitor\QuietXmlParser
 */
class QuietXmlParserTest extends TestCase {

	/**
	 * Restore libxml error handling after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		libxml_clear_errors();
		libxml_use_internal_errors(false);
	}//end tearDown()

	/**
	 * A well-formed document parses into its element tree.
	 *
	 * @return void
	 */
	public function testAWellFormedDocumentParses(): void {
		$xml = (new QuietXmlParser())->parse(document: '<rss><channel><title>Nieuws</title></channel></rss>');

		$this->assertInstanceOf(SimpleXMLElement::class, $xml);
		$this->assertSame('rss', $xml->getName());
		$this->assertSame('Nieuws', (string)$xml->channel->title);
	}//end testAWellFormedDocumentParses()

	/**
	 * An empty or blank document answers null.
	 *
	 * @return void
	 */
	public function testABlankDocumentIsNull(): void {
		$parser = new QuietXmlParser();

		$this->assertNull($parser->parse(document: ''));
		$this->assertNull($parser->parse(document: " \n\t "));
	}//end testABlankDocumentIsNull()

	/**
	 * A malformed document answers null, raises no warning and leaves no error queued.
	 *
	 * @return void
	 */
	public function testAMalformedDocumentIsNullAndQuiet(): void {
		libxml_use_internal_errors(false);

		$this->assertNull((new QuietXmlParser())->parse(document: '<html><body><p>unclosed</body>'));
		$this->assertNull((new QuietXmlParser())->parse(document: 'not xml at all'));

		$this->assertSame([], libxml_get_errors());
		$this->assertFalse(libxml_use_internal_errors(), 'the caller\'s error mode is restored');
	}//end testAMalformedDocumentIsNullAndQuiet()

	/**
	 * A caller that had internal errors on keeps them on, and its queued errors are cleared.
	 *
	 * @return void
	 */
	public function testACallersInternalErrorModeIsKept(): void {
		libxml_use_internal_errors(true);
		simplexml_load_string('<broken');
		$this->assertNotSame([], libxml_get_errors());

		$this->assertNotNull((new QuietXmlParser())->parse(document: '<ok/>'));

		$this->assertTrue(libxml_use_internal_errors());
		$this->assertSame([], libxml_get_errors());
	}//end testACallersInternalErrorModeIsKept()
}//end class
