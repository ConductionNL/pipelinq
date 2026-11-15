<?php

/**
 * Pipelinq RoadmapFeatureCatalog.
 *
 * Builds the Features & Roadmap list from openspec/specs at runtime.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/features-roadmap/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\RoadmapFeatureCatalogInterface;
use OCP\App\IAppManager;
use OCP\ICacheFactory;

/**
 * The Features & Roadmap list, read from the app's own specs.
 *
 * Moved out of Application (it was the one cohesive block there that does
 * not register or boot anything), so Application stays under the class
 * length limit.
 *
 * @spec openspec/specs/features-roadmap/spec.md
 */
class RoadmapFeatureCatalog implements RoadmapFeatureCatalogInterface {

	/**
	 * Constructor.
	 *
	 * @param IAppManager   $appManager   Reads the app version for the cache key.
	 * @param ICacheFactory $cacheFactory The local cache.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()

	/**
	 * Build the Features & Roadmap list from openspec/specs at runtime so the
	 * surface stays current with the specs without depending on a committed
	 * docs/features.json (which can drift). Cached per app version and curated file — the specs
	 * only change when the app updates — with the committed docs/features.json
	 * as a fallback for deploys that ship without openspec/.
	 *
	 * @return array<int, array{slug:string, title:string, summary:string, docsUrl:string}>
	 *
	 * @spec openspec/specs/features-roadmap/spec.md
	 */
	public function load(): array {
		$cacheKey = $this->cacheKey();
		$cache = $this->cacheFactory->createLocal('pipelinq_features');
		$cached = $cache->get($cacheKey);
		if (is_array($cached) === true) {
			return $cached;
		}

		$features = $this->freshFeatures();
		$cache->set($cacheKey, $features, 86400);
		return $features;
	}//end load()

	/**
	 * The cache key: the app version and the curated file's content. The
	 * curated file can change without a version bump (a deploy from a
	 * branch), so its content is part of the key.
	 *
	 * @return string
	 */
	private function cacheKey(): string {
		$version = (string)$this->appManager->getAppVersion('pipelinq');
		$curatedHash = 'specs';
		if (is_file(self::CURATED_PATH) === true) {
			$curatedHash = (string)md5_file(self::CURATED_PATH);
		}

		return 'v' . $version . ':' . $curatedHash;
	}//end cacheKey()

	/**
	 * The list read now: the curated file, else the done specs, else the
	 * committed docs/features.json.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function freshFeatures(): array {
		$features = ($this->curatedFeatures(path: self::CURATED_PATH) ?? $this->extractFeaturesFromSpecs(specsDir: __DIR__ . '/../../openspec/specs'));
		if ($features !== []) {
			return $features;
		}

		$path = __DIR__ . '/../../docs/features.json';
		if (is_file($path) === false) {
			return [];
		}

		$decoded = json_decode((string)file_get_contents($path), associative: true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end freshFeatures()

	/**
	 * The curated feature list: what a user gets, grouped and with a status,
	 * each linked to the spec it is built from when it names one. Null when
	 * the file is absent or unreadable, so the caller falls back to the specs.
	 *
	 * @param string $path The curated file.
	 *
	 * @return array<int, array<string, string>>|null The features, in the file's order.
	 *
	 * @spec openspec/specs/roadmap-curated-list/spec.md#requirement-the-page-reads-the-curated-feature-list
	 */
	public function curatedFeatures(string $path): ?array {
		$entries = $this->curatedEntries(path: $path);
		if ($entries === null) {
			return null;
		}

		$features = [];
		foreach ($entries as $entry) {
			$feature = $this->curatedFeature(entry: $entry);
			if ($feature !== null) {
				$features[] = $feature;
			}
		}

		return $features;
	}//end curatedFeatures()

	/**
	 * The curated file's feature entries; null when the file is absent,
	 * unreadable or holds no list of features.
	 *
	 * @param string $path The curated file.
	 *
	 * @return array<mixed>|null
	 */
	private function curatedEntries(string $path): ?array {
		$document = $this->readCurated(path: $path);
		if ($document === null || is_array($document['features'] ?? null) === false) {
			return null;
		}

		return $document['features'];
	}//end curatedEntries()

	/**
	 * One curated entry as a feature; null for an entry without a string slug.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return array<string, string>|null
	 */
	private function curatedFeature($entry): ?array {
		if (is_array($entry) === false || is_string($entry['slug'] ?? null) === false) {
			return null;
		}

		$feature = ['slug' => $entry['slug'], 'docsUrl' => $this->docsUrlOf(spec: ($entry['spec'] ?? null))];
		foreach (['category', 'status', 'title', 'title_nl', 'summary', 'summary_nl'] as $field) {
			$feature[$field] = (string)($entry[$field] ?? '');
		}

		return $feature;
	}//end curatedFeature()

	/**
	 * The docs link of the spec an entry names; '' when it names none.
	 *
	 * @param mixed $spec The entry's spec name.
	 *
	 * @return string
	 */
	private function docsUrlOf($spec): string {
		if (is_string($spec) === false || $spec === '') {
			return '';
		}

		return 'openspec/specs/' . $spec . '/spec.md';
	}//end docsUrlOf()

	/**
	 * The curated list's groups, key to caption, in the order the page shows them.
	 *
	 * @param string $path The curated file.
	 *
	 * @return array<string, string> The captions; empty without a curated file.
	 *
	 * @spec openspec/specs/roadmap-curated-list/spec.md#requirement-the-page-reads-the-curated-feature-list
	 */
	public function categories(string $path=self::CURATED_PATH): array {
		$document = $this->readCurated(path: $path);
		if ($document === null || is_array($document['categories'] ?? null) === false) {
			return [];
		}

		return array_map(static fn (mixed $caption): string => (string)$caption, $document['categories']);
	}//end categories()

	/**
	 * The curated file, decoded, or null.
	 *
	 * @param string $path The curated file.
	 *
	 * @return array<string, mixed>|null The document.
	 */
	private function readCurated(string $path): ?array {
		if (is_file($path) === false) {
			return null;
		}

		$decoded = json_decode((string)file_get_contents($path), associative: true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end readCurated()

	/**
	 * Parse `status: done` capability specs into feature entries. Mirrors the
	 * org-wide extract-features.py and the docusaurus extractFeatures.js: the
	 * status is read straight off the frontmatter line (resilient to YAML
	 * typos in sibling fields), the title is the H1 minus a trailing
	 * "Specification", and the summary is the first paragraph under `## Purpose`.
	 *
	 * @param string $specsDir Absolute path to openspec/specs.
	 *
	 * @return array<int, array{slug:string, title:string, summary:string, docsUrl:string}>
	 *
	 * @spec openspec/specs/features-roadmap/spec.md
	 */
	public function extractFeaturesFromSpecs(string $specsDir): array {
		$entries = [];
		foreach ($this->specPaths(specsDir: $specsDir) as $specPath) {
			$entry = $this->doneFeature(specPath: $specPath);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}

		// Sort by slug (not full path) to match extract-features.py and
		// extractFeatures.js, which order by the capability slug.
		usort($entries, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
		return $entries;
	}//end extractFeaturesFromSpecs()

	/**
	 * The spec files under the directory; none when it is absent or unreadable.
	 *
	 * @param string $specsDir Absolute path to openspec/specs.
	 *
	 * @return array<int, string>
	 */
	private function specPaths(string $specsDir): array {
		if (is_dir($specsDir) === false) {
			return [];
		}

		$paths = glob($specsDir . '/*/spec.md');
		if ($paths === false) {
			return [];
		}

		return $paths;
	}//end specPaths()

	/**
	 * The feature entry of one spec; null unless its status is `done`.
	 *
	 * @param string $specPath The spec file.
	 *
	 * @return array{slug:string, title:string, summary:string, docsUrl:string}|null
	 */
	private function doneFeature(string $specPath): ?array {
		$body = $this->doneBody(text: (string)file_get_contents($specPath));
		if ($body === null) {
			return null;
		}

		$slug = basename(dirname($specPath));
		return [
			'slug' => $slug,
			'title' => $this->titleOf(body: $body, slug: $slug),
			'summary' => $this->extractSummary(body: $body),
			'docsUrl' => 'openspec/specs/' . $slug . '/spec.md',
		];
	}//end doneFeature()

	/**
	 * The markdown body of a spec whose frontmatter status reads `done`
	 * (quotes and case ignored); null otherwise.
	 *
	 * @param string $text The spec file's text.
	 *
	 * @return string|null
	 */
	private function doneBody(string $text): ?string {
		if (preg_match('/^---\s*\n(.*?\n)---\s*\n(.*)$/s', $text, $matches) !== 1) {
			return null;
		}

		if (preg_match('/^status:\s*(.+?)\s*$/m', $matches[1], $statusMatch) !== 1) {
			return null;
		}

		if (strtolower(trim($statusMatch[1], " \t\"'")) !== 'done') {
			return null;
		}

		return $matches[2];
	}//end doneBody()

	/**
	 * The spec's H1 minus a trailing "Specification"; the slug without an H1.
	 *
	 * @param string $body The markdown body.
	 * @param string $slug The spec's slug.
	 *
	 * @return string
	 */
	private function titleOf(string $body, string $slug): string {
		if (preg_match('/^#\s+(.+?)\s*$/m', $body, $titleMatch) !== 1) {
			return $slug;
		}

		return trim((string)preg_replace('/\s+specification\s*$/i', '', trim($titleMatch[1])));
	}//end titleOf()

	/**
	 * Extract the first paragraph under `## Purpose` as the feature summary.
	 *
	 * @param string $body Spec markdown body (frontmatter stripped).
	 *
	 * @return string Collapsed single-line summary, or empty when absent.
	 */
	private function extractSummary(string $body): string {
		if (preg_match('/^##\s+Purpose\s*$/m', $body, $purposeMatch, PREG_OFFSET_CAPTURE) !== 1) {
			return '';
		}

		$rest = substr($body, ($purposeMatch[0][1] + strlen($purposeMatch[0][0])));
		$nextPos = strlen($rest);
		if (preg_match('/\n##\s/', $rest, $nextMatch, PREG_OFFSET_CAPTURE) === 1) {
			$nextPos = $nextMatch[0][1];
		}

		$section = trim(substr($rest, 0, $nextPos));
		$para = (preg_split('/\n\s*\n/', $section)[0] ?? '');
		return trim((string)preg_replace('/\s+/', ' ', $para));
	}//end extractSummary()
}//end class
