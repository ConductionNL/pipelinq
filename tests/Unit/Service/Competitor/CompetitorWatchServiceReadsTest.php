<?php

/**
 * Pins the plain reads of the competitor-watch service: which schema and arguments reach the store.
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

use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Competitor\RelevanceScorer;
use OCA\Pipelinq\Service\Competitor\WatchEventStore;
use OCA\Pipelinq\Service\Competitor\WatchReaders;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Watches, competitors and a single watch, read through the configured schema slug.
 *
 * @covers \OCA\Pipelinq\Service\Competitor\CompetitorWatchService
 */
class CompetitorWatchServiceReadsTest extends TestCase {

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
	 * Build the service over a store whose schema slugs are configured.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(ListObjectStore::class);
		$this->store->method('schemaSlug')->willReturnCallback(
			static function (string $configKey, string $default): string {
				return ('cfg:' . $configKey . ':' . $default);
			}
		);

		$this->service = new CompetitorWatchService(
			store: $this->store,
			readers: $this->createMock(WatchReaders::class),
			scorer: $this->createMock(RelevanceScorer::class),
			events: $this->createMock(WatchEventStore::class),
			time: $this->createMock(ITimeFactory::class),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Watches are read from the configured watch schema with the filters passed through.
	 *
	 * @return void
	 */
	public function testWatchesPassesSchemaAndFilters(): void {
		$rows = [['id' => 'w1'], ['id' => 'w2']];
		$this->store->expects($this->once())->method('findAll')
			->with('cfg:competitorWatch_schema:competitorWatch', ['competitor' => 'c1'])
			->willReturn($rows);

		$this->assertSame($rows, $this->service->watches(filters: ['competitor' => 'c1']));
	}//end testWatchesPassesSchemaAndFilters()

	/**
	 * Without filters, watches are read with an empty filter set.
	 *
	 * @return void
	 */
	public function testWatchesDefaultsToNoFilters(): void {
		$this->store->expects($this->once())->method('findAll')
			->with('cfg:competitorWatch_schema:competitorWatch', [])
			->willReturn([]);

		$this->assertSame([], $this->service->watches());
	}//end testWatchesDefaultsToNoFilters()

	/**
	 * Competitors are read from the configured competitor schema, unfiltered.
	 *
	 * @return void
	 */
	public function testCompetitorsReadsTheCompetitorSchema(): void {
		$rows = [['id' => 'c1', 'name' => 'Acme']];
		$this->store->expects($this->once())->method('findAll')
			->with('cfg:competitor_schema:competitor')
			->willReturn($rows);

		$this->assertSame($rows, $this->service->competitors());
	}//end testCompetitorsReadsTheCompetitorSchema()

	/**
	 * One watch is found by its trimmed id, and a miss stays null.
	 *
	 * @return void
	 */
	public function testWatchTrimsTheIdAndReturnsTheStoreAnswer(): void {
		$this->store->expects($this->exactly(2))->method('find')
			->willReturnCallback(
				static function (string $schemaSlug, string $id): ?array {
					if ($schemaSlug !== 'cfg:competitorWatch_schema:competitorWatch') {
						return ['wrong schema' => $schemaSlug];
					}

					return ($id === 'w1') ? ['id' => 'w1'] : null;
				}
			);

		$this->assertSame(['id' => 'w1'], $this->service->watch(id: '  w1 '));
		$this->assertNull($this->service->watch(id: 'w9'));
	}//end testWatchTrimsTheIdAndReturnsTheStoreAnswer()
}//end class
