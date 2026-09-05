<?php

/**
 * Pipelinq FeedWatchReader.
 *
 * Reads an RSS 2.0 or Atom feed and returns its entries. The two formats are
 * handled by one class because a marketer who pastes a feed URL does not know
 * or care which one their competitor's blog emits, and asking would be the
 * only place in this product where they had to.
 *
 * A body that is not XML is `unparsable`, never an empty feed. A site that
 * answers a feed URL with an HTML error page is the common case, and treating
 * it as "they published nothing this week" is the failure this whole phase is
 * built to avoid.
 *
 * The fetch is the egress seam's; the parse is a pure function over a string,
 * so both formats and the error case are asserted without a network.
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-feed-watch-reports-the-entries-it-has-not-seen
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use SimpleXMLElement;
use Throwable;

/**
 * RSS and Atom entries out of a feed.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-feed-watch-reports-the-entries-it-has-not-seen
 */
class FeedWatchReader {

	/**
	 * Entries returned from one feed, at most.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 50;

	/**
	 * Constructor.
	 *
	 * @param ConnectorEgress $egress The single outbound seam.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-feed-watch-reports-the-entries-it-has-not-seen
	 */
	public function __construct(
		private ConnectorEgress $egress,
	) {
	}//end __construct()

	/**
	 * Read one feed.
	 *
	 * @param string $url The feed URL.
	 * @param string $sourceId The OpenConnector source to read it through.
	 *
	 * @return WatchOutcome The entries, or the reason there are none.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-feed-watch-reports-the-entries-it-has-not-seen
	 */
	public function read(string $url, string $sourceId = ''): WatchOutcome {
		$result = $this->egress->readUrl(configKey: CompetitorWatchService::SOURCE_KEY, url: $url, sourceId: $sourceId);
		if ($result->ok === false) {
			return WatchOutcome::failed(outcome: (string)$result->failure, reason: $result->reason);
		}

		$entries = $this->parse(document: $result->body);
		if ($entries === null) {
			return WatchOutcome::failed(
				outcome: EgressResult::UNPARSABLE,
				reason: 'The answer from ' . $url . ' is not an RSS or Atom feed.'
			);
		}

		return WatchOutcome::seen(items: $entries);
	}//end read()

	/**
	 * Parse a feed document.
	 *
	 * @param string $document The RSS or Atom document.
	 *
	 * @return array<int, array<string, mixed>>|null The entries, or null when the document is not a feed.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-feed-watch-reports-the-entries-it-has-not-seen
	 */
	public function parse(string $document): ?array {
		$xml = $this->load(document: $document);
		if ($xml === null) {
			return null;
		}

		$items = $this->rssItems(xml: $xml);
		if ($items !== null) {
			return $items;
		}

		return $this->atomEntries(xml: $xml);
	}//end parse()

	/**
	 * The `<item>` children of an RSS channel, or null when this is not RSS.
	 *
	 * @param SimpleXMLElement $xml The document.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function rssItems(SimpleXMLElement $xml): ?array {
		$channel = ($xml->channel ?? null);
		if ($channel === null || isset($channel->item) === false) {
			return null;
		}

		$out = [];
		foreach ($channel->item as $item) {
			$link = trim((string)$item->link);
			if ($link === '') {
				continue;
			}

			$guid = trim((string)$item->guid);
			$stamp = $guid;
			if ($stamp === '') {
				$stamp = trim((string)$item->pubDate);
			}

			$out[] = [
				'url' => $link,
				'title' => trim((string)$item->title),
				'summary' => $this->shorten(value: trim((string)$item->description)),
				'stamp' => $stamp,
				'publishedAt' => $this->instant(value: trim((string)$item->pubDate)),
			];
			if (count($out) >= self::MAX_ENTRIES) {
				break;
			}
		}

		return $out;
	}//end rssItems()

	/**
	 * The `<entry>` children of an Atom feed, or null when this is not Atom.
	 *
	 * @param SimpleXMLElement $xml The document.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function atomEntries(SimpleXMLElement $xml): ?array {
		if (isset($xml->entry) === false) {
			return null;
		}

		$out = [];
		foreach ($xml->entry as $entry) {
			$link = trim((string)($entry->link['href'] ?? ''));
			if ($link === '') {
				$link = trim((string)$entry->id);
			}

			if ($link === '') {
				continue;
			}

			$stamp = trim((string)$entry->id);
			if ($stamp === '') {
				$stamp = trim((string)$entry->updated);
			}

			$published = trim((string)$entry->published);
			if ($published === '') {
				$published = trim((string)$entry->updated);
			}

			$out[] = [
				'url' => $link,
				'title' => trim((string)$entry->title),
				'summary' => $this->shorten(value: trim((string)$entry->summary)),
				'stamp' => $stamp,
				'publishedAt' => $this->instant(value: $published),
			];
			if (count($out) >= self::MAX_ENTRIES) {
				break;
			}
		}

		return $out;
	}//end atomEntries()

	/**
	 * Parse the document as XML with libxml's own errors collected, never
	 * logged: a competitor's feed being slightly invalid is not our incident.
	 *
	 * @param string $document The document.
	 *
	 * @return SimpleXMLElement|null The root, or null.
	 */
	private function load(string $document): ?SimpleXMLElement {
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

		return $xml;
	}//end load()

	/**
	 * An ISO 8601 instant, falling back to now when the feed carries no date.
	 *
	 * @param string $value The date as the feed wrote it.
	 *
	 * @return string An ISO 8601 instant.
	 */
	private function instant(string $value): string {
		$parsed = false;
		if ($value !== '') {
			$parsed = strtotime($value);
		}

		if ($parsed === false) {
			return gmdate('Y-m-d\TH:i:s\Z');
		}

		return gmdate('Y-m-d\TH:i:s\Z', $parsed);
	}//end instant()

	/**
	 * A plain-text summary, capped so a whole article body cannot become one.
	 *
	 * @param string $value The description or summary.
	 *
	 * @return string
	 */
	private function shorten(string $value): string {
		$text = trim(strip_tags($value));
		if (mb_strlen($text, 'UTF-8') <= 500) {
			return $text;
		}

		return (mb_substr($text, 0, 497, 'UTF-8') . '...');
	}//end shorten()
}//end class
