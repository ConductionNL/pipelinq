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
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\WeeklyReviewNumbers;
use OCA\Pipelinq\Service\Marketing\WeeklyReviewService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for WeeklyReviewService: the window, the absent source, and the
 * ADR-088 mark on a narrative.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
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
	 * 🔴 A source that cannot be read is NAMED, never counted as zero.
	 * "0 competitor moves" for a collection that does not exist is the kind
	 * of number a reader believes.
	 *
	 * @return void
	 */
	public function testAnAbsentSourceIsNamedNotCountedAsZero(): void {
		$review = $this->service()->compose();

		$this->assertSame([WeeklyReviewService::DEFERRED_SOURCE], $review['degraded']);
		foreach (array_merge($review['highlights'], $review['suggestions']) as $line) {
			$this->assertStringNotContainsString('competitor', strtolower($line));
		}
	}//end testAnAbsentSourceIsNamedNotCountedAsZero()

	/**
	 * The topic ideas are the three most-shown queries of the week, and
	 * last month's row does not qualify however well it did.
	 *
	 * @return void
	 */
	public function testTopicIdeasAreTheWeeksThreeMostShownQueries(): void {
		$review = $this->service()->compose();

		$this->assertSame(
			['open source gemeente', 'woo verzoek', 'crm nextcloud'],
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
	 * An agent narrative carries its author, and the page can say so.
	 *
	 * @return void
	 */
	public function testAnAgentNarrativeIsMarkedWithItsAuthor(): void {
		$service = $this->service();
		$service->generate();

		$review = $service->recordNarrative(
			weekStarting: self::LAST_MONDAY,
			summary: 'The newsletter did the work last week.'
		);

		$this->assertTrue($review['agentAuthored']);
		$this->assertSame(WeeklyReviewService::AGENT_IDENTITY, $review['agentAuthoredBy']);
		$this->assertSame('The newsletter did the work last week.', $review['summary']);
	}//end testAnAgentNarrativeIsMarkedWithItsAuthor()

	/**
	 * A review composed by pipelinq alone carries no mark at all: a mark on
	 * text no agent wrote is as wrong as a missing one.
	 *
	 * @return void
	 */
	public function testTheMarkIsNotTakenFromTheCaller(): void {
		$review = $this->service()->generate();

		$this->assertArrayNotHasKey('agentAuthored', $review);

		$marked = $this->service()->recordNarrative(
			weekStarting: self::LAST_MONDAY,
			summary: 'Written by a person.',
			agent: ''
		);
		$this->assertFalse($marked['agentAuthored']);
		$this->assertSame('', $marked['agentAuthoredBy']);
	}//end testTheMarkIsNotTakenFromTheCaller()

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
			$this->createMock(LoggerInterface::class),
		);
	}//end service()
}//end class
