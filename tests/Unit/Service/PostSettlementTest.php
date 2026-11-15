<?php

/**
 * Unit tests for PostSettlement.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PostSettlement;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\SocialPostService;
use PHPUnit\Framework\TestCase;

/**
 * PostSettlement derives a social post's status from its per-network publications.
 */
class PostSettlementTest extends TestCase {

	/**
	 * A publication row on a network.
	 *
	 * @param string $network The network.
	 * @param string $status  Its status.
	 * @param string $reason  Its failure reason.
	 *
	 * @return array<string, string>
	 */
	private static function row(string $network, string $status, string $reason=''): array {
		return ['network' => $network, 'status' => $status, 'failureReason' => $reason];
	}//end row()

	/**
	 * A post without publications stays scheduled.
	 *
	 * @return void
	 */
	public function testNoPublicationsStayScheduled(): void {
		$post = (new PostSettlement())->settled(post: ['id' => 'p', 'status' => 'approval', 'failureReason' => 'old'], rows: []);

		self::assertSame(SocialPostService::STATUS_SCHEDULED, $post['status']);
		self::assertSame('', $post['failureReason']);
		self::assertSame('p', $post['id']);
	}//end testNoPublicationsStayScheduled()

	/**
	 * A post is published once every network is published, shared or awaiting a share.
	 *
	 * @return void
	 */
	public function testEverySettledNetworkPublishesThePost(): void {
		$post = (new PostSettlement())->settled(
			post: ['status' => 'scheduled'],
			rows: [
				self::row('mastodon', SocialPublicationStore::PUBLISHED),
				self::row('linkedin', SocialPublicationStore::SHARED),
				self::row('instagram', SocialPublicationStore::AWAITING_SHARE),
			]
		);

		self::assertSame(SocialPostService::STATUS_PUBLISHED, $post['status']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $post['publishedAt']);
	}//end testEverySettledNetworkPublishesThePost()

	/**
	 * A post that already carries a publication moment keeps it.
	 *
	 * @return void
	 */
	public function testAnExistingPublicationMomentIsKept(): void {
		$post = (new PostSettlement())->settled(
			post: ['publishedAt' => '2026-11-01T09:00:00Z'],
			rows: [self::row('bluesky', SocialPublicationStore::PUBLISHED)]
		);

		self::assertSame('2026-11-01T09:00:00Z', $post['publishedAt']);
	}//end testAnExistingPublicationMomentIsKept()

	/**
	 * A blank publication moment is filled.
	 *
	 * @return void
	 */
	public function testABlankPublicationMomentIsFilled(): void {
		$post = (new PostSettlement())->settled(post: ['publishedAt' => '  '], rows: [self::row('x', SocialPublicationStore::PUBLISHED)]);

		self::assertNotSame('  ', $post['publishedAt']);
	}//end testABlankPublicationMomentIsFilled()

	/**
	 * A network still pending keeps the post scheduled.
	 *
	 * @return void
	 */
	public function testAPendingNetworkKeepsThePostScheduled(): void {
		$post = (new PostSettlement())->settled(
			post: [],
			rows: [self::row('mastodon', SocialPublicationStore::PUBLISHED), self::row('linkedin', SocialPublicationStore::PENDING)]
		);

		self::assertSame(SocialPostService::STATUS_SCHEDULED, $post['status']);
		self::assertArrayNotHasKey('publishedAt', $post);
	}//end testAPendingNetworkKeepsThePostScheduled()

	/**
	 * Any failed network fails the post and names every failure.
	 *
	 * @return void
	 */
	public function testFailuresFailThePostAndAreNamed(): void {
		$post = (new PostSettlement())->settled(
			post: [],
			rows: [
				self::row('linkedin', SocialPublicationStore::FAILED, 'token expired.'),
				self::row('mastodon', SocialPublicationStore::PUBLISHED),
				self::row('bluesky', SocialPublicationStore::FAILED, 'rate limited.'),
			]
		);

		self::assertSame(SocialPostService::STATUS_FAILED, $post['status']);
		self::assertSame('linkedin: token expired. bluesky: rate limited.', $post['failureReason']);
	}//end testFailuresFailThePostAndAreNamed()
}//end class
