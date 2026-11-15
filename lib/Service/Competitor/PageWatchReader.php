<?php

/**
 * Pipelinq PageWatchReader.
 *
 * Watches one fragment of one page: the element a CSS selector picks out. The
 * selector is the point. A whole page changes on every deploy (a build hash, a
 * cookie banner, a copyright year), so a watch over the whole document reports
 * a change every night and is switched off within a week.
 *
 * WHAT IS STORED IS FINGERPRINTS, NOT TEXT. Watching a page is not archiving
 * somebody else's page, so the watch keeps a hash of the fragment and a hash
 * per line, never the words. That has one consequence the summary has to be
 * honest about: the lines that were ADDED can be quoted, because they come out
 * of this run's own fresh fetch, and the lines that were REMOVED can only be
 * counted, because the text they held was deliberately not kept.
 *
 * A SELECTOR THAT MATCHES NOTHING IS REPORTED. Silently treating it as "no
 * change" would leave a watch that has been dead since the competitor renamed
 * a div looking exactly like a watch on a page that never changes.
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
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Search\HtmlTextExtractor;

/**
 * Change detection over a CSS-selected fragment.
 *
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The named constructors of the
 *  immutable result type this class returns (WatchOutcome). A value
 *  object's own factory is not the hidden dependency StaticAccess exists
 *  to catch: there is nothing here that could be injected, and the
 *  alternative is a constructor call whose argument order says less than
 *  the method name does.
 */
class PageWatchReader {

	/**
	 * Line fingerprints kept per watch, at most. A fragment longer than this
	 * is compared on its whole-fragment hash alone, which still detects the
	 * change; only the added-line detail is lost.
	 *
	 * @var int
	 */
	public const MAX_LINES = 200;

	/**
	 * Added lines quoted in a summary, at most.
	 *
	 * @var int
	 */
	public const MAX_QUOTED = 5;

	/**
	 * Constructor.
	 *
	 * @param ConnectorEgress $egress The single outbound seam.
	 * @param HtmlTextExtractor $extractor The CSS-fragment selection.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
	 */
	public function __construct(
		private ConnectorEgress $egress,
		private HtmlTextExtractor $extractor,
	) {
	}//end __construct()

	/**
	 * Read a page and report whether its watched fragment changed.
	 *
	 * @param string $url The page URL.
	 * @param string $selector The CSS selector of the fragment.
	 * @param string $fingerprint The fragment's fingerprint as last seen.
	 * @param array<int, string> $lineFingerprints The line fingerprints as last seen.
	 * @param string $sourceId The OpenConnector source to read it through.
	 *
	 * @return WatchOutcome One item when the fragment changed, none when it did not.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
	 */
	public function read(
		string $url,
		string $selector,
		string $fingerprint,
		array $lineFingerprints,
		string $sourceId = '',
	): WatchOutcome {
		return $this->outcomeOf(
			page: ['url' => $url, 'selector' => $selector, 'sourceId' => $sourceId],
			previous: ['fingerprint' => $fingerprint, 'lineFingerprints' => $lineFingerprints]
		);
	}//end read()

	/**
	 * Read the page's fragment and compare it against the fingerprints as last seen.
	 *
	 * @param array{url: string, selector: string, sourceId: string}            $page     The page URL, fragment selector and source.
	 * @param array{fingerprint: string, lineFingerprints: array<int, string>} $previous The fingerprints as last seen.
	 *
	 * @return WatchOutcome One item when the fragment changed, none when it did not.
	 */
	private function outcomeOf(array $page, array $previous): WatchOutcome {
		$result = $this->egress->readUrl(configKey: CompetitorWatchService::SOURCE_KEY, url: $page['url'], sourceId: $page['sourceId']);
		if ($result->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$result->failure, reason: $result->reason);
		}

		$fragment = $this->extractor->fragment(html: $result->body, selector: $page['selector']);
		if ($fragment === null) {
			return self::unmatched(selector: $page['selector'], url: $page['url']);
		}

		return self::seen(url: $page['url'], diff: $this->diffAgainst(previous: $previous, fragment: $fragment));
	}//end outcomeOf()

	/**
	 * The failure of a selector that matched nothing on the page.
	 *
	 * @param string $selector The CSS selector of the fragment.
	 * @param string $url      The page URL.
	 *
	 * @return WatchOutcome
	 */
	private static function unmatched(string $selector, string $url): WatchOutcome {
		return WatchOutcome::failed(
			outcome: EgressResult::UNPARSABLE,
			reason: 'The selector ' . $selector . ' matched nothing on ' . $url . '.'
		);
	}//end unmatched()

	/**
	 * The outcome of a read fragment: its new state, and one item when it changed.
	 *
	 * @param string               $url  The page URL.
	 * @param array<string, mixed> $diff The diff of the fragment.
	 *
	 * @return WatchOutcome
	 */
	private static function seen(string $url, array $diff): WatchOutcome {
		$state = ['fingerprint' => $diff['fingerprint'], 'lineFingerprints' => $diff['lineFingerprints']];
		if ($diff['changed'] === false) {
			return WatchOutcome::seen(items: [], state: $state);
		}

		return WatchOutcome::seen(items: [self::changeItem(url: $url, diff: $diff)], state: $state);
	}//end seen()

	/**
	 * The one item a changed fragment reports.
	 *
	 * @param string               $url  The page URL.
	 * @param array<string, mixed> $diff The diff of the fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function changeItem(string $url, array $diff): array {
		return [
			'url' => $url,
			'title' => $url,
			'summary' => $diff['summary'],
			'stamp' => $diff['fingerprint'],
		];
	}//end changeItem()

	/**
	 * Compare a fresh fragment against the fingerprints of the previous one.
	 *
	 * @param string $fingerprint The fragment's fingerprint as last seen.
	 * @param array<int, string> $lineFingerprints The line fingerprints as last seen.
	 * @param string $fragment The fragment as read now.
	 *
	 * @return array{changed: bool, fingerprint: string, lineFingerprints: array<int, string>, summary: string, added: array<int, string>, removed: int}
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
	 */
	public function diff(string $fingerprint, array $lineFingerprints, string $fragment): array {
		return $this->diffAgainst(previous: ['fingerprint' => $fingerprint, 'lineFingerprints' => $lineFingerprints], fragment: $fragment);
	}//end diff()

	/**
	 * Compare a fresh fragment against the fingerprints as last seen.
	 *
	 * @param array{fingerprint: string, lineFingerprints: array<int, string>} $previous The fingerprints as last seen.
	 * @param string                                                          $fragment The fragment as read now.
	 *
	 * @return array{changed: bool, fingerprint: string, lineFingerprints: array<int, string>, summary: string, added: array<int, string>, removed: int}
	 */
	private function diffAgainst(array $previous, string $fragment): array {
		$current = $this->currentLines(fragment: $fragment);
		$seen = [
			'fingerprint' => $this->hash(value: $fragment),
			'lineFingerprints' => array_slice(array_keys($current), 0, self::MAX_LINES),
		];
		if ($previous['fingerprint'] !== '' && $previous['fingerprint'] === $seen['fingerprint']) {
			return ['changed' => false] + $seen + ['summary' => '', 'added' => [], 'removed' => 0];
		}

		return ['changed' => true] + $seen + $this->changes(current: $current, previous: $previous);
	}//end diffAgainst()

	/**
	 * The fresh fragment's lines by fingerprint.
	 *
	 * @param string $fragment The fragment as read now.
	 *
	 * @return array<string, string>
	 */
	private function currentLines(string $fragment): array {
		$current = [];
		foreach ($this->lines(fragment: $fragment) as $line) {
			$current[$this->hash(value: $line)] = $line;
		}

		return $current;
	}//end currentLines()

	/**
	 * The summary, added lines and removed count of a changed fragment.
	 *
	 * @param array<string, string>                                         $current  The fresh lines by fingerprint.
	 * @param array{fingerprint: string, lineFingerprints: array<int, string>} $previous The fingerprints as last seen.
	 *
	 * @return array{summary: string, added: array<int, string>, removed: int}
	 */
	private function changes(array $current, array $previous): array {
		$added = self::addedLines(current: $current, lineFingerprints: $previous['lineFingerprints']);
		$removed = self::removedCount(lineFingerprints: $previous['lineFingerprints'], current: $current);

		$summary = 'First reading of this fragment.';
		if ($previous['fingerprint'] !== '') {
			$summary = $this->summary(added: $added, removed: $removed);
		}

		return ['summary' => $summary, 'added' => $added, 'removed' => $removed];
	}//end changes()

	/**
	 * The lines of the fresh fragment whose fingerprint was not seen last time.
	 *
	 * @param array<string, string> $current          The fresh lines by fingerprint.
	 * @param array<int, string>    $lineFingerprints The line fingerprints as last seen.
	 *
	 * @return array<int, string>
	 */
	private static function addedLines(array $current, array $lineFingerprints): array {
		$added = [];
		foreach ($current as $hash => $line) {
			if (in_array((string)$hash, $lineFingerprints, true) === false) {
				$added[] = $line;
			}
		}

		return $added;
	}//end addedLines()

	/**
	 * How many lines seen last time are gone from the fresh fragment.
	 *
	 * @param array<int, string>    $lineFingerprints The line fingerprints as last seen.
	 * @param array<string, string> $current          The fresh lines by fingerprint.
	 *
	 * @return int
	 */
	private static function removedCount(array $lineFingerprints, array $current): int {
		$removed = 0;
		foreach ($lineFingerprints as $hash) {
			if (array_key_exists($hash, $current) === false) {
				$removed++;
			}
		}

		return $removed;
	}//end removedCount()

	/**
	 * The summary a watch event carries.
	 *
	 * @param array<int, string> $added The lines this fetch added.
	 * @param int $removed How many lines are gone.
	 *
	 * @return string
	 */
	private function summary(array $added, int $removed): string {
		$parts = [];
		if ($added !== []) {
			$parts[] = ('Added: ' . implode(' / ', array_slice($added, 0, self::MAX_QUOTED)));
		}

		if ($removed > 0) {
			$parts[] = ($removed . ' line(s) removed. The previous text is not kept, so it cannot be quoted.');
		}

		if ($parts === []) {
			return 'The watched fragment changed.';
		}

		return implode(' ', $parts);
	}//end summary()

	/**
	 * The fragment split into comparable lines.
	 *
	 * @param string $fragment The fragment text.
	 *
	 * @return array<int, string> The non-empty lines.
	 */
	private function lines(string $fragment): array {
		$parts = preg_split('/(?<=[.!?])\s+|\R+/u', $fragment);
		if (is_array($parts) === false) {
			return [];
		}

		$out = [];
		foreach ($parts as $part) {
			$line = trim($part);
			if ($line !== '') {
				$out[] = $line;
			}
		}

		return $out;
	}//end lines()

	/**
	 * A short, stable fingerprint of a string.
	 *
	 * @param string $value The value.
	 *
	 * @return string Sixteen hexadecimal characters.
	 */
	private function hash(string $value): string {
		return substr(hash('sha256', $value), 0, 16);
	}//end hash()
}//end class
