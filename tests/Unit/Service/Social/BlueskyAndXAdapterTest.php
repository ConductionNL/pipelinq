<?php

/**
 * Unit tests for the Bluesky and X adapters beyond their request shape:
 * publishing through the gateway, the published id and URL, metrics,
 * followers and the composer link.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Social
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\Pipelinq\Service\Social\AbstractSocialAdapter;
use OCA\Pipelinq\Service\Social\BlueskyAdapter;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;
use OCA\Pipelinq\Service\Social\XAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BlueskyAdapter and XAdapter.
 *
 * @covers \OCA\Pipelinq\Service\Social\BlueskyAdapter
 * @covers \OCA\Pipelinq\Service\Social\XAdapter
 * @uses   \OCA\Pipelinq\Service\Social\AbstractSocialAdapter
 * @uses   \OCA\Pipelinq\Service\Social\SocialAdapterCall
 * @uses   \OCA\Pipelinq\Service\Social\SocialBrokerGateway
 * @uses   \OCA\Pipelinq\Service\Social\SocialGatewayResult
 * @uses   \OCA\Pipelinq\Service\Social\SocialPublishOutcome
 * @uses   \OCA\Pipelinq\Service\Social\SocialPublishRequest
 */
class BlueskyAndXAdapterTest extends TestCase {

	/**
	 * Calls made through the gateway.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $seen = [];

	/**
	 * A ready gateway that records each request and answers from a queue.
	 *
	 * @param array<int, SocialGatewayResult> $answers The answers, in order.
	 *
	 * @return SocialBrokerGateway
	 */
	private function gateway(array $answers = []): SocialBrokerGateway {
		$seen = &$this->seen;

		return new class($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $answers, $seen) extends SocialBrokerGateway {
			/**
			 * @param ContainerInterface               $container The container.
			 * @param LoggerInterface                  $logger    The logger.
			 * @param array<int, SocialGatewayResult>  $answers   The answers.
			 * @param array<int, array<string, mixed>> $seen      The recorded calls.
			 */
			public function __construct(ContainerInterface $container, LoggerInterface $logger, private array $answers, private array &$seen) {
				parent::__construct(container: $container, logger: $logger);
			}

			/**
			 * @param string $brokerProvider The provider.
			 * @return array{state: string, reason: string}
			 */
			public function readiness(string $brokerProvider): array {
				return ['state' => SocialBrokerGateway::READY, 'reason' => ''];
			}

			/**
			 * @param string                $credentialRef The credential.
			 * @param string                $method        The method.
			 * @param string                $path          The path.
			 * @param array<string, string> $headers       The headers.
			 * @param string|null           $body          The body.
			 * @param string|null           $actingUserId  The acting user.
			 * @return SocialGatewayResult
			 */
			public function request(string $credentialRef, string $method, string $path, array $headers = [], ?string $body = null, ?string $actingUserId = null): SocialGatewayResult {
				$this->seen[] = ['credentialRef' => $credentialRef, 'method' => $method, 'path' => $path, 'body' => $body, 'actingUserId' => $actingUserId];

				return array_shift($this->answers);
			}
		};
	}//end gateway()

	/**
	 * A post for one network with the given handle.
	 *
	 * @param string $network The network.
	 * @param string $handle  The account handle.
	 *
	 * @return SocialPublishRequest
	 */
	private function post(string $network, string $handle = '@conduction.nl'): SocialPublishRequest {
		return new SocialPublishRequest(
			network: $network,
			body: 'Release notes',
			link: 'https://conduction.nl/r',
			media: [],
			credentialRef: 'cred-1',
			externalAccountId: 'did:plc:abc',
			accountKind: 'organisation',
			handle: $handle,
			actingUserId: 'ruben',
		);
	}//end post()

	/**
	 * Bluesky publishes through the gateway and builds the post URL from the handle and record key.
	 *
	 * @return void
	 */
	public function testBlueskyPublishReadsTheRecordUri(): void {
		$uri     = 'at://did:plc:abc/app.bsky.feed.post/3kxyz';
		$adapter = new BlueskyAdapter($this->gateway(answers: [SocialGatewayResult::succeeded(status: 200, body: ['uri' => $uri])]));

		$outcome = $adapter->publish(request: $this->post(network: 'bluesky'));

		$this->assertTrue($outcome->accepted);
		$this->assertSame($uri, $outcome->externalId);
		$this->assertSame('https://bsky.app/profile/conduction.nl/post/3kxyz', $outcome->url);
		$this->assertSame(0.0, $outcome->cost);
		$this->assertSame('POST', $this->seen[0]['method']);
		$this->assertSame('/xrpc/com.atproto.repo.createRecord', $this->seen[0]['path']);
		$this->assertSame('cred-1', $this->seen[0]['credentialRef']);
		$this->assertSame('ruben', $this->seen[0]['actingUserId']);
	}//end testBlueskyPublishReadsTheRecordUri()

	/**
	 * Without a uri, a handle or a record key there is no URL; a failed call is a refusal.
	 *
	 * @return void
	 */
	public function testBlueskyPublishWithoutAUsableUri(): void {
		$adapter = new BlueskyAdapter($this->gateway(answers: [
			SocialGatewayResult::succeeded(status: 200, body: []),
			SocialGatewayResult::succeeded(status: 200, body: ['uri' => 'at://x/post/1']),
			SocialGatewayResult::succeeded(status: 200, body: ['uri' => 'at://x/post/']),
			SocialGatewayResult::failed(code: SocialGatewayResult::UNAVAILABLE, reason: 'down'),
		]));

		$noUri = $adapter->publish(request: $this->post(network: 'bluesky'));
		$this->assertSame(['', ''], [$noUri->externalId, $noUri->url]);
		$this->assertSame('', $adapter->publish(request: $this->post(network: 'bluesky', handle: '@'))->url);
		$this->assertSame('', $adapter->publish(request: $this->post(network: 'bluesky'))->url);

		$failed = $adapter->publish(request: $this->post(network: 'bluesky'));
		$this->assertFalse($failed->accepted);
		$this->assertSame(SocialGatewayResult::UNAVAILABLE, $failed->failureCode);
		$this->assertSame('down', $failed->failureReason);
	}//end testBlueskyPublishWithoutAUsableUri()

	/**
	 * Bluesky reads metrics from the author feed and followers from the profile.
	 *
	 * @return void
	 */
	public function testBlueskyMetricsAndFollowers(): void {
		$adapter = new BlueskyAdapter($this->gateway());
		$account = ['externalAccountId' => 'did:plc:a b'];

		$this->assertSame('/xrpc/app.bsky.feed.getAuthorFeed?actor=did%3Aplc%3Aa%20b&limit=100', $adapter->metricsCall(externalId: 'at://p/1', account: $account)->path);
		$this->assertNull($adapter->metricsCall(externalId: 'at://p/1', account: []));
		$this->assertNull($adapter->metricsCall(externalId: ' ', account: $account));
		$this->assertSame('/xrpc/app.bsky.actor.getProfile?actor=did%3Aplc%3Aa%20b', $adapter->followersCall(account: $account)->path);
		$this->assertNull($adapter->followersCall(account: ['externalAccountId' => '']));
		$this->assertSame(42, $adapter->readFollowers(payload: ['followersCount' => 42]));

		$feed = ['feed' => [
			'junk',
			['post' => ['uri' => 'at://p/0', 'likeCount' => 99]],
			['post' => ['uri' => 'at://p/1', 'likeCount' => 3, 'replyCount' => 2, 'repostCount' => 1]],
		]];
		$this->assertSame(['views' => 0, 'likes' => 3, 'comments' => 2, 'shares' => 1, 'clicks' => 0], $adapter->normaliseMetrics(payload: $feed, externalId: 'at://p/1'));
		$this->assertSame(AbstractSocialAdapter::noMetrics(), $adapter->normaliseMetrics(payload: $feed, externalId: 'at://p/9'));
		$this->assertSame(AbstractSocialAdapter::noMetrics(), $adapter->normaliseMetrics(payload: $feed));
		$this->assertSame(AbstractSocialAdapter::noMetrics(), $adapter->normaliseMetrics(payload: ['feed' => 'x'], externalId: 'at://p/1'));
	}//end testBlueskyMetricsAndFollowers()

	/**
	 * Bluesky's limits and composer link.
	 *
	 * @return void
	 */
	public function testBlueskyLimitsAndComposer(): void {
		$adapter = new BlueskyAdapter($this->gateway());

		$this->assertSame(['bluesky', 'bluesky', 300, 4], [$adapter->network(), $adapter->brokerProvider(), $adapter->bodyLimit(), $adapter->maxMedia()]);
		$this->assertSame('https://bsky.app/intent/compose?text=Release%20notes%0A%0Ahttps%3A%2F%2Fconduction.nl%2Fr', $adapter->composerUrl(request: $this->post(network: 'bluesky')));
	}//end testBlueskyLimitsAndComposer()

	/**
	 * X publishes a tweet, reads its id from data.id and charges the post cost.
	 *
	 * @return void
	 */
	public function testXPublishReadsTheTweetId(): void {
		$adapter = new XAdapter($this->gateway(answers: [
			SocialGatewayResult::succeeded(status: 201, body: ['data' => ['id' => '1789']]),
			SocialGatewayResult::succeeded(status: 201, body: ['data' => 'x']),
			SocialGatewayResult::succeeded(status: 201, body: ['data' => ['id' => '1790']]),
		]));

		$outcome = $adapter->publish(request: $this->post(network: 'x'));
		$this->assertSame(['1789', 'https://x.com/conduction.nl/status/1789', XAdapter::COST_PER_POST_EUR], [$outcome->externalId, $outcome->url, $outcome->cost]);
		$this->assertSame('/2/tweets', $this->seen[0]['path']);
		$this->assertSame(['text' => "Release notes\n\nhttps://conduction.nl/r"], json_decode((string) $this->seen[0]['body'], true));

		$malformed = $adapter->publish(request: $this->post(network: 'x'));
		$this->assertSame(['', ''], [$malformed->externalId, $malformed->url]);

		$this->assertSame('', $adapter->publish(request: $this->post(network: 'x', handle: ''))->url);
	}//end testXPublishReadsTheTweetId()

	/**
	 * X metrics come from public_metrics; followers from the authenticated user.
	 *
	 * @return void
	 */
	public function testXMetricsAndFollowers(): void {
		$adapter = new XAdapter($this->gateway());

		$this->assertSame('/2/tweets/17%2F89?tweet.fields=public_metrics', $adapter->metricsCall(externalId: '17/89', account: [])->path);
		$this->assertNull($adapter->metricsCall(externalId: '', account: []));
		$this->assertSame(
			['views' => 100, 'likes' => 5, 'comments' => 4, 'shares' => 3, 'clicks' => 2],
			$adapter->normaliseMetrics(payload: ['data' => ['public_metrics' => ['impression_count' => 100, 'like_count' => 5, 'reply_count' => 4, 'retweet_count' => 3, 'url_link_clicks' => 2]]])
		);
		$this->assertSame(AbstractSocialAdapter::noMetrics(), $adapter->normaliseMetrics(payload: []));
		$this->assertSame('/2/users/me?user.fields=public_metrics', $adapter->followersCall(account: [])->path);
		$this->assertSame(7, $adapter->readFollowers(payload: ['data' => ['public_metrics' => ['followers_count' => 7]]]));
		$this->assertSame(['x', 'x', 280, 4], [$adapter->network(), $adapter->brokerProvider(), $adapter->bodyLimit(), $adapter->maxMedia()]);
		$this->assertSame('https://x.com/intent/post?text=Release%20notes%0A%0Ahttps%3A%2F%2Fconduction.nl%2Fr', $adapter->composerUrl(request: $this->post(network: 'x')));
	}//end testXMetricsAndFollowers()
}//end class
