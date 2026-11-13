<?php

/**
 * Tests for XAdapter.
 *
 * X is the network that charges, so the tests pin that a published post
 * carries its cost, that an unfiled network is refused before the broker is
 * called, and how the `data`-nested answers are read into an id, an address,
 * five metrics and a follower count.
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

use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;
use OCA\Pipelinq\Service\Social\XAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Social\XAdapter
 * @covers \OCA\Pipelinq\Service\Social\AbstractSocialAdapter
 * @uses \OCA\Pipelinq\Service\Social\SocialAdapterCall
 * @uses \OCA\Pipelinq\Service\Social\SocialPublishRequest
 * @uses \OCA\Pipelinq\Service\Social\SocialPublishOutcome
 * @uses \OCA\Pipelinq\Service\Social\SocialGatewayResult
 */
class XAdapterTest extends TestCase {

	/**
	 * The mocked broker gateway.
	 *
	 * @var SocialBrokerGateway&MockObject
	 */
	private SocialBrokerGateway $gateway;

	/**
	 * The adapter under test.
	 *
	 * @var XAdapter
	 */
	private XAdapter $adapter;

	/**
	 * Set up the adapter over a mocked gateway.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->gateway = $this->createMock(SocialBrokerGateway::class);
		$this->adapter = new XAdapter($this->gateway);
	}

	/**
	 * A post for one handle.
	 *
	 * @param string $handle The handle.
	 *
	 * @return SocialPublishRequest The request.
	 */
	private function post(string $handle = '@conduction'): SocialPublishRequest {
		return new SocialPublishRequest(
			network: 'x',
			body: 'Nieuw',
			link: 'https://conduction.nl',
			credentialRef: 'cred-x',
			handle: $handle,
			actingUserId: 'ruben',
		);
	}

	/**
	 * The network's identity, limits and prices.
	 *
	 * @return void
	 */
	public function testIdentityLimitsAndPrices(): void {
		$this->assertSame('x', $this->adapter->network());
		$this->assertSame('x', $this->adapter->brokerProvider());
		$this->assertSame(280, $this->adapter->bodyLimit());
		$this->assertSame(4, $this->adapter->maxMedia());
		$this->assertSame(XAdapter::COST_PER_POST_EUR, $this->adapter->costPerPost());
		$this->assertSame(XAdapter::COST_PER_READ_EUR, $this->adapter->costPerRead());
	}

	/**
	 * An unfiled network is refused, typed, with the gateway's reason and
	 * without a call to the broker.
	 *
	 * @return void
	 */
	public function testAnUnfiledNetworkIsRefusedBeforeTheBroker(): void {
		$this->gateway->method('readiness')
			->with('x')
			->willReturn(['state' => SocialBrokerGateway::NOT_CONFIGURED, 'reason' => 'no developer account']);
		$this->gateway->expects($this->never())->method('request');

		$outcome = $this->adapter->publish(request: $this->post());

		$this->assertFalse($outcome->accepted);
		$this->assertSame(SocialGatewayResult::NOT_CONFIGURED, $outcome->failureCode);
		$this->assertSame('no developer account', $outcome->failureReason);
		$this->assertSame(0.0, $outcome->cost);
	}

	/**
	 * A published post reads its id out of `data`, builds the x.com address
	 * and carries the per-post cost.
	 *
	 * @return void
	 */
	public function testPublishCarriesIdAddressAndCost(): void {
		$this->gateway->method('readiness')->willReturn(['state' => SocialBrokerGateway::READY, 'reason' => '']);
		$this->gateway->expects($this->once())
			->method('request')
			->with(
				'cred-x',
				'POST',
				'/2/tweets',
				['Content-Type' => 'application/json'],
				'{"text":"Nieuw\n\nhttps://conduction.nl"}',
				'ruben',
			)
			->willReturn(SocialGatewayResult::succeeded(status: 201, body: ['data' => ['id' => '1790']]));

		$outcome = $this->adapter->publish(request: $this->post());

		$this->assertTrue($outcome->accepted);
		$this->assertSame('1790', $outcome->externalId);
		$this->assertSame('https://x.com/conduction/status/1790', $outcome->url);
		$this->assertSame(XAdapter::COST_PER_POST_EUR, $outcome->cost);
	}

	/**
	 * A `data` that is not an object, or no handle, yields no id or address.
	 *
	 * @return void
	 */
	public function testNoIdOrAddressFromAnUnexpectedAnswer(): void {
		$this->gateway->method('readiness')->willReturn(['state' => SocialBrokerGateway::READY, 'reason' => '']);
		$this->gateway->method('request')->willReturnOnConsecutiveCalls(
			SocialGatewayResult::succeeded(status: 201, body: ['data' => 'odd']),
			SocialGatewayResult::succeeded(status: 201, body: ['data' => ['id' => '1790']]),
		);

		$odd = $this->adapter->publish(request: $this->post());
		$this->assertSame('', $odd->externalId);
		$this->assertSame('', $odd->url);

		$noHandle = $this->adapter->publish(request: $this->post(handle: ''));
		$this->assertSame('1790', $noHandle->externalId);
		$this->assertSame('', $noHandle->url);
	}

	/**
	 * A failed call is refused with the gateway's code and costs nothing.
	 *
	 * @return void
	 */
	public function testAFailedCallIsRefusedWithItsCode(): void {
		$this->gateway->method('readiness')->willReturn(['state' => SocialBrokerGateway::READY, 'reason' => '']);
		$this->gateway->method('request')->willReturn(
			SocialGatewayResult::failed(code: SocialGatewayResult::REJECTED_BY_NETWORK, reason: 'duplicate', status: 403)
		);

		$outcome = $this->adapter->publish(request: $this->post());

		$this->assertFalse($outcome->accepted);
		$this->assertSame(SocialGatewayResult::REJECTED_BY_NETWORK, $outcome->failureCode);
		$this->assertSame('duplicate', $outcome->failureReason);
		$this->assertSame(0.0, $outcome->cost);
	}

	/**
	 * The metrics read asks for the public metrics of one post.
	 *
	 * @return void
	 */
	public function testMetricsCallAsksForPublicMetrics(): void {
		$call = $this->adapter->metricsCall(externalId: '17/90', account: []);

		$this->assertSame('GET', $call->method);
		$this->assertSame('/2/tweets/17%2F90?tweet.fields=public_metrics', $call->path);
		$this->assertNull($this->adapter->metricsCall(externalId: '  ', account: []));
	}

	/**
	 * All five numbers are read, and a missing one is zero.
	 *
	 * @return void
	 */
	public function testNormaliseMetricsReadsAllFive(): void {
		$payload = [
			'data' => [
				'public_metrics' => [
					'impression_count' => 500,
					'like_count' => 12,
					'reply_count' => 3,
					'retweet_count' => '4',
				],
			],
		];

		$this->assertSame(
			['views' => 500, 'likes' => 12, 'comments' => 3, 'shares' => 4, 'clicks' => 0],
			$this->adapter->normaliseMetrics(payload: $payload)
		);
		$this->assertSame(XAdapter::noMetrics(), $this->adapter->normaliseMetrics(payload: []));
	}

	/**
	 * Followers come from the account's own record.
	 *
	 * @return void
	 */
	public function testFollowersComeFromTheOwnRecord(): void {
		$call = $this->adapter->followersCall(account: []);

		$this->assertSame('GET', $call->method);
		$this->assertSame('/2/users/me?user.fields=public_metrics', $call->path);
		$this->assertSame(
			42,
			$this->adapter->readFollowers(payload: ['data' => ['public_metrics' => ['followers_count' => 42]]])
		);
		$this->assertSame(0, $this->adapter->readFollowers(payload: ['data' => 'odd']));
	}

	/**
	 * The composer intent carries the encoded text with its link.
	 *
	 * @return void
	 */
	public function testComposerUrlEncodesTheText(): void {
		$this->assertSame(
			'https://x.com/intent/post?text=Nieuw%0A%0Ahttps%3A%2F%2Fconduction.nl',
			$this->adapter->composerUrl(request: $this->post())
		);
	}
}
