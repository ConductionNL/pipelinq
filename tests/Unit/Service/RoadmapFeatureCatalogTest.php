<?php

/**
 * Unit tests for RoadmapFeatureCatalog.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\RoadmapFeatureCatalog;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * The Features & Roadmap list moved out of Application keeps its behaviour.
 */
class RoadmapFeatureCatalogTest extends TestCase {
	/**
	 * Build the catalog over a cache that holds the given value.
	 *
	 * @param mixed $cached What the cache returns.
	 * @param ICache|null $cache The cache mock, filled in.
	 *
	 * @return RoadmapFeatureCatalog
	 */
	private function catalog(mixed $cached, ?ICache &$cache = null): RoadmapFeatureCatalog {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.2.3');
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn($cached);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocal')->with('pipelinq_features')->willReturn($cache);

		return new RoadmapFeatureCatalog(appManager: $appManager, cacheFactory: $factory);
	}//end catalog()

	/**
	 * The real specs give done features, sorted by slug, with a summary.
	 *
	 * @return void
	 */
	public function testReadsDoneSpecsSortedBySlug(): void {
		$features = $this->catalog(cached: null)->extractFeaturesFromSpecs(specsDir: __DIR__.'/../../../openspec/specs');

		$this->assertNotEmpty($features);
		$slugs = array_column($features, 'slug');
		$sorted = $slugs;
		sort($sorted, SORT_STRING);
		$this->assertSame($sorted, $slugs);
		foreach ($features as $feature) {
			$this->assertSame('openspec/specs/'.$feature['slug'].'/spec.md', $feature['docsUrl']);
			$this->assertStringNotContainsStringIgnoringCase('specification', $feature['title']);
		}
	}//end testReadsDoneSpecsSortedBySlug()

	/**
	 * A missing directory gives no features.
	 *
	 * @return void
	 */
	public function testMissingDirectoryGivesNothing(): void {
		$this->assertSame([], $this->catalog(cached: null)->extractFeaturesFromSpecs(specsDir: '/nonexistent/specs'));
	}//end testMissingDirectoryGivesNothing()

	/**
	 * A cached list for this version is returned as is.
	 *
	 * @return void
	 */
	public function testLoadReturnsTheCachedList(): void {
		$cached = [['slug' => 'x', 'title' => 'X', 'summary' => '', 'docsUrl' => 'openspec/specs/x/spec.md']];
		$cache  = null;
		$catalog = $this->catalog(cached: $cached, cache: $cache);
		$cache->expects($this->never())->method('set');

		$this->assertSame($cached, $catalog->load());
	}//end testLoadReturnsTheCachedList()

	/**
	 * Without a cached list it reads the specs and caches them under the version.
	 *
	 * @return void
	 */
	public function testLoadReadsAndCachesPerVersion(): void {
		$cache = null;
		$catalog = $this->catalog(cached: null, cache: $cache);
		$cache->expects($this->once())->method('set')->with(
			'v1.2.3:'.md5_file(RoadmapFeatureCatalog::CURATED_PATH),
			$this->isType(type: 'array'),
			86400
		);

		$this->assertNotEmpty($catalog->load());
	}//end testLoadReadsAndCachesPerVersion()

	/**
	 * The curated list is the board's: 36 features in five groups, each with a
	 * known status, Dutch texts, and a link to a spec that exists.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/roadmap-curated-list/spec.md#requirement-the-page-reads-the-curated-feature-list
	 */
	public function testCuratedListIsTheBoards(): void {
		$catalog    = $this->catalog(cached: null);
		$features   = $catalog->curatedFeatures(path: __DIR__.'/../../../lib/Settings/roadmap.json');
		$categories = $catalog->categories(path: __DIR__.'/../../../lib/Settings/roadmap.json');

		$this->assertCount(expectedCount: 36, haystack: $features);
		$this->assertSame(
			expected: ['core-crm', 'customer-contact', 'insight', 'automation', 'infrastructure'],
			actual: array_keys($categories)
		);
		foreach ($features as $feature) {
			$this->assertArrayHasKey(key: $feature['category'], array: $categories, message: $feature['slug']);
			$this->assertContains(needle: $feature['status'], haystack: ['available', 'beta', 'planned'], message: $feature['slug']);
			$this->assertNotSame(expected: '', actual: $feature['title_nl'], message: $feature['slug']);
			$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $feature['title'].$feature['summary'], message: $feature['slug']);
			if ($feature['docsUrl'] !== '') {
				$this->assertFileExists(filename: __DIR__.'/../../../'.$feature['docsUrl'], message: $feature['slug']);
			}
		}
	}//end testCuratedListIsTheBoards()

	/**
	 * Without a curated file there is no curated list, and no categories.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/roadmap-curated-list/spec.md#requirement-the-page-reads-the-curated-feature-list
	 */
	public function testNoCuratedFile(): void {
		$catalog = $this->catalog(cached: null);
		$this->assertNull(actual: $catalog->curatedFeatures(path: '/nonexistent/roadmap.json'));
		$this->assertSame(expected: [], actual: $catalog->categories(path: '/nonexistent/roadmap.json'));
	}//end testNoCuratedFile()

	/**
	 * The page gets the curated list, not the raw specs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/roadmap-curated-list/spec.md#requirement-the-page-reads-the-curated-feature-list
	 */
	public function testLoadPrefersTheCuratedList(): void {
		$features = $this->catalog(cached: null)->load();
		$this->assertCount(expectedCount: 36, haystack: $features);
		$this->assertSame(expected: 'client-management', actual: $features[0]['slug']);
		$this->assertSame(expected: 'core-crm', actual: $features[0]['category']);
	}//end testLoadPrefersTheCuratedList()

	/**
	 * A list cached before the curated file changed (same app version) is
	 * not served: the cache key follows the curated file's content.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/roadmap-curated-list/spec.md#requirement-the-page-reads-the-curated-feature-list
	 */
	public function testStaleListUnderTheBareVersionKeyIsNotServed(): void {
		$stale      = [['slug' => 'stale', 'title' => 'Stale', 'summary' => '', 'docsUrl' => '']];
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.2.3');
		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('get')->willReturnCallback(
			static function (string $key) use ($stale): ?array {
				if ($key === 'v1.2.3') {
					return $stale;
				}

				return null;
			}
		);
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('createLocal')->willReturn($cache);

		$features = (new RoadmapFeatureCatalog(appManager: $appManager, cacheFactory: $factory))->load();
		$this->assertCount(expectedCount: 36, haystack: $features);
	}//end testStaleListUnderTheBareVersionKeyIsNotServed()
}//end class
