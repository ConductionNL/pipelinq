<?php

/**
 * Pins how an Atom feed's entries are read: link, stamp and date fallbacks, skips and the cap.
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

use OCA\Pipelinq\Service\Competitor\FeedWatchReader;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use PHPUnit\Framework\TestCase;

/**
 * Whole entries for every fallback, and the entry cap.
 *
 * @covers \OCA\Pipelinq\Service\Competitor\FeedWatchReader
 * @uses \OCA\Pipelinq\Service\Competitor\QuietXmlParser
 */
class FeedAtomEntriesGoldenTest extends TestCase {

	/**
	 * Every Atom entry shape reads exactly as pinned.
	 *
	 * @return void
	 */
	public function testAtomEntriesAreExact(): void {
		$reader  = new FeedWatchReader(egress: $this->createMock(ConnectorEgress::class));
		$entries = $reader->parse(
			document: '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">'
				. '<entry><title> Eén </title><link href=" https://a.example/1 "/><id>tag:a,1</id><published>2026-09-01T10:00:00Z</published><updated>2026-09-02T10:00:00Z</updated><summary> Kort </summary></entry>'
				. '<entry><title>Twee</title><id> tag:a,2 </id><updated>2026-09-03T10:00:00+02:00</updated></entry>'
				. '<entry><title>Drie</title><link href="https://a.example/3"/><updated>2026-09-04T10:00:00Z</updated></entry>'
				. '<entry><title>Geen link</title><updated>2026-09-05T10:00:00Z</updated></entry>'
				. '</feed>'
		);
		$this->assertNotNull($entries);
		$this->assertSame(
			[
				['https://a.example/1', 'Eén', 'Kort', 'tag:a,1'],
				['tag:a,2', 'Twee', '', 'tag:a,2'],
				['https://a.example/3', 'Drie', '', '2026-09-04T10:00:00Z'],
			],
			array_map(static fn (array $e): array => [$e['url'], $e['title'], $e['summary'], $e['stamp']], $entries)
		);
		$this->assertSame(['url', 'title', 'summary', 'stamp', 'publishedAt'], array_keys($entries[0]));
		$this->assertSame(strtotime('2026-09-01T10:00:00Z'), strtotime((string)$entries[0]['publishedAt']));
		$this->assertSame(strtotime('2026-09-03T10:00:00+02:00'), strtotime((string)$entries[1]['publishedAt']));
		$this->assertSame(strtotime('2026-09-04T10:00:00Z'), strtotime((string)$entries[2]['publishedAt']));
	}//end testAtomEntriesAreExact()

	/**
	 * A feed longer than the cap is cut at the cap, in document order.
	 *
	 * @return void
	 */
	public function testTheEntryCapHolds(): void {
		$reader = new FeedWatchReader(egress: $this->createMock(ConnectorEgress::class));
		$xml    = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">';
		for ($i = 1; $i <= FeedWatchReader::MAX_ENTRIES + 5; $i++) {
			$xml .= '<entry><id>e-'.$i.'</id></entry>';
		}

		$entries = $reader->parse(document: $xml.'</feed>');
		$this->assertCount(FeedWatchReader::MAX_ENTRIES, $entries);
		$this->assertSame('e-1', $entries[0]['url']);
		$this->assertSame('e-'.FeedWatchReader::MAX_ENTRIES, $entries[FeedWatchReader::MAX_ENTRIES - 1]['url']);
	}//end testTheEntryCapHolds()
}//end class
