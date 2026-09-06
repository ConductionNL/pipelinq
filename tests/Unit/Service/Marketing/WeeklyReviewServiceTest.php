<?php

/**
 * Unit tests for WeeklyReviewService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\WeeklyReviewNumbers;
use OCA\Pipelinq\Service\Marketing\WeeklyReviewService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WeeklyReviewService: the window, the absent source, and the
 * ADR-088 mark on a narrative.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */
class WeeklyReviewServiceTest extends TestCase {

	/**
	 * A Saturday, so "last week" is unambiguous.
	 *
	 * @var string
	 */
	private const NOW = '2026-09-05 12:00:00';

	/**
	 * The Monday of the week before {@see NOW}.
	 *
	 * @var string
	 */
	private const LAST_MONDAY = '2026-08-24';

	/**
	 * The store the review reads from.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Set up one week of activity in every source the review reads.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryListObjectStore([
			'blast' => [
				['uuid' => 'b-1', 'name' => 'Nieuwsbrief', 'sentAt' => '2026-08-25T09:00:00+02:00'],
				['uuid' => 'b-2', 'name' => 'Oud', 'sentAt' => '2026-07-01T09:00:00+02:00'],
			],
			'touchpoint' => [
				['uuid' => 't-1', 'kind' => 'click', 'occurredAt' => '2026-08-26T10:00:00+02:00'],
				['uuid' => 't-2', 'kind' => 'click', 'occurredAt' => '2026-08-27T10:00:00+02:00'],
				['uuid' => 't-3', 'kind' => 'visit', 'occurredAt' => '2026-08-27T10:00:00+02:00'],
			],
			'socialPublication' => [
				['uuid' => 's-1', 'publishedAt' => '2026-08-25T12:00:00+02:00', 'metrics' => ['views' => 120]],
			],
			'watchEvent' => [
				[
					'uuid' => 'w-1',
					'title' => 'Concurrent lanceert open source portaal',
					'seenAt' => '2026-08-26T08:00:00+02:00',
					'relevanceScore' => 80,
				],
				[
					'uuid' => 'w-2',
					'title' => 'Oud nieuws',
					'seenAt' => '2026-07-02T08:00:00+02:00',
					'relevanceScore' => 90,
				],
			],
			'searchQueryDaily' => [
				['uuid' => 'q-1', 'date' => '2026-08-25', 'query' => 'open source gemeente', 'clicks' => 4, 'impressions' => 300],
				['uuid' => 'q-2', 'date' => '2026-08-26', 'query' => 'woo verzoek', 'clicks' => 1, 'impressions' => 150],
				['uuid' => 'q-3', 'date' => '2026-08-26', 'query' => 'crm nextcloud', 'clicks' => 0, 'impressions' => 90],
				['uuid' => 'q-4', 'date' => '2026-08-26', 'query' => 'kassa', 'clicks' => 0, 'impressions' => 10],
				['uuid' => 'q-5', 'date' => '2026-07-01', 'query' => 'vorige maand', 'clicks' => 99, 'impressions' => 999],
			],
		]);
	}//end setUp()

	/**
	 * The review covers last week and counts only what happened in it.
	 *
	 * @return void
	 */
	public function testComposesFromOneReadOfEachCollection(): void {
		$review = $this->service()->compose();

		$this->assertSame(self::LAST_MONDAY, $review['weekStarting']);
		$this->assertSame(WeeklyReviewService::SOURCES, $review['sources']);
		$this->assertStringContainsString('1 mailings went out and drew 2 clicks.', implode(' ', $review['highlights']));
		$this->assertStringContainsString('seen 120 times', implode(' ', $review['highlights']));
		$this->assertStringContainsString('550 times and brought 5 visits', implode(' ', $review['highlights']));
	}//end testComposesFromOneReadOfEachCollection()

	/**
	 * 🔴 A source this tenant holds nothing for is NAMED, never passed off as
	 * a zero. A quiet week and a Search Console nobody connected both render
	 * as no line, and only one of them is a result.
	 *
	 * @return void
	 */
	public function testAnEmptySourceIsNamedNotCountedAsZero(): void {
		$review = $this->service()->compose();

		// The store holds watchEvent rows, so it is NOT reported empty.
		$this->assertNotContains('watchEvent', $review['degraded']);

		$this->store->rows['watchEvent'] = [];
		$this->store->rows['socialPublication'] = [];
		$degraded = $this->service()->compose()['degraded'];

		$this->assertContains('watchEvent', $degraded);
		$this->assertContains('socialPublication', $degraded);
		$this->assertNotContains('blast', $degraded);

		foreach (array_merge($review['highlights'], $review['suggestions']) as $line) {
			$this->assertStringNotContainsString('0 posts', $line);
		}
	}//end testAnEmptySourceIsNamedNotCountedAsZero()

	/**
	 * A competitor's headline is a better prompt than a search query, so it
	 * leads the topic ideas and the queries fill the rest.
	 *
	 * @return void
	 */
	public function testACompetitorHeadlineLeadsTheTopicIdeas(): void {
		$review = $this->service()->compose();

		$this->assertSame('Concurrent lanceert open source portaal', $review['topicIdeas'][0]);
		$this->assertSame('open source gemeente', $review['topicIdeas'][1]);
		$this->assertStringContainsString(
			'Competitors published 1 things worth looking at.',
			implode(' ', $review['highlights'])
		);
	}//end testACompetitorHeadlineLeadsTheTopicIdeas()

	/**
	 * The topic ideas are this week's three strongest prompts, and last
	 * month's rows do not qualify however well they did.
	 *
	 * @return void
	 */
	public function testTopicIdeasAreThisWeeksThreeStrongestPrompts(): void {
		$review = $this->service()->compose();

		$this->assertSame(
			['Concurrent lanceert open source portaal', 'open source gemeente', 'woo verzoek'],
			$review['topicIdeas']
		);
	}//end testTopicIdeasAreTheWeeksThreeMostShownQueries()

	/**
	 * Search that shows the site but brings nobody is worth a suggestion.
	 *
	 * @return void
	 */
	public function testSuggestsRewritingTitlesWhenSearchBringsNoClicks(): void {
		$this->store->rows['searchQueryDaily'] = [
			'q-6' => ['uuid' => 'q-6', 'date' => '2026-08-25', 'query' => 'iets', 'clicks' => 0, 'impressions' => 400],
		];

		$this->assertContains(
			'Search shows the site but nobody clicks. Rewrite the page titles.',
			$this->service()->compose()['suggestions']
		);
	}//end testSuggestsRewritingTitlesWhenSearchBringsNoClicks()

	/**
	 * Composing twice for one week updates the same object rather than
	 * leaving two reviews of the same week behind.
	 *
	 * @return void
	 */
	public function testGeneratingTwiceKeepsOneReviewPerWeek(): void {
		$service = $this->service();
		$service->generate();
		$service->generate();

		$this->assertCount(1, $this->store->findAll('weeklyReview'));
	}//end testGeneratingTwiceKeepsOneReviewPerWeek()

	/**
	 * A review pipelinq composed carries NO agent mark at all.
	 *
	 * 🔴 THE MARK'S ABSENCE IS THE ASSERTION. Nothing in pipelinq can write an
	 * agent narrative today: the app ships no MCP tool provider, so the seeded
	 * agent template grants read-only tools and no agent reaches a write path.
	 * A review that claimed an author would be claiming one that does not
	 * exist, and the page would tell a reader an agent wrote what pipelinq
	 * counted.
	 *
	 * @return void
	 */
	public function testAComposedReviewCarriesNoAgentMark(): void {
		$review = $this->service()->generate();

		$this->assertArrayNotHasKey('agentAuthored', $review);
		$this->assertArrayNotHasKey('agentAuthoredBy', $review);
	}//end testAComposedReviewCarriesNoAgentMark()

	/**
	 * The latest review is the one with the most recent week, not the row
	 * that happened to be written last.
	 *
	 * @return void
	 */
	public function testLatestIsTheMostRecentWeek(): void {
		$service = $this->service();
		$service->generate(weekStarting: '2026-07-06');
		$service->generate(weekStarting: '2026-08-24');
		$service->generate(weekStarting: '2026-08-03');

		$this->assertSame('2026-08-24', $service->latest()['weekStarting']);
	}//end testLatestIsTheMostRecentWeek()

	/**
	 * A service over the current store, with the clock pinned.
	 *
	 * @return WeeklyReviewService The service.
	 */
	private function service(): WeeklyReviewService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime(self::NOW));

		return new WeeklyReviewService(
			$this->store,
			new WeeklyReviewNumbers($this->store),
			$time,
		);
	}//end service()
}//end class
