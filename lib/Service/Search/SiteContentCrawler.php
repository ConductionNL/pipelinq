<?php

/**
 * Pipelinq SiteContentCrawler.
 *
 * Reads our own pages so the content-gap detector knows what they say.
 *
 * WHICH PAGES. The ones that already appear in the window's `searchQueryDaily`
 * rows, highest impressions first. That needs no sitemap and no configuration
 * beyond the source, and it is exactly the right set: a page nobody is ever
 * shown for is not the page a gap is about.
 *
 * The run is capped, because a large property has thousands of pages and one
 * request that fans out over all of them is the failure pipelinq#1781 already
 * cost this app once.
 *
 * WITHOUT A SOURCE THERE IS NO CRAWL, AND THAT IS NOT AN EMPTY SITE. The
 * result carries the reason, and the gap section renders it. An empty page
 * list presented as a successful crawl would make every query with demand a
 * content gap, which is the most confident wrong answer this phase could give.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Search
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-our-own-pages-are-crawled-through-the-egress-plane
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;

/**
 * Crawls our own pages through the egress plane.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-our-own-pages-are-crawled-through-the-egress-plane
 */
class SiteContentCrawler {

	/**
	 * App-config key naming the OpenConnector source that reaches our site.
	 *
	 * @var string
	 */
	public const SOURCE_KEY = 'search.crawl_source';

	/**
	 * Pages fetched in one run, at most.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param ConnectorEgress $egress The single outbound seam.
	 * @param HtmlTextExtractor $extractor Title and headings out of a document.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-our-own-pages-are-crawled-through-the-egress-plane
	 */
	public function __construct(
		private ConnectorEgress $egress,
		private HtmlTextExtractor $extractor,
	) {
	}//end __construct()

	/**
	 * The distinct pages of a window, most impressions first.
	 *
	 * Public so the ordering and the cap are testable without a network.
	 *
	 * @param array<int, array<string, mixed>> $rows `searchQueryDaily` rows.
	 * @param int $limit How many pages at most.
	 *
	 * @return array<int, string> The page URLs, in crawl order.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-our-own-pages-are-crawled-through-the-egress-plane
	 */
	public function pagesToCrawl(array $rows, int $limit = self::DEFAULT_LIMIT): array {
		$impressions = [];
		foreach ($rows as $row) {
			$page = trim((string)($row['page'] ?? ''));
			if ($page === '') {
				continue;
			}

			$impressions[$page] = (($impressions[$page] ?? 0) + max(0, (int)($row['impressions'] ?? 0)));
		}

		arsort($impressions);

		return array_slice(array_keys($impressions), 0, max(0, $limit));
	}//end pagesToCrawl()

	/**
	 * Crawl the pages of a window.
	 *
	 * @param array<int, array<string, mixed>> $rows `searchQueryDaily` rows.
	 * @param int $limit How many pages at most.
	 *
	 * @return array{crawled: bool, reason: string, failure: string|null, pages: array<int, array{url: string, text: string}>}
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-our-own-pages-are-crawled-through-the-egress-plane
	 */
	public function crawl(array $rows, int $limit = self::DEFAULT_LIMIT): array {
		if ($this->egress->isConfigured(configKey: self::SOURCE_KEY) === false) {
			return [
				'crawled' => false,
				'failure' => EgressResult::NOT_CONFIGURED,
				'reason' => 'No crawl source is configured, so the content gap check did not run.',
				'pages' => [],
			];
		}

		$urls = $this->pagesToCrawl(rows: $rows, limit: $limit);
		if ($urls === []) {
			return [
				'crawled' => false,
				'failure' => EgressResult::NOT_CONFIGURED,
				'reason' => 'No page has been shown in search yet, so there is nothing to crawl.',
				'pages' => [],
			];
		}

		$pages = [];
		$lastFailure = null;
		$lastReason = '';
		foreach ($urls as $url) {
			$result = $this->egress->readUrl(configKey: self::SOURCE_KEY, url: $url);
			if ($result->ok === false) {
				$lastFailure = $result->failure;
				$lastReason = $result->reason;
				continue;
			}

			$pages[] = ['url' => $url, 'text' => $this->extractor->headline(html: $result->body)];
		}

		if ($pages === []) {
			$reason = $lastReason;
			if ($reason === '') {
				$reason = 'No page could be read, so the content gap check did not run.';
			}

			return [
				'crawled' => false,
				'failure' => ($lastFailure ?? EgressResult::UNAVAILABLE),
				'reason' => $reason,
				'pages' => [],
			];
		}

		return ['crawled' => true, 'failure' => null, 'reason' => '', 'pages' => $pages];
	}//end crawl()
}//end class
