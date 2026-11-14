<?php

/**
 * Tests for SearchWatchReader.
 *
 * A search watch is the one kind that depends on another app being installed,
 * so the thing worth asserting is that its absence is a quiet no-op with a
 * reason, and not an exception that would take the feed and sitemap watches of
 * the same run down with it.
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

use OCA\Pipelinq\Service\Competitor\SearchWatchReader;
use OCA\Pipelinq\Service\Egress\EgressResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for hermiq's web search client.
 */
class FakeWebSearchClient {

	/**
	 * What the client will answer.
	 *
	 * @var array<string, mixed>
	 */
	public array $answer = [];

	/**
	 * Whether the search should throw.
	 *
	 * @var bool
	 */
	public bool $throws = false;

	/**
	 * Answer whatever the test set.
	 *
	 * @param string $query The query.
	 * @param string|null $actingUserId The acting user.
	 *
	 * @return array<string, mixed>
	 */
	public function search(string $query, ?string $actingUserId = null): array {
		if ($this->throws === true) {
			throw new RuntimeException('the backend is down');
		}

		return $this->answer;
	}//end search()
}//end class

/**
 * @covers \OCA\Pipelinq\Service\Competitor\SearchWatchReader
 * @uses \OCA\Pipelinq\Service\Competitor\WatchOutcome
 */
class SearchWatchReaderTest extends TestCase {

	/**
	 * A reader over a container that resolves the given client, or nothing.
	 *
	 * @param FakeWebSearchClient|null $client The fake hermiq client, or null.
	 *
	 * @return SearchWatchReader
	 */
	private function reader(?FakeWebSearchClient $client): SearchWatchReader {
		$container = $this->createMock(ContainerInterface::class);
		if ($client === null) {
			$container->method('get')->willThrowException(new RuntimeException('hermiq is not installed'));
		} else {
			$container->method('get')->willReturn($client);
		}

		return new SearchWatchReader(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * Without hermiq the watch produces nothing and says why.
	 *
	 * @return void
	 */
	public function testReturnsNothingWithoutHermiq(): void {
		$outcome = $this->reader(client: null)->read(query: 'voorbeeld aanbesteding');

		$this->assertFalse($outcome->succeeded());
		$this->assertSame(EgressResult::NOT_CONFIGURED, $outcome->outcome);
		$this->assertSame([], $outcome->items);
		$this->assertStringContainsString('Hermiq', $outcome->reason);
	}//end testReturnsNothingWithoutHermiq()

	/**
	 * The absence of hermiq is reported, never thrown, so the rest of a
	 * watch pass keeps running.
	 *
	 * @return void
	 */
	public function testDoesNotFailTheRun(): void {
		$outcome = $this->reader(client: null)->read(query: 'voorbeeld');

		$this->assertNotSame('', $outcome->reason);
	}//end testDoesNotFailTheRun()

	/**
	 * Hermiq's own error shape is a configuration answer, with its message.
	 *
	 * @return void
	 */
	public function testHermiqsOwnErrorIsReportedWithItsMessage(): void {
		$client = new FakeWebSearchClient();
		$client->answer = ['error' => ['code' => 'search_unavailable', 'message' => 'No web search provider is configured.']];

		$outcome = $this->reader(client: $client)->read(query: 'voorbeeld');

		$this->assertFalse($outcome->succeeded());
		$this->assertSame('No web search provider is configured.', $outcome->reason);
	}//end testHermiqsOwnErrorIsReportedWithItsMessage()

	/**
	 * A throwing client is `unavailable` rather than a failed run.
	 *
	 * @return void
	 */
	public function testAThrowingClientIsUnavailable(): void {
		$client = new FakeWebSearchClient();
		$client->throws = true;

		$outcome = $this->reader(client: $client)->read(query: 'voorbeeld');

		$this->assertSame(EgressResult::UNAVAILABLE, $outcome->outcome);
	}//end testAThrowingClientIsUnavailable()

	/**
	 * Results become watch items keyed on their URL.
	 *
	 * @return void
	 */
	public function testResultsBecomeWatchItems(): void {
		$client = new FakeWebSearchClient();
		$client->answer = [
			'query' => 'voorbeeld',
			'results' => [
				['title' => 'Voorbeeld wint aanbesteding', 'url' => 'https://nieuws.example.org/1', 'snippet' => 'Een alinea.'],
				['title' => 'Zonder url', 'snippet' => 'Wordt overgeslagen.'],
			],
		];

		$outcome = $this->reader(client: $client)->read(query: 'voorbeeld');

		$this->assertTrue($outcome->succeeded());
		$this->assertCount(1, $outcome->items);
		$this->assertSame('https://nieuws.example.org/1', $outcome->items[0]['url']);
		$this->assertSame('https://nieuws.example.org/1', $outcome->items[0]['stamp']);
	}//end testResultsBecomeWatchItems()

	/**
	 * An empty query is refused rather than searched for.
	 *
	 * @return void
	 */
	public function testAnEmptyQueryIsRefused(): void {
		$client = new FakeWebSearchClient();

		$outcome = $this->reader(client: $client)->read(query: '   ');

		$this->assertSame(EgressResult::NOT_CONFIGURED, $outcome->outcome);
	}//end testAnEmptyQueryIsRefused()

	/**
	 * An answer that is not an array is unparsable, not an empty result.
	 *
	 * @return void
	 */
	public function testANonArrayAnswerIsUnparsable(): void {
		$client = new class {
			/**
			 * Answer with something that is not a result set.
			 *
			 * @param string $query The query.
			 * @param string|null $actingUserId The acting user.
			 *
			 * @return string
			 */
			public function search(string $query, ?string $actingUserId = null): string {
				return 'no results today';
			}//end search()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($client);
		$reader = new SearchWatchReader(container: $container, logger: $this->createMock(LoggerInterface::class));

		$outcome = $reader->read(query: 'voorbeeld');

		$this->assertFalse($outcome->succeeded());
		$this->assertSame(EgressResult::UNPARSABLE, $outcome->outcome);
		$this->assertSame('The web search answered nothing usable.', $outcome->reason);
		$this->assertSame([], $outcome->items);
	}//end testANonArrayAnswerIsUnparsable()

	/**
	 * A resolved service without a `search` method counts as hermiq being
	 * absent, and the client is never called.
	 *
	 * @return void
	 */
	public function testAServiceWithoutSearchCountsAsAbsent(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with(SearchWatchReader::CLIENT_CLASS)
			->willReturn(new \stdClass());
		$reader = new SearchWatchReader(container: $container, logger: $this->createMock(LoggerInterface::class));

		$outcome = $reader->read(query: 'voorbeeld');

		$this->assertFalse($outcome->succeeded());
		$this->assertSame(EgressResult::NOT_CONFIGURED, $outcome->outcome);
		$this->assertStringContainsString('Hermiq', $outcome->reason);
	}//end testAServiceWithoutSearchCountsAsAbsent()

	/**
	 * Non-array results are skipped, titles fall back to the URL, text is
	 * trimmed, and at most MAX_RESULTS items are kept.
	 *
	 * @return void
	 */
	public function testResultsAreNormalisedAndCapped(): void {
		$results = ['not a result', ['url' => '  https://example.org/0  ', 'snippet' => '  Tekst.  ']];
		for ($i = 1; $i <= (SearchWatchReader::MAX_RESULTS + 5); $i++) {
			$results[] = ['url' => 'https://example.org/' . $i, 'title' => ' Titel ' . $i . ' '];
		}

		$client = new FakeWebSearchClient();
		$client->answer = ['results' => $results];

		$outcome = $this->reader(client: $client)->read(query: '  voorbeeld  ');

		$this->assertTrue($outcome->succeeded());
		$this->assertCount(SearchWatchReader::MAX_RESULTS, $outcome->items);
		$this->assertSame(
			['url' => 'https://example.org/0', 'title' => 'https://example.org/0', 'summary' => 'Tekst.', 'stamp' => 'https://example.org/0'],
			$outcome->items[0]
		);
		$this->assertSame('Titel 1', $outcome->items[1]['title']);
		$this->assertSame('', $outcome->items[1]['summary']);
		$this->assertSame('https://example.org/' . (SearchWatchReader::MAX_RESULTS - 1), $outcome->items[SearchWatchReader::MAX_RESULTS - 1]['url']);
	}//end testResultsAreNormalisedAndCapped()

	/**
	 * A success without a results key is an empty, successful read.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutResultsIsEmpty(): void {
		$client = new FakeWebSearchClient();
		$client->answer = ['query' => 'voorbeeld'];

		$outcome = $this->reader(client: $client)->read(query: 'voorbeeld');

		$this->assertTrue($outcome->succeeded());
		$this->assertSame([], $outcome->items);
	}//end testAnAnswerWithoutResultsIsEmpty()

}//end class
