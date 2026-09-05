<?php

/**
 * Pipelinq WeeklyReviewNumbers.
 *
 * What the mailings, the social posts and search did in one week. It reads
 * and counts; it decides nothing about how any of it is phrased.
 *
 * 🔴 A SOURCE THAT IS ABSENT IS NAMED, NEVER COUNTED AS ZERO. Nothing here
 * invents a number for a collection it could not read. Reporting "0
 * competitor moves" for a collection that does not exist is the kind of
 * number a reader believes.
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
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * WeeklyReviewNumbers: one week of counting, in one read per collection.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
 */
class WeeklyReviewNumbers {

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object plumbing.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
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
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
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
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
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
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
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
	 * Whether one collection has anything at all to read on this instance.
	 *
	 * @param string $schemaSlug The schema to probe.
	 *
	 * @return bool True when it holds at least one row.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
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
