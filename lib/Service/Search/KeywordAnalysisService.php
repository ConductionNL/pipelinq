<?php

/**
 * Pipelinq KeywordAnalysisService.
 *
 * The four derivations phase 5 makes over the `searchQueryDaily` rows phase 2
 * imports: position buckets, striking distance, cannibalisation and content
 * gaps.
 *
 * EVERY METHOD IS PURE. It takes rows and returns findings; it resolves no
 * service, reads no configuration and writes nothing. That is not tidiness:
 * these four predicates are the substance of the phase and each can be wrong
 * in a way no integration test would notice, so every threshold is an argument
 * with a documented default and every boundary is asserted from both sides in
 * the unit suite.
 *
 * NOTHING HERE CREATES A KEYWORD TARGET. A finding is a proposal. A striking-
 * distance list recomputed daily would otherwise create and delete records
 * under the marketer's hands, and a keyword target is a commitment somebody is
 * going to write a page against. {@see KeywordTargetService::confirm()} is the
 * only write path.
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

/**
 * Position buckets, striking distance, cannibalisation and content gaps.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Four independent
 *  derivations over one row shape, each a short predicate with its own
 *  thresholds. They are together because they share the per-query and
 *  per-page aggregation and because a marketer reads them as one answer;
 *  splitting them into four classes would duplicate that aggregation four
 *  times and let the four copies drift, which is the defect this class is
 *  written to avoid.
 * @SuppressWarnings(PHPMD.StaticAccess) `ExpectedCtrCurve::rateAt()` is a
 *  pure lookup over a documented constant table. It holds no state and no
 *  collaborators, so there is nothing an injected instance could carry;
 *  making it a service would add DI wiring around a table.
 */
class KeywordAnalysisService {

	/**
	 * The bucket labels, in order, with their inclusive upper bound. A
	 * boundary belongs to the LOWER bucket, so exactly 3.0 is `1-3`.
	 *
	 * @var array<string, float>
	 */
	public const BUCKETS = [
		'1-3' => 3.0,
		'4-10' => 10.0,
		'11-20' => 20.0,
	];

	/**
	 * The bucket everything above the last bound falls into.
	 *
	 * @var string
	 */
	public const TAIL_BUCKET = '21+';

	/**
	 * Impressions a query needs over the window before any derivation calls
	 * it a finding. A hundred is roughly "somebody would notice this".
	 *
	 * @var int
	 */
	public const DEFAULT_MIN_IMPRESSIONS = 100;

	/**
	 * The lowest position that counts as striking distance. Above position
	 * eight there is little left to win by writing.
	 *
	 * @var float
	 */
	public const STRIKING_FROM = 8.0;

	/**
	 * The highest position that counts. Beyond twenty a paragraph is not what
	 * is missing.
	 *
	 * @var float
	 */
	public const STRIKING_TO = 20.0;

	/**
	 * The share of a query's impressions a second page must carry before it
	 * makes the query a cannibalisation finding.
	 *
	 * @var float
	 */
	public const DEFAULT_MIN_PAGE_SHARE = 0.20;

	/**
	 * How far below the better page's rate the combined rate must fall before
	 * the loss counts as material.
	 *
	 * @var float
	 */
	public const DEFAULT_MATERIALITY = 0.10;

	/**
	 * Tokens shorter than this never decide whether a page carries a query.
	 *
	 * @var int
	 */
	public const MIN_TOKEN_LENGTH = 3;

	/**
	 * Dutch and English words that carry no topic. Deliberately short: a long
	 * stop list starts removing terms people actually search for.
	 *
	 * @var array<int, string>
	 */
	public const STOP_WORDS = [
		'aan', 'als', 'bij', 'dat', 'deze', 'die', 'een', 'het', 'hoe', 'ik',
		'kan', 'met', 'niet', 'om', 'ook', 'van', 'voor', 'wat', 'zijn',
		'and', 'are', 'can', 'for', 'how', 'the', 'was', 'what', 'which',
		'with', 'you', 'your',
	];

	/**
	 * Group the queries of a window into position buckets.
	 *
	 * @param array<int, array<string, mixed>> $rows `searchQueryDaily` rows.
	 *
	 * @return array<int, array{bucket: string, queries: int, clicks: int, impressions: int}> One entry per bucket, in order.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets
	 */
	public function positionBuckets(array $rows): array {
		$labels = array_merge(array_keys(self::BUCKETS), [self::TAIL_BUCKET]);
		$out = [];
		foreach ($labels as $label) {
			$out[$label] = ['bucket' => $label, 'queries' => 0, 'clicks' => 0, 'impressions' => 0];
		}

		foreach ($this->byQuery(rows: $rows) as $entry) {
			if ($entry['impressions'] <= 0) {
				continue;
			}

			$label = $this->bucketOf(position: $entry['position']);
			$out[$label]['queries']++;
			$out[$label]['clicks'] += $entry['clicks'];
			$out[$label]['impressions'] += $entry['impressions'];
		}

		return array_values($out);
	}//end positionBuckets()

	/**
	 * The bucket a position falls in. A boundary belongs to the lower bucket.
	 *
	 * @param float $position The impressions-weighted mean position.
	 *
	 * @return string One of {@see BUCKETS} or {@see TAIL_BUCKET}.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets
	 */
	public function bucketOf(float $position): string {
		foreach (self::BUCKETS as $label => $bound) {
			if ($position <= $bound) {
				return $label;
			}
		}

		return self::TAIL_BUCKET;
	}//end bucketOf()

	/**
	 * Queries that sit one push away from page one.
	 *
	 * @param array<int, array<string, mixed>> $rows `searchQueryDaily` rows.
	 * @param int $minImpressions Impressions a query needs to qualify.
	 *
	 * @return array<int, array<string, mixed>> Findings, most impressions first.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-striking-distance-queries-are-queries-one-push-from-page-one
	 */
	public function strikingDistance(array $rows, int $minImpressions = self::DEFAULT_MIN_IMPRESSIONS): array {
		$out = [];
		foreach ($this->byQuery(rows: $rows) as $entry) {
			if ($entry['impressions'] < $minImpressions || $entry['impressions'] <= 0) {
				continue;
			}

			$position = $entry['position'];
			if ($position < self::STRIKING_FROM || $position > self::STRIKING_TO) {
				continue;
			}

			$ctr = ($entry['clicks'] / $entry['impressions']);
			$expected = ExpectedCtrCurve::rateAt(position: $position);
			if ($ctr >= $expected) {
				continue;
			}

			$out[] = [
				'query' => $entry['query'],
				'clicks' => $entry['clicks'],
				'impressions' => $entry['impressions'],
				'ctr' => round($ctr, 4),
				'position' => round($position, 1),
				'expectedCtr' => round($expected, 4),
				'shortfall' => round(($expected - $ctr), 4),
				'topPage' => $entry['topPage'],
			];
		}

		usort(
			$out,
			static function (array $a, array $b): int {
				return [$b['impressions'], $b['shortfall'], $a['query']] <=> [$a['impressions'], $a['shortfall'], $b['query']];
			}
		);

		return $out;
	}//end strikingDistance()

	/**
	 * Queries where two of our pages are competing and costing each other
	 * clicks.
	 *
	 * The naive predicate, "the combined rate is below the better page's
	 * rate", is true for nearly every query with two pages: a combined rate
	 * is a weighted average and an average sits below its maximum whenever
	 * the terms differ. Two guards make the finding mean something. A page
	 * must carry a real share of the impressions, so one stray page cannot
	 * create a finding, and the loss must be material.
	 *
	 * @param array<int, array<string, mixed>> $rows `searchQueryDaily` rows.
	 * @param int $minImpressions Impressions the query needs to qualify.
	 * @param float $minPageShare The share a second page must carry.
	 * @param float $materiality How far below the better rate the combined rate must fall.
	 *
	 * @return array<int, array<string, mixed>> Findings, most impressions first.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-cannibalisation-names-two-pages-competing-for-one-query
	 */
	public function cannibalisation(
		array $rows,
		int $minImpressions = self::DEFAULT_MIN_IMPRESSIONS,
		float $minPageShare = self::DEFAULT_MIN_PAGE_SHARE,
		float $materiality = self::DEFAULT_MATERIALITY,
	): array {
		$out = [];
		foreach ($this->byQueryAndPage(rows: $rows) as $query => $pages) {
			$totals = $this->pageTotals(pages: $pages);
			if ($totals['impressions'] < $minImpressions || $totals['impressions'] <= 0) {
				continue;
			}

			$contenders = $this->contenders(pages: $pages, queryImpressions: $totals['impressions'], minPageShare: $minPageShare);
			if (count($contenders) < 2) {
				continue;
			}

			$combined = ($totals['clicks'] / $totals['impressions']);
			$best = $this->bestPage(contenders: $contenders);
			if ($combined > ($best['ctr'] * (1.0 - $materiality))) {
				continue;
			}

			$out[] = [
				'query' => $query,
				'clicks' => $totals['clicks'],
				'impressions' => $totals['impressions'],
				'combinedCtr' => round($combined, 4),
				'bestPage' => $best['page'],
				'bestPageCtr' => round($best['ctr'], 4),
				'pages' => array_values($contenders),
			];
		}

		usort(
			$out,
			static function (array $a, array $b): int {
				return [$b['impressions'], $a['query']] <=> [$a['impressions'], $b['query']];
			}
		);

		return $out;
	}//end cannibalisation()

	/**
	 * Queries with demand that no page of ours answers.
	 *
	 * A page carries a query when EVERY significant token of the query
	 * appears in that page's title-and-headings text. Requiring every token
	 * rather than any is deliberate: "woo verzoek indienen" is not answered
	 * by a page whose title merely contains "verzoek".
	 *
	 * @param array<int, array<string, mixed>> $rows `searchQueryDaily` rows.
	 * @param array<int, array<string, mixed>> $pages Crawled pages, each with `url` and `text`.
	 * @param int $minImpressions Impressions the query needs to qualify.
	 *
	 * @return array<int, array<string, mixed>> Gaps, most impressions first.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-content-gap-is-a-query-no-page-of-ours-answers
	 */
	public function contentGaps(array $rows, array $pages, int $minImpressions = self::DEFAULT_MIN_IMPRESSIONS): array {
		if ($pages === []) {
			return [];
		}

		$haystacks = [];
		foreach ($pages as $page) {
			$haystacks[(string)($page['url'] ?? '')] = $this->normalise(value: (string)($page['text'] ?? ''));
		}

		$out = [];
		foreach ($this->byQuery(rows: $rows) as $entry) {
			if ($entry['impressions'] < $minImpressions) {
				continue;
			}

			$tokens = $this->significantTokens(value: $entry['query']);
			if ($tokens === [] || $this->anyPageCarries(tokens: $tokens, haystacks: $haystacks) === true) {
				continue;
			}

			$out[] = [
				'query' => $entry['query'],
				'clicks' => $entry['clicks'],
				'impressions' => $entry['impressions'],
				'position' => round($entry['position'], 1),
				'terms' => $tokens,
			];
		}

		usort(
			$out,
			static function (array $a, array $b): int {
				return [$b['impressions'], $a['query']] <=> [$a['impressions'], $b['query']];
			}
		);

		return $out;
	}//end contentGaps()

	/**
	 * The significant tokens of a phrase: normalised, short ones and stop
	 * words dropped.
	 *
	 * @param string $value The phrase.
	 *
	 * @return array<int, string> The tokens, deduplicated, in order.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-content-gap-is-a-query-no-page-of-ours-answers
	 */
	public function significantTokens(string $value): array {
		$parts = preg_split('/[^a-z0-9]+/', $this->normalise(value: $value));
		if (is_array($parts) === false) {
			return [];
		}

		$out = [];
		foreach ($parts as $part) {
			if (strlen($part) < self::MIN_TOKEN_LENGTH || in_array($part, self::STOP_WORDS, true) === true) {
				continue;
			}

			$out[$part] = true;
		}

		return array_keys($out);
	}//end significantTokens()

	/**
	 * Lowercase, strip diacritics, collapse whitespace.
	 *
	 * @param string $value The text.
	 *
	 * @return string The normalised text.
	 */
	private function normalise(string $value): string {
		$lower = mb_strtolower($value, 'UTF-8');
		$folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
		if (is_string($folded) === false) {
			$folded = $lower;
		}

		$collapsed = preg_replace('/\s+/', ' ', $folded);
		if (is_string($collapsed) === false) {
			$collapsed = $folded;
		}

		return trim($collapsed);
	}//end normalise()

	/**
	 * Whether any crawled page carries every token.
	 *
	 * @param array<int, string> $tokens The query's significant tokens.
	 * @param array<string, string> $haystacks Normalised page text by URL.
	 *
	 * @return bool
	 */
	private function anyPageCarries(array $tokens, array $haystacks): bool {
		foreach ($haystacks as $text) {
			$carries = true;
			foreach ($tokens as $token) {
				if (str_contains($text, $token) === false) {
					$carries = false;
					break;
				}
			}

			if ($carries === true) {
				return true;
			}
		}

		return false;
	}//end anyPageCarries()

	/**
	 * Aggregate rows per query: clicks and impressions summed, position as
	 * the impressions-weighted mean, and the page with the most impressions.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, array{query: string, clicks: int, impressions: int, position: float, topPage: string}>
	 */
	private function byQuery(array $rows): array {
		$acc = [];
		foreach ($rows as $row) {
			$query = trim((string)($row['query'] ?? ''));
			if ($query === '') {
				continue;
			}

			$impressions = max(0, (int)($row['impressions'] ?? 0));
			$entry = ($acc[$query] ?? ['query' => $query, 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'pages' => []]);
			$entry['clicks'] += max(0, (int)($row['clicks'] ?? 0));
			$entry['impressions'] += $impressions;
			$entry['weighted'] += ((float)($row['position'] ?? 0.0) * $impressions);
			$page = (string)($row['page'] ?? '');
			if ($page !== '') {
				$entry['pages'][$page] = (($entry['pages'][$page] ?? 0) + $impressions);
			}

			$acc[$query] = $entry;
		}

		$out = [];
		foreach ($acc as $entry) {
			$position = 0.0;
			if ($entry['impressions'] > 0) {
				$position = ($entry['weighted'] / $entry['impressions']);
			}

			arsort($entry['pages']);
			$out[] = [
				'query' => $entry['query'],
				'clicks' => (int)$entry['clicks'],
				'impressions' => (int)$entry['impressions'],
				'position' => $position,
				'topPage' => (string)(array_key_first($entry['pages']) ?? ''),
			];
		}

		return $out;
	}//end byQuery()

	/**
	 * Aggregate rows per query and page.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, array{page: string, clicks: int, impressions: int, weighted: float}>>
	 */
	private function byQueryAndPage(array $rows): array {
		$acc = [];
		foreach ($rows as $row) {
			$query = trim((string)($row['query'] ?? ''));
			$page = trim((string)($row['page'] ?? ''));
			if ($query === '' || $page === '') {
				continue;
			}

			$impressions = max(0, (int)($row['impressions'] ?? 0));
			$entry = ($acc[$query][$page] ?? ['page' => $page, 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0]);
			$entry['clicks'] += max(0, (int)($row['clicks'] ?? 0));
			$entry['impressions'] += $impressions;
			$entry['weighted'] += ((float)($row['position'] ?? 0.0) * $impressions);
			$acc[$query][$page] = $entry;
		}

		return $acc;
	}//end byQueryAndPage()

	/**
	 * Clicks and impressions summed over a query's pages.
	 *
	 * @param array<string, array<string, mixed>> $pages The per-page entries.
	 *
	 * @return array{clicks: int, impressions: int}
	 */
	private function pageTotals(array $pages): array {
		$clicks = 0;
		$impressions = 0;
		foreach ($pages as $entry) {
			$clicks += (int)$entry['clicks'];
			$impressions += (int)$entry['impressions'];
		}

		return ['clicks' => $clicks, 'impressions' => $impressions];
	}//end pageTotals()

	/**
	 * The pages carrying at least the minimum share of the query.
	 *
	 * @param array<string, array<string, mixed>> $pages The per-page entries.
	 * @param int $queryImpressions The query's total impressions.
	 * @param float $minPageShare The minimum share.
	 *
	 * @return array<string, array<string, mixed>> The contenders, with their rates.
	 */
	private function contenders(array $pages, int $queryImpressions, float $minPageShare): array {
		$out = [];
		foreach ($pages as $page => $entry) {
			$impressions = (int)$entry['impressions'];
			if ($impressions <= 0 || ($impressions / $queryImpressions) < $minPageShare) {
				continue;
			}

			$out[$page] = [
				'page' => (string)$entry['page'],
				'clicks' => (int)$entry['clicks'],
				'impressions' => $impressions,
				'ctr' => round(((int)$entry['clicks'] / $impressions), 4),
				'position' => round(((float)$entry['weighted'] / $impressions), 1),
			];
		}

		return $out;
	}//end contenders()

	/**
	 * The contender with the best click-through rate.
	 *
	 * @param array<string, array<string, mixed>> $contenders The contenders.
	 *
	 * @return array{page: string, ctr: float}
	 */
	private function bestPage(array $contenders): array {
		$best = ['page' => '', 'ctr' => 0.0];
		foreach ($contenders as $entry) {
			$ctr = (float)$entry['ctr'];
			if ($ctr > $best['ctr']) {
				$best = ['page' => (string)$entry['page'], 'ctr' => $ctr];
			}
		}

		return $best;
	}//end bestPage()
}//end class
