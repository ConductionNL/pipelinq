<?php

/**
 * Pipelinq WeeklyReviewNumbers.
 *
 * What the mailings, the social posts, search and the competitor watches did
 * in one week. It reads and counts; it decides nothing about how any of it is
 * phrased.
 *
 * 🔴 AN EMPTY SOURCE IS NAMED, NEVER PASSED OFF AS A ZERO. `readable()` is
 * what separates "nobody published anything last week" from "this tenant has
 * never connected Search Console". Both render as no line in the review, and
 * only one of them is a result. "0 competitor moves" on an instance with no
 * watches configured is a number a reader believes.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * WeeklyReviewNumbers: one week of counting, in one read per collection.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */
class WeeklyReviewNumbers {

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object plumbing.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	public function __construct(
		private ListObjectStore $store,
	) {
	}//end __construct()

	/**
	 * What the mailings did.
	 *
	 * @param string $from Week start, inclusive.
	 * @param string $to Week end, inclusive.
	 *
	 * @return array<string, mixed> `sent`, `clicks` and one highlight line.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	public function mail(string $from, string $to): array {
		$sent = 0;
		foreach ($this->store->findAll(schemaSlug: $this->store->schemaSlug('blast_schema', 'blast')) as $blast) {
			if ($this->inWeek(value: ($blast['sentAt'] ?? ''), from: $from, to: $to) === true) {
				$sent++;
			}
		}

		$clicks = 0;
		foreach ($this->store->findAll(schemaSlug: $this->store->schemaSlug('touchpoint_schema', 'touchpoint')) as $touchpoint) {
			$isClick = ((string)($touchpoint['kind'] ?? '') === 'click');
			if ($isClick === true && $this->inWeek(value: ($touchpoint['occurredAt'] ?? ''), from: $from, to: $to) === true) {
				$clicks++;
			}
		}

		$highlights = [];
		if ($sent > 0 || $clicks > 0) {
			$highlights[] = sprintf('%d mailings went out and drew %d clicks.', $sent, $clicks);
		}

		return ['sent' => $sent, 'clicks' => $clicks, 'highlights' => $highlights];
	}//end mail()

	/**
	 * What the social posts did.
	 *
	 * @param string $from Week start, inclusive.
	 * @param string $to Week end, inclusive.
	 *
	 * @return array<string, mixed> `published`, `views` and one highlight line.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	public function social(string $from, string $to): array {
		$published = 0;
		$views = 0;
		$schema = $this->store->schemaSlug('socialPublication_schema', 'socialPublication');
		foreach ($this->store->findAll(schemaSlug: $schema) as $publication) {
			if ($this->inWeek(value: ($publication['publishedAt'] ?? ''), from: $from, to: $to) === false) {
				continue;
			}

			$published++;
			$metrics = (array)($publication['metrics'] ?? []);
			$views += (int)($metrics['views'] ?? 0);
		}

		$highlights = [];
		if ($published > 0) {
			$highlights[] = sprintf('%d posts were published and were seen %d times.', $published, $views);
		}

		return ['published' => $published, 'views' => $views, 'highlights' => $highlights];
	}//end social()

	/**
	 * What search did, and the queries worth writing about.
	 *
	 * @param string $from Week start, inclusive.
	 * @param string $to Week end, inclusive.
	 *
	 * @return array<string, mixed> `clicks`, `impressions`, one highlight line
	 *         and the week's queries, most-shown first.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	public function search(string $from, string $to): array {
		$clicks = 0;
		$impressions = 0;
		$byQuery = [];
		$schema = $this->store->schemaSlug('searchQueryDaily_schema', 'searchQueryDaily');
		foreach ($this->store->findAll(schemaSlug: $schema) as $row) {
			if ($this->inWeek(value: ($row['date'] ?? ''), from: $from, to: $to) === false) {
				continue;
			}

			$clicks += (int)($row['clicks'] ?? 0);
			$impressions += (int)($row['impressions'] ?? 0);

			$query = trim((string)($row['query'] ?? ''));
			if ($query !== '') {
				$byQuery[$query] = (($byQuery[$query] ?? 0) + (int)($row['impressions'] ?? 0));
			}
		}

		arsort($byQuery);

		$highlights = [];
		if ($impressions > 0) {
			$highlights[] = sprintf('Search showed the site %d times and brought %d visits.', $impressions, $clicks);
		}

		return [
			'clicks' => $clicks,
			'impressions' => $impressions,
			'highlights' => $highlights,
			'ideas' => array_keys($byQuery),
		];
	}//end search()

	/**
	 * What the competitors did, and what they wrote about.
	 *
	 * Phase 5 landed `watchEvent` on development, so this is a real source
	 * rather than the absent one an earlier draft of this change reported.
	 *
	 * @param string $from Week start, inclusive.
	 * @param string $to Week end, inclusive.
	 *
	 * @return array<string, mixed> `moves`, one highlight line, and the
	 *         titles worth writing about, most relevant first.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	public function watch(string $from, string $to): array {
		$moves = 0;
		$byTitle = [];
		$schema = $this->store->schemaSlug('watchEvent_schema', 'watchEvent');
		foreach ($this->store->findAll(schemaSlug: $schema) as $event) {
			if ($this->inWeek(value: ($event['seenAt'] ?? ''), from: $from, to: $to) === false) {
				continue;
			}

			$moves++;
			$title = trim((string)($event['title'] ?? ''));
			if ($title !== '') {
				$byTitle[$title] = max(($byTitle[$title] ?? 0), (int)($event['relevanceScore'] ?? 0));
			}
		}

		arsort($byTitle);

		$highlights = [];
		if ($moves > 0) {
			$highlights[] = sprintf('Competitors published %d things worth looking at.', $moves);
		}

		return ['moves' => $moves, 'highlights' => $highlights, 'ideas' => array_keys($byTitle)];
	}//end watch()

	/**
	 * Whether one collection has anything at all to read on this instance.
	 *
	 * @param string $schemaSlug The schema to probe.
	 *
	 * @return bool True when it holds at least one row.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	public function readable(string $schemaSlug): bool {
		return ($this->store->findAll(schemaSlug: $schemaSlug) !== []);
	}//end readable()

	/**
	 * Whether a date-ish value falls inside the week.
	 *
	 * @param mixed $value A date or date-time string.
	 * @param string $from Week start, inclusive.
	 * @param string $to Week end, inclusive.
	 *
	 * @return bool True when it counts.
	 */
	private function inWeek(mixed $value, string $from, string $to): bool {
		if (is_string($value) === false || trim($value) === '') {
			return false;
		}

		$day = substr(trim($value), 0, 10);
		return ($day >= $from && $day <= $to);
	}//end inWeek()
}//end class
