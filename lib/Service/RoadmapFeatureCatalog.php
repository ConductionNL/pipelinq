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
class RoadmapFeatureCatalog {
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
	 * docs/features.json (which can drift). Cached per app version — the specs
	 * only change when the app updates — with the committed docs/features.json
	 * as a fallback for deploys that ship without openspec/.
	 *
	 * @return array<int, array{slug:string, title:string, summary:string, docsUrl:string}>
	 *
	 * @spec openspec/specs/features-roadmap/spec.md
	 */
	public function load(): array {
		$version = (string)$this->appManager->getAppVersion('pipelinq');
		$cache = $this->cacheFactory->createLocal('pipelinq_features');
		$cacheKey = 'v' . $version;

		$cached = $cache->get($cacheKey);
		if (is_array($cached) === true) {
			return $cached;
		}

		$features = $this->extractFeaturesFromSpecs(specsDir: __DIR__ . '/../../openspec/specs');
		if ($features === []) {
			$path = __DIR__ . '/../../docs/features.json';
			if (is_file($path) === true) {
				$decoded = json_decode((string)file_get_contents($path), associative: true);
				if (is_array($decoded) === true) {
					$features = $decoded;
				}
			}
		}

		$cache->set($cacheKey, $features, 86400);
		return $features;
	}//end load()

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
		if (is_dir($specsDir) === false) {
			return [];
		}

		$paths = glob($specsDir . '/*/spec.md');
		if ($paths === false) {
			return [];
		}

		$entries = [];
		foreach ($paths as $specPath) {
			$text = (string)file_get_contents($specPath);
			if (preg_match('/^---\s*\n(.*?\n)---\s*\n(.*)$/s', $text, $matches) !== 1) {
				continue;
			}

			$front = $matches[1];
			$body = $matches[2];
			if (preg_match('/^status:\s*(.+?)\s*$/m', $front, $statusMatch) !== 1) {
				continue;
			}

			if (strtolower(trim($statusMatch[1], " \t\"'")) !== 'done') {
				continue;
			}

			$slug = basename(dirname($specPath));
			$title = $slug;
			if (preg_match('/^#\s+(.+?)\s*$/m', $body, $titleMatch) === 1) {
				$title = trim((string)preg_replace('/\s+specification\s*$/i', '', trim($titleMatch[1])));
			}

			$entries[] = [
				'slug' => $slug,
				'title' => $title,
				'summary' => $this->extractSummary(body: $body),
				'docsUrl' => 'openspec/specs/' . $slug . '/spec.md',
			];
		}//end foreach

		// Sort by slug (not full path) to match extract-features.py and
		// extractFeatures.js, which order by the capability slug.
		usort($entries, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
		return $entries;
	}//end extractFeaturesFromSpecs()

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
