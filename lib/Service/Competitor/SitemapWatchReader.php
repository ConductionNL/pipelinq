<?php

/**
 * Pipelinq SitemapWatchReader.
 *
 * A sitemap is the most honest source a competitor publishes: it lists every
 * page they want found, and it says when each one last changed. Diffing it
 * answers "what did they publish, and what did they quietly rewrite" without
 * reading anything they did not put in front of a crawler.
 *
 * TWO LISTS, NEVER ONE. New locations and changed locations are different
 * things to a marketer: a new page is a topic they have decided to own, and a
 * changed page is one they think is not working. Collapsing them would lose
 * the more interesting half.
 *
 * A LOCATION WITHOUT A `lastmod` IS UNCHANGED ONCE SEEN. Plenty of sitemaps
 * carry no dates at all, and treating a dateless location as changed on every
 * run would report the competitor's entire site every night, which is the same
 * as reporting nothing.
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-sitemap-watch-reports-new-and-changed-locations
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use SimpleXMLElement;
use Throwable;

/**
 * New and changed locations out of a sitemap.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-sitemap-watch-reports-new-and-changed-locations
 */
class SitemapWatchReader {

	/**
	 * How deep a sitemap index is followed. One level is what the protocol
	 * itself allows; a deeper nest is a misconfiguration, not a feature.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 1;

	/**
	 * Locations read from one sitemap tree, at most.
	 *
	 * @var int
	 */
	public const MAX_LOCATIONS = 5000;

	/**
	 * Constructor.
	 *
	 * @param ConnectorEgress $egress The single outbound seam.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-sitemap-watch-reports-new-and-changed-locations
	 */
	public function __construct(
		private ConnectorEgress $egress,
	) {
	}//end __construct()

	/**
	 * Read a sitemap and report what is new and what changed since the
	 * locations already recorded.
	 *
	 * @param string $url The sitemap URL.
	 * @param array<string, string> $previous Location to stamp, as last seen.
	 * @param string $sourceId The OpenConnector source to read it through.
	 *
	 * @return WatchOutcome The new and changed locations, or the reason there are none.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-sitemap-watch-reports-new-and-changed-locations
	 */
	public function read(string $url, array $previous, string $sourceId = ''): WatchOutcome {
		$current = $this->collect(url: $url, sourceId: $sourceId, depth: 0);
		if (is_array($current) === false) {
			return WatchOutcome::failed(outcome: $current->outcome, reason: $current->reason);
		}

		$diff = $this->diff(previous: $previous, current: $current);
		$items = [];
		foreach ($diff['new'] as $loc => $stamp) {
			$items[] = ['url' => (string)$loc, 'title' => (string)$loc, 'summary' => 'New in the sitemap.', 'stamp' => (string)$stamp];
		}

		foreach ($diff['changed'] as $loc => $stamp) {
			$items[] = ['url' => (string)$loc, 'title' => (string)$loc, 'summary' => 'Changed in the sitemap.', 'stamp' => (string)$stamp];
		}

		return WatchOutcome::seen(items: $items, state: ['locations' => $current]);
	}//end read()

	/**
	 * What is new and what changed between two sitemap states.
	 *
	 * @param array<string, string> $previous Location to stamp, as last seen.
	 * @param array<string, string> $current Location to stamp, as read now.
	 *
	 * @return array{new: array<string, string>, changed: array<string, string>}
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-sitemap-watch-reports-new-and-changed-locations
	 */
	public function diff(array $previous, array $current): array {
		$new = [];
		$changed = [];
		foreach ($current as $loc => $stamp) {
			if (array_key_exists($loc, $previous) === false) {
				$new[$loc] = $stamp;
				continue;
			}

			$before = trim((string)$previous[$loc]);
			$after = trim((string)$stamp);
			if ($before === '' || $after === '' || $before === $after) {
				continue;
			}

			$changed[$loc] = $stamp;
		}

		return ['new' => $new, 'changed' => $changed];
	}//end diff()

	/**
	 * Parse one sitemap document.
	 *
	 * @param string $document The sitemap or sitemap index.
	 *
	 * @return array{index: bool, locations: array<string, string>}|null The state, or null when it is not a sitemap.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-sitemap-watch-reports-new-and-changed-locations
	 */
	public function parse(string $document): ?array {
		if (trim($document) === '') {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$xml = simplexml_load_string($document, SimpleXMLElement::class, (LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
		} catch (Throwable) {
			return null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($xml === false) {
			return null;
		}

		$isIndex = (isset($xml->sitemap) === true);
		$children = ($xml->url ?? null);
		if ($isIndex === true) {
			$children = $xml->sitemap;
		}

		if ($children === null) {
			return null;
		}

		$locations = [];
		foreach ($children as $child) {
			$loc = trim((string)$child->loc);
			if ($loc === '') {
				continue;
			}

			$locations[$loc] = trim((string)$child->lastmod);
			if (count($locations) >= self::MAX_LOCATIONS) {
				break;
			}
		}

		return ['index' => $isIndex, 'locations' => $locations];
	}//end parse()

	/**
	 * Read a sitemap, following an index one level.
	 *
	 * @param string $url The sitemap URL.
	 * @param string $sourceId The source to read through.
	 * @param int $depth How deep we already are.
	 *
	 * @return array<string, string>|WatchOutcome The locations, or the failure.
	 */
	private function collect(string $url, string $sourceId, int $depth): array|WatchOutcome {
		$result = $this->egress->readUrl(configKey: CompetitorWatchService::SOURCE_KEY, url: $url, sourceId: $sourceId);
		if ($result->ok === false) {
			return WatchOutcome::failed(outcome: (string)$result->failure, reason: $result->reason);
		}

		$parsed = $this->parse(document: $result->body);
		if ($parsed === null) {
			return WatchOutcome::failed(
				outcome: EgressResult::UNPARSABLE,
				reason: 'The answer from ' . $url . ' is not a sitemap.'
			);
		}

		if ($parsed['index'] === false) {
			return $parsed['locations'];
		}

		if ($depth >= self::MAX_DEPTH) {
			return WatchOutcome::failed(
				outcome: EgressResult::UNPARSABLE,
				reason: 'The sitemap index at ' . $url . ' nests deeper than one level.'
			);
		}

		$locations = [];
		foreach (array_keys($parsed['locations']) as $child) {
			$nested = $this->collect(url: (string)$child, sourceId: $sourceId, depth: ($depth + 1));
			if (is_array($nested) === false) {
				continue;
			}

			$locations = ($locations + $nested);
			if (count($locations) >= self::MAX_LOCATIONS) {
				break;
			}
		}

		return $locations;
	}//end collect()
}//end class
