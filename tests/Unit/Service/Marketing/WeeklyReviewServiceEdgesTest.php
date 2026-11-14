<?php

/**
 * Edge cases of WeeklyReviewService: mail without clicks, an unreadable
 * week and stored reviews without a week.
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
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\WeeklyReviewNumbers;
use OCA\Pipelinq\Service\Marketing\WeeklyReviewService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the WeeklyReviewService branches the main test does not reach.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\WeeklyReviewService
 * @uses   \OCA\Pipelinq\Service\Marketing\WeeklyReviewNumbers
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */
class WeeklyReviewServiceEdgesTest extends TestCase {

	/**
	 * A Saturday; the Monday of its week is 2026-08-31.
	 */
	private const NOW = '2026-09-05 12:00:00';

	/**
	 * The store.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * A mailing that went out last week with no click at all asks for a new
	 * subject line, and does not claim that nothing was sent.
	 *
	 * @return void
	 */
	public function testAMailingWithoutClicksAsksForANewSubjectLine(): void {
		$this->store = new InMemoryListObjectStore([
			'blast' => [['uuid' => 'b-1', 'name' => 'Nieuwsbrief', 'sentAt' => '2026-08-25T09:00:00+02:00']],
			'touchpoint' => [['uuid' => 't-1', 'kind' => 'visit', 'occurredAt' => '2026-08-26T10:00:00+02:00']],
			'socialPublication' => [['uuid' => 's-1', 'publishedAt' => '2026-08-25T12:00:00+02:00', 'metrics' => ['views' => 3]]],
		]);

		$suggestions = $this->service()->compose()['suggestions'];

		$this->assertSame(['Mailings went out and nobody clicked. Try a different subject line.'], $suggestions);
	}//end testAMailingWithoutClicksAsksForANewSubjectLine()

	/**
	 * A week that cannot be read falls back to the week of today, not to last week.
	 *
	 * @return void
	 */
	public function testAnUnreadableWeekFallsBackToThisWeek(): void {
		$this->store = new InMemoryListObjectStore();
		$service = $this->service();

		$this->assertSame('2026-08-31', $service->compose(weekStarting: 'the week after next-ish')['weekStarting']);
		$this->assertSame('2026-08-24', $service->compose(weekStarting: '   ')['weekStarting']);
		$this->assertSame('2026-08-10', $service->compose(weekStarting: '2026-08-13')['weekStarting']);
	}//end testAnUnreadableWeekFallsBackToThisWeek()

	/**
	 * A stored review without a week is never the latest, and no review is null.
	 *
	 * @return void
	 */
	public function testAReviewWithoutAWeekIsNeverTheLatest(): void {
		$this->store = new InMemoryListObjectStore();
		$this->assertNull($this->service()->latest());

		$this->store = new InMemoryListObjectStore([
			'weeklyReview' => [
				['uuid' => 'r-1', 'weekStarting' => ''],
				['uuid' => 'r-2', 'weekStarting' => '2026-08-17'],
				['uuid' => 'r-3'],
			],
		]);

		$this->assertSame('r-2', $this->service()->latest()['uuid']);
	}//end testAReviewWithoutAWeekIsNeverTheLatest()

	/**
	 * The service over the current store at a fixed time.
	 *
	 * @return WeeklyReviewService
	 */
	private function service(): WeeklyReviewService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime(self::NOW));

		return new WeeklyReviewService($this->store, new WeeklyReviewNumbers($this->store), $time);
	}//end service()
}//end class
