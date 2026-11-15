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
	/**
	 * Every spec shape: only a `done` status (quoted, any case) is listed;
	 * no frontmatter or no status is skipped; the title drops a trailing
	 * "Specification" and falls back to the slug; the summary is the first
	 * Purpose paragraph, collapsed; the list is sorted by slug.
	 *
	 * @return void
	 */
	public function testEverySpecShape(): void {
		$dir = sys_get_temp_dir().'/roadmap-specs-'.uniqid();
		$specs = [
			'zeta'    => "---\nstatus: \"Done\"\n---\n# Zeta  Specification \n\n## Purpose\n\nFirst  line\nsecond line.\n\nNext para.\n\n## Other\nx\n",
			'alpha'   => "---\ntitle: x\nstatus: done\n---\nNo heading here.\n## Purpose\nOnly para\n",
			'beta'    => "---\nstatus: draft\n---\n# Beta\n",
			'gamma'   => "# Gamma without frontmatter\n",
			'delta'   => "---\nowner: me\n---\n# Delta\n",
			'epsilon' => "---\nstatus: 'DONE'\n---\n# Epsilon\n\n## Purpose\n",
		];
		foreach ($specs as $slug => $text) {
			mkdir($dir.'/'.$slug, 0777, true);
			file_put_contents($dir.'/'.$slug.'/spec.md', $text);
		}

		$features = $this->catalog(cached: null)->extractFeaturesFromSpecs(specsDir: $dir);
		array_map('unlink', glob($dir.'/*/spec.md'));
		array_map('rmdir', glob($dir.'/*'));
		rmdir($dir);

		$this->assertSame(
			[
				['slug' => 'alpha', 'title' => 'alpha', 'summary' => 'Only para', 'docsUrl' => 'openspec/specs/alpha/spec.md'],
				['slug' => 'epsilon', 'title' => 'Epsilon', 'summary' => '', 'docsUrl' => 'openspec/specs/epsilon/spec.md'],
				['slug' => 'zeta', 'title' => 'Zeta', 'summary' => 'First line second line.', 'docsUrl' => 'openspec/specs/zeta/spec.md'],
			],
			$features
		);
	}//end testEverySpecShape()

	/**
	 * Every curated entry shape: a non-list document or features is null; an
	 * entry without a string slug is skipped; missing fields are ''; the docs
	 * link is set only for a non-empty spec name.
	 *
	 * @return void
	 */
	public function testEveryCuratedEntryShape(): void {
		$path = tempnam(sys_get_temp_dir(), 'roadmap');
		$catalog = $this->catalog(cached: null);

		file_put_contents($path, '"text"');
		$this->assertNull($catalog->curatedFeatures(path: $path));
		file_put_contents($path, '{"features": "none"}');
		$this->assertNull($catalog->curatedFeatures(path: $path));

		file_put_contents($path, json_encode(['features' => [
			'loose',
			['title' => 'No slug'],
			['slug' => 7],
			['slug' => 'a', 'spec' => 'spec-a', 'title' => 'A', 'status' => 3, 'extra' => 'x'],
			['slug' => 'b', 'spec' => ''],
			['slug' => 'c', 'spec' => ['x']],
		]]));
		$empty = ['category' => '', 'status' => '', 'title' => '', 'title_nl' => '', 'summary' => '', 'summary_nl' => ''];
		$this->assertSame(
			[
				['slug' => 'a', 'docsUrl' => 'openspec/specs/spec-a/spec.md'] + ['category' => '', 'status' => '3', 'title' => 'A'] + $empty,
				['slug' => 'b', 'docsUrl' => ''] + $empty,
				['slug' => 'c', 'docsUrl' => ''] + $empty,
			],
			$catalog->curatedFeatures(path: $path)
		);
		unlink($path);
	}//end testEveryCuratedEntryShape()
}//end class
