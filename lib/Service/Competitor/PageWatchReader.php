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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Search\HtmlTextExtractor;

/**
 * Change detection over a CSS-selected fragment.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
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
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
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
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
	 */
	public function read(
		string $url,
		string $selector,
		string $fingerprint,
		array $lineFingerprints,
		string $sourceId = '',
	): WatchOutcome {
		$result = $this->egress->readUrl(configKey: CompetitorWatchService::SOURCE_KEY, url: $url, sourceId: $sourceId);
		if ($result->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$result->failure, reason: $result->reason);
		}

		$fragment = $this->extractor->fragment(html: $result->body, selector: $selector);
		if ($fragment === null) {
			return WatchOutcome::failed(
				outcome: EgressResult::UNPARSABLE,
				reason: 'The selector ' . $selector . ' matched nothing on ' . $url . '.'
			);
		}

		$diff = $this->diff(fingerprint: $fingerprint, lineFingerprints: $lineFingerprints, fragment: $fragment);
		$state = ['fingerprint' => $diff['fingerprint'], 'lineFingerprints' => $diff['lineFingerprints']];
		if ($diff['changed'] === false) {
			return WatchOutcome::seen(items: [], state: $state);
		}

		return WatchOutcome::seen(
			items: [
				[
					'url' => $url,
					'title' => $url,
					'summary' => $diff['summary'],
					'stamp' => $diff['fingerprint'],
				],
			],
			state: $state
		);
	}//end read()

	/**
	 * Compare a fresh fragment against the fingerprints of the previous one.
	 *
	 * @param string $fingerprint The fragment's fingerprint as last seen.
	 * @param array<int, string> $lineFingerprints The line fingerprints as last seen.
	 * @param string $fragment The fragment as read now.
	 *
	 * @return array{changed: bool, fingerprint: string, lineFingerprints: array<int, string>, summary: string, added: array<int, string>, removed: int}
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-page-watch-diffs-a-selected-fragment-and-stores-a-fingerprint
	 */
	public function diff(string $fingerprint, array $lineFingerprints, string $fragment): array {
		$lines = $this->lines(fragment: $fragment);
		$current = [];
		foreach ($lines as $line) {
			$current[$this->hash(value: $line)] = $line;
		}

		$currentHashes = array_slice(array_keys($current), 0, self::MAX_LINES);
		$freshFingerprint = $this->hash(value: $fragment);
		if ($fingerprint !== '' && $fingerprint === $freshFingerprint) {
			return [
				'changed' => false,
				'fingerprint' => $freshFingerprint,
				'lineFingerprints' => $currentHashes,
				'summary' => '',
				'added' => [],
				'removed' => 0,
			];
		}

		$added = [];
		foreach ($current as $hash => $line) {
			if (in_array((string)$hash, $lineFingerprints, true) === false) {
				$added[] = $line;
			}
		}

		$removed = 0;
		foreach ($lineFingerprints as $hash) {
			if (array_key_exists($hash, $current) === false) {
				$removed++;
			}
		}

		return [
			'changed' => true,
			'fingerprint' => $freshFingerprint,
			'lineFingerprints' => $currentHashes,
			'summary' => $this->summary(added: $added, removed: $removed, first: ($fingerprint === '')),
			'added' => $added,
			'removed' => $removed,
		];
	}//end diff()

	/**
	 * The summary a watch event carries.
	 *
	 * @param array<int, string> $added The lines this fetch added.
	 * @param int $removed How many lines are gone.
	 * @param bool $first Whether this is the first time the fragment was read.
	 *
	 * @return string
	 */
	private function summary(array $added, int $removed, bool $first): string {
		if ($first === true) {
			return 'First reading of this fragment.';
		}

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
