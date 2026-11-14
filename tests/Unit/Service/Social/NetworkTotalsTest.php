<?php

/**
 * Unit tests for NetworkTotals.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Social
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\Pipelinq\Service\Social\NetworkTotals;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use PHPUnit\Framework\TestCase;

/**
 * NetworkTotals sums published posts per network since a moment.
 */
class NetworkTotalsTest extends TestCase {

	/**
	 * The start of the reporting window: 1 November 2026, 00:00 UTC.
	 *
	 * @var int
	 */
	private const SINCE = 1793491200;

	/**
	 * A published post on a network, at a moment, with metrics.
	 *
	 * @param string               $network     The network key.
	 * @param string               $publishedAt When it was published.
	 * @param array<string, mixed> $metrics     Its metrics.
	 *
	 * @return array<string, mixed>
	 */
	private static function post(string $network, string $publishedAt, array $metrics=[]): array {
		return [
			'network' => $network,
			'status' => SocialPublicationStore::PUBLISHED,
			'publishedAt' => $publishedAt,
			'metrics' => $metrics,
		];
	}//end post()

	/**
	 * No posts give no rows.
	 *
	 * @return void
	 */
	public function testNothingAddedGivesNoRows(): void {
		self::assertSame([], (new NetworkTotals(since: self::SINCE))->rows());
	}//end testNothingAddedGivesNoRows()

	/**
	 * One post makes one row with its label, counts and engagement share.
	 *
	 * @return void
	 */
	public function testOnePostMakesOneRowWithItsEngagement(): void {
		$totals = new NetworkTotals(since: self::SINCE);
		$totals->add(publication: self::post('linkedin', '2026-11-05T10:00:00Z', ['views' => 200, 'likes' => 10, 'comments' => 4, 'shares' => 6]));

		self::assertSame(
			[
				[
					'network' => 'linkedin',
					'label' => 'LinkedIn',
					'published' => 1,
					'views' => 200,
					'likes' => 10,
					'comments' => 4,
					'shares' => 6,
					'followers' => 0,
					'engagementPercent' => 10.0,
				],
			],
			$totals->rows()
		);
	}//end testOnePostMakesOneRowWithItsEngagement()

	/**
	 * Posts on the same network add up.
	 *
	 * @return void
	 */
	public function testPostsOnOneNetworkAddUp(): void {
		$totals = new NetworkTotals(since: self::SINCE);
		$totals->add(publication: self::post('mastodon', '2026-11-02T08:00:00Z', ['views' => 100, 'likes' => 1]));
		$totals->add(publication: self::post('mastodon', '2026-11-03T08:00:00Z', ['views' => 50, 'likes' => 2, 'shares' => 3]));

		$row = $totals->rows()[0];
		self::assertSame(2, $row['published']);
		self::assertSame(150, $row['views']);
		self::assertSame(3, $row['likes']);
		self::assertSame(3, $row['shares']);
		self::assertSame(4.0, $row['engagementPercent']);
	}//end testPostsOnOneNetworkAddUp()

	/**
	 * A draft, a post without a network and a post before the window are left out.
	 *
	 * @return void
	 */
	public function testOnlyPublishedPostsInsideTheWindowCount(): void {
		$totals = new NetworkTotals(since: self::SINCE);
		$draft = self::post('bluesky', '2026-11-05T10:00:00Z');
		$draft['status'] = 'draft';
		$totals->add(publication: $draft);
		$totals->add(publication: self::post('', '2026-11-05T10:00:00Z'));
		$totals->add(publication: self::post('bluesky', '2026-10-31T23:59:59Z'));
		$totals->add(publication: self::post('bluesky', 'not a date'));

		self::assertSame([], $totals->rows());
	}//end testOnlyPublishedPostsInsideTheWindowCount()

	/**
	 * A post exactly at the start of the window counts.
	 *
	 * @return void
	 */
	public function testAPostAtTheStartOfTheWindowCounts(): void {
		$totals = new NetworkTotals(since: self::SINCE);
		$totals->add(publication: self::post('x', '2026-11-01T00:00:00Z'));

		self::assertSame(1, $totals->rows()[0]['published']);
		self::assertSame('X', $totals->rows()[0]['label']);
	}//end testAPostAtTheStartOfTheWindowCounts()

	/**
	 * Without views there is no engagement share, and missing metrics count as zero.
	 *
	 * @return void
	 */
	public function testNoViewsMeansNoEngagementShare(): void {
		$totals = new NetworkTotals(since: self::SINCE);
		$post = self::post('threads', '2026-11-05T10:00:00Z');
		$post['metrics'] = 'not a list';
		$totals->add(publication: $post);

		$row = $totals->rows()[0];
		self::assertNull($row['engagementPercent']);
		self::assertSame(0, $row['views']);
		self::assertSame(1, $row['published']);
	}//end testNoViewsMeansNoEngagementShare()

	/**
	 * A network without a known label is shown capitalised.
	 *
	 * @return void
	 */
	public function testAnUnknownNetworkIsCapitalised(): void {
		$totals = new NetworkTotals(since: self::SINCE);
		$totals->add(publication: self::post('pixelfed', '2026-11-05T10:00:00Z'));

		self::assertSame('Pixelfed', $totals->rows()[0]['label']);
	}//end testAnUnknownNetworkIsCapitalised()

	/**
	 * Followers add up only for networks that have a published post.
	 *
	 * @return void
	 */
	public function testFollowersOnlyJoinNetworksWithPosts(): void {
		$totals = new NetworkTotals(since: self::SINCE);
		$totals->add(publication: self::post('instagram', '2026-11-05T10:00:00Z'));
		$totals->addFollowers(
			accounts: [
				['network' => 'instagram', 'followerCount' => 120],
				['network' => 'instagram', 'followerCount' => '30'],
				['network' => 'facebook', 'followerCount' => 999],
				['followerCount' => 5],
			]
		);

		$rows = $totals->rows();
		self::assertCount(1, $rows);
		self::assertSame(150, $rows[0]['followers']);
	}//end testFollowersOnlyJoinNetworksWithPosts()

	/**
	 * Rows come most-published first.
	 *
	 * @return void
	 */
	public function testRowsAreOrderedByPublishedCount(): void {
		$totals = new NetworkTotals(since: self::SINCE);
		$totals->add(publication: self::post('facebook', '2026-11-05T10:00:00Z'));
		$totals->add(publication: self::post('linkedin', '2026-11-05T10:00:00Z'));
		$totals->add(publication: self::post('linkedin', '2026-11-06T10:00:00Z'));
		$totals->add(publication: self::post('linkedin', '2026-11-07T10:00:00Z'));
		$totals->add(publication: self::post('mastodon', '2026-11-05T10:00:00Z'));
		$totals->add(publication: self::post('mastodon', '2026-11-06T10:00:00Z'));

		self::assertSame(['linkedin', 'mastodon', 'facebook'], array_column($totals->rows(), 'network'));
	}//end testRowsAreOrderedByPublishedCount()
}//end class
