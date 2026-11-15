<?php

/**
 * Pipelinq WatchReaders.
 *
 * Sends one competitor watch to the reader for its kind.
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
 * Every watch reader behind one dispatch on the watch's kind.
 *
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `WatchOutcome`'s named constructor.
 */
class WatchReaders {

	/**
	 * Constructor.
	 *
	 * @param UrlWatchReaders $urls The feed, sitemap and page readers.
	 * @param FediverseWatchReader $fediverse The Mastodon and Bluesky reader.
	 * @param SearchWatchReader $searches The hermiq web-search reader.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
	 */
	public function __construct(
		private UrlWatchReaders $urls,
		private FediverseWatchReader $fediverse,
		private SearchWatchReader $searches,
	) {
	}//end __construct()

	/**
	 * Read one watch with the reader for its kind.
	 *
	 * @param array<string, mixed> $watch The watch.
	 * @param string|null $actingUserId The identity the run acts as.
	 *
	 * @return WatchOutcome
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
	 */
	public function read(array $watch, ?string $actingUserId): WatchOutcome {
		$target = trim((string)($watch['target'] ?? ''));
		$sourceId = trim((string)($watch['connectorSourceId'] ?? ''));

		return match ((string)($watch['kind'] ?? '')) {
			'rss', 'sitemap', 'page' => $this->urls->read(watch: $watch),
			'fediverse' => $this->fediverse->read(handle: $target, sourceId: $sourceId),
			'search' => $this->searches->read(query: $target, actingUserId: $actingUserId),
			default => WatchOutcome::failed(
				outcome: EgressResult::NOT_CONFIGURED,
				reason: 'This watch has no kind that can be read.'
			),
		};
	}//end read()
}//end class
