<?php

/**
 * Tests for BlueskyAdapter.
 *
 * Request shapes are covered by SocialAdapterRequestShapeTest. These pin what
 * the adapter makes of Bluesky's answers: the AT URI as the published id, the
 * public bsky.app address built from the handle and the record key, the feed
 * search that picks one post's counts, and the profile follower count.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Social
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\Pipelinq\Service\Social\BlueskyAdapter;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Social\BlueskyAdapter
 * @covers \OCA\Pipelinq\Service\Social\AbstractSocialAdapter
 * @uses \OCA\Pipelinq\Service\Social\SocialAdapterCall
 * @uses \OCA\Pipelinq\Service\Social\SocialPublishRequest
 * @uses \OCA\Pipelinq\Service\Social\SocialPublishOutcome
 * @uses \OCA\Pipelinq\Service\Social\SocialGatewayResult
 */
class BlueskyAdapterTest extends TestCase {

	/**
	 * The mocked broker gateway.
	 *
	 * @var SocialBrokerGateway&MockObject
	 */
	private SocialBrokerGateway $gateway;

	/**
	 * The adapter under test.
	 *
	 * @var BlueskyAdapter
	 */
	private BlueskyAdapter $adapter;

	/**
	 * Set up the adapter over a mocked gateway.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->gateway = $this->createMock(SocialBrokerGateway::class);
		$this->gateway->method('readiness')->willReturn(['state' => SocialBrokerGateway::PREVIEW, 'reason' => 'dpop']);
		$this->adapter = new BlueskyAdapter($this->gateway);
	}

	/**
	 * A post for one handle.
	 *
	 * @param string $handle The handle.
	 *
	 * @return SocialPublishRequest The request.
	 */
	private function post(string $handle = '@conduction.nl'): SocialPublishRequest {
		return new SocialPublishRequest(
			network: 'bluesky',
			body: 'Hallo',
			credentialRef: 'cred-1',
			externalAccountId: 'did:plc:abc',
			handle: $handle,
			actingUserId: 'ruben',
		);
	}

	/**
	 * The network's identity and limits.
	 *
	 * @return void
	 */
	public function testIdentityAndLimits(): void {
		$this->assertSame('bluesky', $this->adapter->network());
		$this->assertSame('bluesky', $this->adapter->brokerProvider());
		$this->assertSame(300, $this->adapter->bodyLimit());
		$this->assertSame(4, $this->adapter->maxMedia());
		$this->assertSame(0.0, $this->adapter->costPerPost());
		$this->assertSame(0.0, $this->adapter->costPerRead());
	}

	/**
	 * A preview network still publishes; the AT URI is the id and the public
	 * address is built from the handle and the record key.
	 *
	 * @return void
	 */
	public function testPublishReadsTheAtUriAndBuildsTheWebAddress(): void {
		$this->gateway->expects($this->once())
			->method('request')
			->with(
				'cred-1',
				'POST',
				'/xrpc/com.atproto.repo.createRecord',
				['Content-Type' => 'application/json'],
				$this->isType('string'),
				'ruben',
			)
			->willReturn(SocialGatewayResult::succeeded(status: 200, body: ['uri' => 'at://did:plc:abc/app.bsky.feed.post/3kxyz']));

		$outcome = $this->adapter->publish(request: $this->post());

		$this->assertTrue($outcome->accepted);
		$this->assertSame('at://did:plc:abc/app.bsky.feed.post/3kxyz', $outcome->externalId);
		$this->assertSame('https://bsky.app/profile/conduction.nl/post/3kxyz', $outcome->url);
		$this->assertSame(0.0, $outcome->cost);
	}

	/**
	 * Without a handle, or without a URI, no public address is invented.
	 *
	 * @return void
	 */
	public function testNoAddressWithoutAHandleOrAUri(): void {
		$this->gateway->method('request')->willReturnOnConsecutiveCalls(
			SocialGatewayResult::succeeded(status: 200, body: ['uri' => 'at://did:plc:abc/app.bsky.feed.post/3kxyz']),
			SocialGatewayResult::succeeded(status: 200, body: []),
			SocialGatewayResult::succeeded(status: 200, body: ['uri' => 'at://did:plc:abc/app.bsky.feed.post/']),
		);

		$this->assertSame('', $this->adapter->publish(request: $this->post(handle: '@'))->url);
		$noUri = $this->adapter->publish(request: $this->post());
		$this->assertSame('', $noUri->externalId);
		$this->assertSame('', $noUri->url);
		$this->assertSame('', $this->adapter->publish(request: $this->post())->url);
	}

	/**
	 * A gateway failure keeps its code and reason.
	 *
	 * @return void
	 */
	public function testAGatewayFailureIsRefusedWithItsCode(): void {
		$this->gateway->method('request')->willReturn(
			SocialGatewayResult::failed(code: SocialGatewayResult::RELINK_NEEDED, reason: 'token refused')
		);

		$outcome = $this->adapter->publish(request: $this->post());

		$this->assertFalse($outcome->accepted);
		$this->assertSame(SocialGatewayResult::RELINK_NEEDED, $outcome->failureCode);
		$this->assertSame('token refused', $outcome->failureReason);
	}

	/**
	 * The metrics read is the actor's feed, and needs both an actor and a post.
	 *
	 * @return void
	 */
	public function testMetricsCallReadsTheAuthorFeed(): void {
		$call = $this->adapter->metricsCall(externalId: 'at://x/y/z', account: ['externalAccountId' => ' did:plc:abc ']);

		$this->assertSame('GET', $call->method);
		$this->assertSame('/xrpc/app.bsky.feed.getAuthorFeed?actor=did%3Aplc%3Aabc&limit=100', $call->path);
		$this->assertNull($this->adapter->metricsCall(externalId: 'at://x/y/z', account: []));
		$this->assertNull($this->adapter->metricsCall(externalId: ' ', account: ['externalAccountId' => 'did:plc:abc']));
	}

	/**
	 * The post is picked out of the feed by its URI and its counts mapped.
	 *
	 * @return void
	 */
	public function testNormaliseMetricsPicksThePostOutOfTheFeed(): void {
		$payload = [
			'feed' => [
				'not-an-entry',
				['post' => ['uri' => 'at://other', 'likeCount' => 99]],
				['post' => ['uri' => 'at://mine', 'likeCount' => 7, 'replyCount' => '3', 'repostCount' => 2]],
			],
		];

		$this->assertSame(
			['views' => 0, 'likes' => 7, 'comments' => 3, 'shares' => 2, 'clicks' => 0],
			$this->adapter->normaliseMetrics(payload: $payload, externalId: 'at://mine')
		);
	}

	/**
	 * A post off the first page, a missing feed or no id gives five zeroes.
	 *
	 * @return void
	 */
	public function testNormaliseMetricsFallsBackToZeroes(): void {
		$zero = BlueskyAdapter::noMetrics();

		$this->assertSame($zero, $this->adapter->normaliseMetrics(payload: ['feed' => []], externalId: 'at://mine'));
		$this->assertSame($zero, $this->adapter->normaliseMetrics(payload: ['feed' => 'x'], externalId: 'at://mine'));
		$this->assertSame($zero, $this->adapter->normaliseMetrics(payload: ['feed' => [['post' => ['uri' => 'at://mine']]]], externalId: ''));
	}

	/**
	 * Followers come from the actor's profile.
	 *
	 * @return void
	 */
	public function testFollowersComeFromTheProfile(): void {
		$call = $this->adapter->followersCall(account: ['externalAccountId' => 'did:plc:abc']);

		$this->assertSame('GET', $call->method);
		$this->assertSame('/xrpc/app.bsky.actor.getProfile?actor=did%3Aplc%3Aabc', $call->path);
		$this->assertNull($this->adapter->followersCall(account: ['externalAccountId' => '  ']));
		$this->assertSame(1234, $this->adapter->readFollowers(payload: ['followersCount' => 1234]));
		$this->assertSame(0, $this->adapter->readFollowers(payload: ['followersCount' => 'many']));
	}

	/**
	 * The composer intent carries the encoded text.
	 *
	 * @return void
	 */
	public function testComposerUrlEncodesTheText(): void {
		$request = new SocialPublishRequest(network: 'bluesky', body: 'Hallo wereld', link: 'https://conduction.nl');

		$this->assertSame(
			'https://bsky.app/intent/compose?text=Hallo%20wereld%0A%0Ahttps%3A%2F%2Fconduction.nl',
			$this->adapter->composerUrl(request: $request)
		);
	}
}
