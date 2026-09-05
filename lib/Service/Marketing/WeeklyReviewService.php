<?php

/**
 * Pipelinq WeeklyReviewService.
 *
 * One page for Monday morning: what moved last week, what to try, and three
 * topic ideas. Composed in one read so the card paints from a single
 * response (pipelinq#1781, ADR-112).
 *
 * 🔴 IT TAKES NO ACTION, AND THAT IS THE DESIGN. There is no send path and no
 * publish path anywhere in this class, so the agent that reads it has none
 * either. An agent drafts and analyses; a person decides (ADR-088).
 *
 * 🔴 A SOURCE THAT IS ABSENT IS NAMED, NEVER COUNTED AS ZERO. Phase 5's watch
 * events are not on this branch, so the review reports `watchEvent` under
 * `degraded` and draws its topic ideas from search queries instead. Reporting
 * "0 competitor moves" for a collection that does not exist is the kind of
 * number that gets believed.
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

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * WeeklyReviewService: the numbers, in one read.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One read model over four
 *  collections, assembled once so the page fetches once.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
 */
class WeeklyReviewService {

	/**
	 * The review schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'weeklyReview';

	/**
	 * The identity an agent-written narrative would be marked with.
	 *
	 * 🔴 NOTHING WRITES IT YET, AND SAYING SO IS THE POINT. The mark's storage
	 * is on the schema and the page renders it, but pipelinq ships no MCP tool
	 * provider (ADR-034 Decision 3), so the seeded agent template grants
	 * read-only tools and no agent can reach a pipelinq write path. A method
	 * that stamped this mark and had no caller would be a capability that
	 * looks present and can never run, which is worse than an absent one. The
	 * constant is here so the writer, when it exists, does not invent a second
	 * spelling of the same identity.
	 *
	 * @var string
	 */
	public const AGENT_IDENTITY = 'hermiq:marketing-weekly-review';

	/**
	 * The sources this review reads, in the order it reads them.
	 *
	 * @var array<int, string>
	 */
	public const SOURCES = ['blast', 'touchpoint', 'socialPublication', 'searchQueryDaily'];

	/**
	 * The source phase 5 was to bring and has not.
	 *
	 * @var string
	 */
	public const DEFERRED_SOURCE = 'watchEvent';

	/**
	 * How many topic ideas a review carries.
	 *
	 * @var int
	 */
	private const IDEAS = 3;

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object plumbing.
	 * @param WeeklyReviewNumbers $numbers One week of counting, per collection.
	 * @param ITimeFactory $time Clock.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
	 */
	public function __construct(
		private ListObjectStore $store,
		private WeeklyReviewNumbers $numbers,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Compose the review for the week containing a given day.
	 *
	 * @param string $weekStarting The Monday, `YYYY-MM-DD`. Empty means last week.
	 *
	 * @return array<string, mixed> The review, not yet stored.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
	 */
	public function compose(string $weekStarting = ''): array {
		$monday = $this->mondayOf(day: $weekStarting);
		$sunday = date('Y-m-d', (strtotime($monday) + (6 * 86400)));

		$mail = $this->numbers->mail(from: $monday, to: $sunday);
		$social = $this->numbers->social(from: $monday, to: $sunday);
		$search = $this->numbers->search(from: $monday, to: $sunday);

		$highlights = array_merge($mail['highlights'], $social['highlights'], $search['highlights']);

		return [
			'weekStarting' => $monday,
			'sources' => self::SOURCES,
			'degraded' => $this->degradedSources(),
			'summary' => $this->summaryOf(monday: $monday, sunday: $sunday, highlights: $highlights),
			'highlights' => $highlights,
			'suggestions' => $this->suggestionsFor(mail: $mail, social: $social, search: $search),
			'topicIdeas' => array_slice($search['ideas'], 0, self::IDEAS),
			'generatedAt' => date('c', $this->time->getTime()),
		];
	}//end compose()

	/**
	 * Compose the review and store it, one object per week.
	 *
	 * @param string $weekStarting The Monday, `YYYY-MM-DD`. Empty means last week.
	 *
	 * @return array<string, mixed>|null The stored review, or null when the write failed.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
	 */
	public function generate(string $weekStarting = ''): ?array {
		$review = $this->compose(weekStarting: $weekStarting);
		$existing = $this->findByWeek(weekStarting: (string)$review['weekStarting']);
		$id = null;
		if ($existing !== null) {
			$id = $this->store->idOf($existing);
		}

		return $this->store->save(schemaSlug: $this->schema(), payload: $review, id: $id);
	}//end generate()

	/**
	 * The most recent stored review.
	 *
	 * @return array<string, mixed>|null The review, or null when none exists.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
	 */
	public function latest(): ?array {
		$latest = null;
		foreach ($this->store->findAll(schemaSlug: $this->schema()) as $review) {
			$week = (string)($review['weekStarting'] ?? '');
			if ($week === '') {
				continue;
			}

			if ($latest === null || $week > (string)($latest['weekStarting'] ?? '')) {
				$latest = $review;
			}
		}

		return $latest;
	}//end latest()

	/**
	 * What to try next week.
	 *
	 * @param array<string, mixed> $mail Mail numbers.
	 * @param array<string, mixed> $social Social numbers.
	 * @param array<string, mixed> $search Search numbers.
	 *
	 * @return array<int, string> The suggestions.
	 */
	private function suggestionsFor(array $mail, array $social, array $search): array {
		$suggestions = [];
		if ($mail['sent'] === 0) {
			$suggestions[] = 'No mailing went out. Send the one that is waiting in draft.';
		}

		if ($mail['sent'] > 0 && $mail['clicks'] === 0) {
			$suggestions[] = 'Mailings went out and nobody clicked. Try a different subject line.';
		}

		if ($social['published'] === 0) {
			$suggestions[] = 'Nothing was published on social. Turn last week\'s article into a post.';
		}

		if ($search['impressions'] > 0 && $search['clicks'] === 0) {
			$suggestions[] = 'Search shows the site but nobody clicks. Rewrite the page titles.';
		}

		return $suggestions;
	}//end suggestionsFor()

	/**
	 * The one-line factual summary a person or an agent may replace.
	 *
	 * @param string $monday Week start.
	 * @param string $sunday Week end.
	 * @param array<int, string> $highlights The highlights.
	 *
	 * @return string The summary.
	 */
	private function summaryOf(string $monday, string $sunday, array $highlights): string {
		if ($highlights === []) {
			return sprintf('Nothing was recorded between %s and %s.', $monday, $sunday);
		}

		return sprintf('Between %s and %s: %s', $monday, $sunday, implode(' ', $highlights));
	}//end summaryOf()

	/**
	 * The sources this instance cannot read.
	 *
	 * @return array<int, string> The absent source slugs.
	 */
	private function degradedSources(): array {
		$schema = $this->store->schemaSlug('watchEvent_schema', self::DEFERRED_SOURCE);
		if ($this->numbers->readable(schemaSlug: $schema) === true) {
			return [];
		}

		return [self::DEFERRED_SOURCE];
	}//end degradedSources()

	/**
	 * One stored review by its week.
	 *
	 * @param string $weekStarting The Monday.
	 *
	 * @return array<string, mixed>|null The review.
	 */
	private function findByWeek(string $weekStarting): ?array {
		foreach ($this->store->findAll(schemaSlug: $this->schema(), filters: ['weekStarting' => $weekStarting]) as $review) {
			if ((string)($review['weekStarting'] ?? '') === $weekStarting) {
				return $review;
			}
		}

		return null;
	}//end findByWeek()

	/**
	 * The Monday of the week a day falls in, or of last week when unset.
	 *
	 * @param string $day A `YYYY-MM-DD` day, or empty.
	 *
	 * @return string The Monday, `YYYY-MM-DD`.
	 */
	private function mondayOf(string $day): string {
		$day = trim($day);
		$stamp = strtotime($day);
		if ($day === '') {
			$stamp = strtotime('-7 days', $this->time->getTime());
		}
		if ($stamp === false) {
			$stamp = $this->time->getTime();
		}

		return date('Y-m-d', strtotime('monday this week', $stamp));
	}//end mondayOf()

	/**
	 * The review schema slug.
	 *
	 * @return string The slug.
	 */
	private function schema(): string {
		return $this->store->schemaSlug('weeklyReview_schema', self::SCHEMA);
	}//end schema()
}//end class
