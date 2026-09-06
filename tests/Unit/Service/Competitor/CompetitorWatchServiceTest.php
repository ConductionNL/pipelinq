<?php

/**
 * Tests for CompetitorWatchService and WatchEventStore.
 *
 * Two properties carry this pair. `isDue()` is what replaces a background
 * job's interval after ADR-094 handed the firing to the flow engine, so it has
 * to be right for a watch that has never run and for one whose cadence has not
 * come round. And the event store has to be idempotent per (watch, URL), so a
 * schedule that fires twice cannot turn one headline into three rows.
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

use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Competitor\FediverseWatchReader;
use OCA\Pipelinq\Service\Competitor\FeedWatchReader;
use OCA\Pipelinq\Service\Competitor\PageWatchReader;
use OCA\Pipelinq\Service\Competitor\RelevanceScorer;
use OCA\Pipelinq\Service\Competitor\SearchWatchReader;
use OCA\Pipelinq\Service\Competitor\SitemapWatchReader;
use OCA\Pipelinq\Service\Competitor\WatchEventStore;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Service\Competitor\CompetitorWatchService
 * @covers \OCA\Pipelinq\Service\Competitor\WatchEventStore
 */
class CompetitorWatchServiceTest extends TestCase {

	/**
	 * A fixed "now", so a cadence test is not a clock test.
	 *
	 * @var int
	 */
	private const NOW = 1788600000;

	/**
	 * The mocked store.
	 *
	 * @var ListObjectStore&MockObject
	 */
	private ListObjectStore $store;

	/**
	 * The service under test.
	 *
	 * @var CompetitorWatchService
	 */
	private CompetitorWatchService $service;

	/**
	 * Build the service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(ListObjectStore::class);
		$this->store->method('schemaSlug')->willReturnCallback(
			static function (string $configKey, string $default): string {
				return $default;
			}
		);
		$this->store->method('idOf')->willReturnCallback(
			static function (?array $payload): string {
				return (string)($payload['id'] ?? '');
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->service = new CompetitorWatchService(
			store: $this->store,
			feeds: $this->createMock(FeedWatchReader::class),
			sitemaps: $this->createMock(SitemapWatchReader::class),
			pages: $this->createMock(PageWatchReader::class),
			fediverse: $this->createMock(FediverseWatchReader::class),
			searches: $this->createMock(SearchWatchReader::class),
			scorer: $this->createMock(RelevanceScorer::class),
			events: $this->createMock(WatchEventStore::class),
			time: $time,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A watch that has never run is due.
	 *
	 * @return void
	 */
	public function testAWatchThatHasNeverRunIsDue(): void {
		$this->assertTrue($this->service->isDue(watch: ['schedule' => 'daily'], now: self::NOW));
	}//end testAWatchThatHasNeverRunIsDue()

	/**
	 * A daily watch that ran an hour ago is not due; one that ran a day ago
	 * is. The boundary is the cadence exactly.
	 *
	 * @return void
	 */
	public function testCadenceDecidesWhetherAWatchIsDue(): void {
		$hourAgo = gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 3600));
		$dayAgo = gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 86400));

		$this->assertFalse($this->service->isDue(watch: ['schedule' => 'daily', 'lastRunAt' => $hourAgo], now: self::NOW));
		$this->assertTrue($this->service->isDue(watch: ['schedule' => 'daily', 'lastRunAt' => $dayAgo], now: self::NOW));
		$this->assertTrue($this->service->isDue(watch: ['schedule' => 'hourly', 'lastRunAt' => $hourAgo], now: self::NOW));
	}//end testCadenceDecidesWhetherAWatchIsDue()

	/**
	 * An inactive watch is never due, whatever its cadence.
	 *
	 * @return void
	 */
	public function testAnInactiveWatchIsNeverDue(): void {
		$this->assertFalse($this->service->isDue(watch: ['schedule' => 'hourly', 'active' => false], now: self::NOW));
	}//end testAnInactiveWatchIsNeverDue()

	/**
	 * An unreadable last-run stamp makes the watch due rather than stuck.
	 * A watch that can never run again is worse than one that runs twice.
	 *
	 * @return void
	 */
	public function testAnUnreadableLastRunMakesTheWatchDue(): void {
		$this->assertTrue($this->service->isDue(watch: ['schedule' => 'daily', 'lastRunAt' => 'gisteren'], now: self::NOW));
	}//end testAnUnreadableLastRunMakesTheWatchDue()

	/**
	 * An unknown cadence falls back to daily rather than to never.
	 *
	 * @return void
	 */
	public function testAnUnknownCadenceFallsBackToDaily(): void {
		$hourAgo = gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 3600));

		$this->assertFalse($this->service->isDue(watch: ['schedule' => 'maandelijks', 'lastRunAt' => $hourAgo], now: self::NOW));
	}//end testAnUnknownCadenceFallsBackToDaily()

	/**
	 * Only the due watches are returned, and never more than the limit.
	 *
	 * @return void
	 */
	public function testDueNowReturnsOnlyDueWatchesUpToTheLimit(): void {
		$recent = gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 60));
		$this->store->method('findAll')->willReturn(
			[
				['id' => 'w1', 'schedule' => 'daily'],
				['id' => 'w2', 'schedule' => 'daily', 'lastRunAt' => $recent],
				['id' => 'w3', 'schedule' => 'daily'],
				['id' => 'w4', 'schedule' => 'daily'],
			]
		);

		$this->assertSame(['w1', 'w3', 'w4'], array_column($this->service->dueNow(), 'id'));
		$this->assertCount(2, $this->service->dueNow(limit: 2));
	}//end testDueNowReturnsOnlyDueWatchesUpToTheLimit()

	/**
	 * The event store writes one object per (watch, URL): the first sighting
	 * creates and the second updates, so a second run over unchanged input
	 * adds nothing.
	 *
	 * @return void
	 */
	public function testTheEventStoreUpsertsPerWatchAndUrl(): void {
		$stored = [];
		$store = $this->createMock(ListObjectStore::class);
		$store->method('schemaSlug')->willReturn('watchEvent');
		$store->method('idOf')->willReturnCallback(
			static function (?array $payload): string {
				return (string)($payload['id'] ?? '');
			}
		);
		$store->method('findAll')->willReturnCallback(
			static function (string $schemaSlug, array $filters = []) use (&$stored): array {
				$key = (($filters['watchId'] ?? '') . '|' . ($filters['url'] ?? ''));
				if (array_key_exists($key, $stored) === true) {
					return [$stored[$key]];
				}

				return [];
			}
		);
		$store->method('save')->willReturnCallback(
			static function (string $schemaSlug, array $payload, ?string $id = null) use (&$stored): array {
				$saved = ($payload + ['id' => ($id ?? 'event-1')]);
				$stored[($saved['watchId'] . '|' . $saved['url'])] = $saved;

				return $saved;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$events = new WatchEventStore(store: $store, time: $time);

		$watch = ['id' => 'w1', 'competitorId' => 'c1', 'kind' => 'rss'];
		$item = ['url' => 'https://example.org/1', 'title' => 'Eerste', 'summary' => 'Alinea', 'stamp' => 'g1'];

		$first = $events->upsert(watch: $watch, item: $item);
		$second = $events->upsert(watch: $watch, item: $item);

		$this->assertTrue($first['created']);
		$this->assertFalse($second['created']);
		$this->assertCount(1, $stored);
	}//end testTheEventStoreUpsertsPerWatchAndUrl()

	/**
	 * A re-sighting keeps the FIRST `seenAt`, so "what did they publish this
	 * week" keeps meaning what it says, and keeps a relevance score an
	 * earlier run wrote rather than erasing it.
	 *
	 * @return void
	 */
	public function testAReSightingKeepsTheFirstSeenAtAndAnExistingScore(): void {
		$existing = [
			'id' => 'event-1',
			'watchId' => 'w1',
			'url' => 'https://example.org/1',
			'seenAt' => '2026-01-01T00:00:00Z',
			'relevanceScore' => 72,
		];

		$store = $this->createMock(ListObjectStore::class);
		$store->method('schemaSlug')->willReturn('watchEvent');
		$store->method('idOf')->willReturnCallback(
			static function (?array $payload): string {
				return (string)($payload['id'] ?? '');
			}
		);
		$store->method('findAll')->willReturn([$existing]);
		$saved = [];
		$store->method('save')->willReturnCallback(
			static function (string $schemaSlug, array $payload, ?string $id = null) use (&$saved): array {
				$saved = $payload;

				return $payload;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$events = new WatchEventStore(store: $store, time: $time);

		$events->upsert(
			watch: ['id' => 'w1', 'competitorId' => 'c1', 'kind' => 'rss'],
			item: ['url' => 'https://example.org/1', 'title' => 'Eerste', 'stamp' => 'g1']
		);

		$this->assertSame('2026-01-01T00:00:00Z', $saved['seenAt']);
		$this->assertSame(72, $saved['relevanceScore']);
	}//end testAReSightingKeepsTheFirstSeenAtAndAnExistingScore()

	/**
	 * An item with no URL is not stored: the URL is half the identity, and a
	 * row without one could never be updated again.
	 *
	 * @return void
	 */
	public function testAnItemWithoutAUrlIsNotStored(): void {
		$store = $this->createMock(ListObjectStore::class);
		$store->method('schemaSlug')->willReturn('watchEvent');
		$store->method('idOf')->willReturn('w1');
		$store->expects($this->never())->method('save');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$events = new WatchEventStore(store: $store, time: $time);

		$result = $events->upsert(watch: ['id' => 'w1'], item: ['title' => 'Zonder url']);

		$this->assertFalse($result['created']);
		$this->assertNull($result['event']);
	}//end testAnItemWithoutAUrlIsNotStored()
}//end class
