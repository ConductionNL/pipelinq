<?php

/**
 * Tests for WatchEventStore: the record it writes, what it remembers per watch, and when it writes nothing.
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

use OCA\Pipelinq\Service\Competitor\WatchEventStore;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Competitor\WatchEventStore
 */
class WatchEventStoreTest extends TestCase {

	/**
	 * 2026-03-04T05:06:07Z.
	 *
	 * @var int
	 */
	private const NOW = 1772600767;

	/**
	 * The object store double.
	 *
	 * @var ListObjectStore&MockObject
	 */
	private ListObjectStore&MockObject $store;

	/**
	 * Build the store double, resolving the schema and reading ids from `id`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(ListObjectStore::class);
		$this->store->method('schemaSlug')->with('watchEvent_schema', 'watchEvent')->willReturn('watch-event-v2');
		$this->store->method('idOf')->willReturnCallback(
			static fn (?array $payload): string => (string)($payload['id'] ?? '')
		);
	}//end setUp()

	/**
	 * The store under test.
	 *
	 * @return WatchEventStore
	 */
	private function events(): WatchEventStore {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new WatchEventStore(store: $this->store, time: $time);
	}//end events()

	/**
	 * The schema is resolved through its config key, and all() reads it with the filters.
	 *
	 * @return void
	 */
	public function testAllReadsTheConfiguredSchemaWithTheFilters(): void {
		$this->store->expects($this->once())
			->method('findAll')
			->with('watch-event-v2', ['competitorId' => 'c1'])
			->willReturn([['id' => 'e1']]);

		$events = $this->events();
		$this->assertSame('watch-event-v2', $events->schemaSlug());
		$this->assertSame([['id' => 'e1']], $events->all(filters: ['competitorId' => 'c1']));
	}//end testAllReadsTheConfiguredSchemaWithTheFilters()

	/**
	 * The seen locations of a watch map each trimmed url to its trimmed stamp, skipping blanks.
	 *
	 * @return void
	 */
	public function testSeenLocationsMapEachUrlToItsStamp(): void {
		$this->store->expects($this->once())
			->method('findAll')
			->with('watch-event-v2', ['watchId' => 'w1'])
			->willReturn(
				[
					['url' => ' https://a.example/1 ', 'sourceStamp' => ' 2026-01-01 '],
					['url' => 'https://a.example/2'],
					['url' => '   ', 'sourceStamp' => 'ignored'],
					['sourceStamp' => 'no url'],
					['url' => 'https://a.example/1', 'sourceStamp' => 'later'],
				]
			);

		$this->assertSame(
			['https://a.example/1' => 'later', 'https://a.example/2' => ''],
			$this->events()->seenLocations(watch: ['id' => 'w1'])
		);
	}//end testSeenLocationsMapEachUrlToItsStamp()

	/**
	 * A watch without an id has no seen locations and nothing is read.
	 *
	 * @return void
	 */
	public function testAWatchWithoutAnIdHasSeenNothing(): void {
		$this->store->expects($this->never())->method('findAll');

		$this->assertSame([], $this->events()->seenLocations(watch: ['competitorId' => 'c1']));
	}//end testAWatchWithoutAnIdHasSeenNothing()

	/**
	 * A new event is written whole: trimmed, the title defaulting to the url, stamped now, without an id.
	 *
	 * @return void
	 */
	public function testANewEventIsWrittenWhole(): void {
		$this->store->expects($this->once())
			->method('findAll')
			->with('watch-event-v2', ['watchId' => 'w1', 'url' => 'https://a.example/1'])
			->willReturn([]);
		$this->store->expects($this->once())
			->method('save')
			->with(
				'watch-event-v2',
				[
					'competitorId' => 'c1',
					'watchId' => 'w1',
					'kind' => 'rss',
					'title' => 'https://a.example/1',
					'url' => 'https://a.example/1',
					'diffSummary' => 'Alinea',
					'sourceStamp' => 'g1',
					'seenAt' => '2026-03-04T05:06:07Z',
				],
				null
			)
			->willReturn(['id' => 'e1']);

		$result = $this->events()->upsert(
			watch: ['id' => 'w1', 'competitorId' => ' c1 ', 'kind' => ' rss '],
			item: ['url' => ' https://a.example/1 ', 'summary' => ' Alinea ', 'stamp' => ' g1 ']
		);

		$this->assertSame(['created' => true, 'event' => ['id' => 'e1']], $result);
	}//end testANewEventIsWrittenWhole()

	/**
	 * Title, summary and stamp are cut to 500, 2000 and 128 characters, counting multibyte characters as one.
	 *
	 * @return void
	 */
	public function testLongFieldsAreCutByCharacter(): void {
		$this->store->method('findAll')->willReturn([]);
		$saved = [];
		$this->store->method('save')->willReturnCallback(
			static function (string $slug, array $payload, ?string $id = null) use (&$saved): array {
				$saved = $payload;
				return $payload;
			}
		);

		$this->events()->upsert(
			watch: ['id' => 'w1'],
			item: ['url' => 'https://a.example/1', 'title' => str_repeat('é', 600), 'summary' => str_repeat('ß', 2100), 'stamp' => str_repeat('x', 200)]
		);

		$this->assertSame(str_repeat('é', 500), $saved['title']);
		$this->assertSame(str_repeat('ß', 2000), $saved['diffSummary']);
		$this->assertSame(str_repeat('x', 128), $saved['sourceStamp']);
		$this->assertSame('', $saved['competitorId']);
		$this->assertSame('', $saved['kind']);
	}//end testLongFieldsAreCutByCharacter()

	/**
	 * A re-sighting updates the existing event under its id, dropping its metadata, with extra fields winning.
	 *
	 * @return void
	 */
	public function testAReSightingUpdatesUnderTheExistingId(): void {
		$this->store->method('findAll')->willReturn(
			[
				[
					'id' => 'e7',
					'uuid' => 'e7',
					'@self' => ['id' => 'e7'],
					'url' => 'https://a.example/1',
					'title' => 'Old',
					'seenAt' => '',
					'note' => 'kept',
				],
			]
		);
		$this->store->expects($this->once())
			->method('save')
			->with(
				'watch-event-v2',
				[
					'url' => 'https://a.example/1',
					'title' => 'Nieuw',
					'seenAt' => '2026-03-04T05:06:07Z',
					'note' => 'kept',
					'competitorId' => '',
					'watchId' => 'w1',
					'kind' => '',
					'diffSummary' => '',
					'sourceStamp' => '',
					'relevanceScore' => 40,
				],
				'e7'
			)
			->willReturn(null);

		$result = $this->events()->upsert(
			watch: ['id' => 'w1'],
			item: ['url' => 'https://a.example/1', 'title' => 'Nieuw'],
			extra: ['relevanceScore' => 40, 'title' => 'Nieuw']
		);

		$this->assertSame(['created' => false, 'event' => null], $result);
	}//end testAReSightingUpdatesUnderTheExistingId()

	/**
	 * Without a watch id nothing is looked up or written.
	 *
	 * @return void
	 */
	public function testAWatchWithoutAnIdStoresNothing(): void {
		$this->store->expects($this->never())->method('findAll');
		$this->store->expects($this->never())->method('save');

		$this->assertSame(
			['created' => false, 'event' => null],
			$this->events()->upsert(watch: ['competitorId' => 'c1'], item: ['url' => 'https://a.example/1'])
		);
	}//end testAWatchWithoutAnIdStoresNothing()
}//end class
