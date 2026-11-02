<?php

/**
 * Pipelinq UrlWatchReaders.
 *
 * Reads a competitor watch whose target is a URL: a feed, a sitemap or a page.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Competitor
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
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\EgressResult;

/**
 * The feed, sitemap and page readers behind one dispatch on the watch's kind.
 *
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `WatchOutcome`'s named constructor.
 */
class UrlWatchReaders {

	/**
	 * Constructor.
	 *
	 * @param FeedWatchReader $feeds The RSS and Atom reader.
	 * @param SitemapWatchReader $sitemaps The sitemap reader.
	 * @param PageWatchReader $pages The page-fragment reader.
	 * @param WatchEventStore $events The events a sitemap watch compares against.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
	 */
	public function __construct(
		private FeedWatchReader $feeds,
		private SitemapWatchReader $sitemaps,
		private PageWatchReader $pages,
		private WatchEventStore $events,
	) {
	}//end __construct()

	/**
	 * Read one feed, sitemap or page watch with the reader for its kind.
	 *
	 * @param array<string, mixed> $watch The watch.
	 *
	 * @return WatchOutcome
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
	 */
	public function read(array $watch): WatchOutcome {
		$target = trim((string)($watch['target'] ?? ''));
		$sourceId = trim((string)($watch['connectorSourceId'] ?? ''));

		return match ((string)($watch['kind'] ?? '')) {
			'rss' => $this->feeds->read(url: $target, sourceId: $sourceId),
			'sitemap' => $this->sitemaps->read(
				url: $target,
				previous: $this->events->seenLocations(watch: $watch),
				sourceId: $sourceId
			),
			'page' => $this->pages->read(
				url: $target,
				selector: trim((string)($watch['selector'] ?? '')),
				fingerprint: trim((string)($watch['fingerprint'] ?? '')),
				lineFingerprints: array_map('strval', (array)($watch['lineFingerprints'] ?? [])),
				sourceId: $sourceId
			),
			default => WatchOutcome::failed(
				outcome: EgressResult::NOT_CONFIGURED,
				reason: 'This watch has no kind that can be read.'
			),
		};
	}//end read()
}//end class
