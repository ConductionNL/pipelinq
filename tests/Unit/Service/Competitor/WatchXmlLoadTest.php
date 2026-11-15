<?php

/**
 * Tests for the quiet XML parse the feed and sitemap readers share.
 *
 * A competitor's slightly invalid feed or sitemap is not our incident: the
 * parse answers null and leaves no libxml error behind and the caller's
 * libxml error mode as it was.
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
use OCA\Pipelinq\Service\Competitor\SitemapWatchReader;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SimpleXMLElement;

/**
 * The load() of both readers.
 */
class WatchXmlLoadTest extends TestCase {
	/**
	 * The two readers.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function readerProvider(): array {
		return ['feed' => [FeedWatchReader::class], 'sitemap' => [SitemapWatchReader::class]];
	}//end readerProvider()

	/**
	 * Valid XML parses; blank, broken and non-XML documents answer null; no
	 * libxml error is left and the caller's error mode is kept either way.
	 *
	 * @param class-string $class The reader.
	 *
	 * @return void
	 *
	 * @dataProvider readerProvider
	 */
	public function testLoad(string $class): void {
		$reader = new $class(egress: $this->createMock(ConnectorEgress::class));
		$load = new ReflectionMethod($reader, 'load');
		$load->setAccessible(true);

		foreach ([false, true] as $mode) {
			$previous = libxml_use_internal_errors($mode);

			$xml = $load->invoke($reader, '<rss><channel><title>T</title></channel></rss>');
			$this->assertInstanceOf(SimpleXMLElement::class, $xml);
			$this->assertSame('T', (string)$xml->channel->title);

			foreach (['', "  \n ", '<rss><channel>', '<!doctype html><p>Not found', 'plain text'] as $document) {
				$this->assertNull($load->invoke($reader, $document));
				$this->assertSame([], libxml_get_errors());
			}

			$this->assertSame($mode, libxml_use_internal_errors($previous));
		}
	}//end testLoad()
}//end class
