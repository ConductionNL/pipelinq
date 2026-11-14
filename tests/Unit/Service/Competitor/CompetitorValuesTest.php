<?php

/**
 * Pins the small competitor-watch value objects.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Competitor
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Competitor;

use OCA\Pipelinq\Service\Competitor\MastodonAccount;
use OCA\Pipelinq\Service\Competitor\SitemapHop;
use OCA\Pipelinq\Service\Competitor\WatchOutcome;
use PHPUnit\Framework\TestCase;

/**
 * WatchOutcome factories, the Mastodon instance base and the sitemap hop depth.
 *
 * @covers \OCA\Pipelinq\Service\Competitor\WatchOutcome
 * @covers \OCA\Pipelinq\Service\Competitor\MastodonAccount
 * @covers \OCA\Pipelinq\Service\Competitor\SitemapHop
 */
class CompetitorValuesTest extends TestCase {

	/**
	 * A seen outcome is ok, carries items and state, and has no reason.
	 *
	 * @return void
	 */
	public function testSeenOutcome(): void {
		$outcome = WatchOutcome::seen(items: [['url' => 'https://a.example']], state: ['etag' => 'x']);

		$this->assertSame('ok', $outcome->outcome);
		$this->assertSame('', $outcome->reason);
		$this->assertSame([['url' => 'https://a.example']], $outcome->items);
		$this->assertSame(['etag' => 'x'], $outcome->state);
		$this->assertTrue($outcome->succeeded());
		$this->assertSame([], WatchOutcome::seen(items: [])->state);
	}//end testSeenOutcome()

	/**
	 * A failed outcome keeps its outcome and reason, with no items or state.
	 *
	 * @return void
	 */
	public function testFailedOutcome(): void {
		$outcome = WatchOutcome::failed(outcome: 'refused', reason: '403');

		$this->assertSame('refused', $outcome->outcome);
		$this->assertSame('403', $outcome->reason);
		$this->assertSame([], $outcome->items);
		$this->assertSame([], $outcome->state);
		$this->assertFalse($outcome->succeeded());
	}//end testFailedOutcome()

	/**
	 * The instance base is everything after the first @ of the handle.
	 *
	 * @return void
	 */
	public function testMastodonBase(): void {
		$account = new MastodonAccount(handle: 'gem@social.example@odd', id: '42');

		$this->assertSame('https://social.example@odd', $account->base());
		$this->assertSame('42', $account->id);
		$this->assertSame('gem@social.example@odd', $account->handle);
	}//end testMastodonBase()

	/**
	 * Going deeper adds one level, keeps the source and leaves the original alone.
	 *
	 * @return void
	 */
	public function testSitemapHopDeeper(): void {
		$hop    = new SitemapHop(sourceId: 'src-1', depth: 2);
		$deeper = $hop->deeper();

		$this->assertNotSame($hop, $deeper);
		$this->assertSame(3, $deeper->depth);
		$this->assertSame('src-1', $deeper->sourceId);
		$this->assertSame(2, $hop->depth);
	}//end testSitemapHopDeeper()
}//end class
