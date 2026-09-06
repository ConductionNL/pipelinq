<?php

/**
 * Unit tests for the seven network adapters' request shapes.
 *
 * WHY THIS FILE IS THE MOST IMPORTANT TEST IN THE CHANGE. Mastodon can be
 * proven against a real account today. LinkedIn, X, Facebook, Instagram and
 * Threads cannot, because their developer applications have not been filed,
 * and Bluesky cannot either until OpenRegister's broker gains DPoP. For six of
 * the seven networks the assertion below is the ONLY assertion available, so
 * every method and path here was read out of that network's published API and
 * cross-checked against the allow-rules OpenRegister's provider catalogue
 * declares for the matching provider. An adapter that invented a path would be
 * refused by the broker rather than reaching the network.
 *
 * The second thing asserted is a negative: no adapter sets a host and no
 * adapter sets an authorization header. Both belong to the broker, and an
 * adapter that could set either could send the grant somewhere else.
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
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\Pipelinq\Service\Social\BlueskyAdapter;
use OCA\Pipelinq\Service\Social\FacebookPageAdapter;
use OCA\Pipelinq\Service\Social\InstagramBusinessAdapter;
use OCA\Pipelinq\Service\Social\LinkedInAdapter;
use OCA\Pipelinq\Service\Social\MastodonAdapter;
use OCA\Pipelinq\Service\Social\SocialAdapterCall;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialNetworkAdapter;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;
use OCA\Pipelinq\Service\Social\ThreadsAdapter;
use OCA\Pipelinq\Service\Social\XAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One request-shape test per network, plus the two negatives every adapter
 * must satisfy.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */
class SocialAdapterRequestShapeTest extends TestCase {
	/**
	 * A gateway that records the calls made through it and answers with a
	 * scripted queue, so a two-step publish can be driven without a network.
	 *
	 * @param array<int, SocialGatewayResult> $answers The scripted answers, in order.
	 * @param array<int, SocialAdapterCall> $seen Filled with the calls that were made.
	 * @param string $readiness The readiness the gateway reports.
	 *
	 * @return SocialBrokerGateway The recording gateway.
	 */
	private function recordingGateway(array $answers, array &$seen, string $readiness = SocialBrokerGateway::READY): SocialBrokerGateway {
		$container = $this->createMock(ContainerInterface::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new class($container, $logger, $answers, $seen, $readiness) extends SocialBrokerGateway {
			/**
			 * @param ContainerInterface $container The container.
			 * @param LoggerInterface $logger The logger.
			 * @param array<int, SocialGatewayResult> $answers The scripted answers.
			 * @param array<int, array<string, mixed>> $seen The recorded calls.
			 * @param string $readiness The readiness to report.
			 */
			public function __construct(
				ContainerInterface $container,
				LoggerInterface $logger,
				private array $answers,
				private array &$seen,
				private string $readiness,
			) {
				parent::__construct(container: $container, logger: $logger);
			}

			/**
			 * @param string $brokerProvider The provider.
			 * @return array{state: string, reason: string} The readiness.
			 */
			public function readiness(string $brokerProvider): array {
				return ['state' => $this->readiness, 'reason' => 'scripted'];
			}

			/**
			 * @param string $credentialRef The credential.
			 * @param string $method The method.
			 * @param string $path The path.
			 * @param array<string, string> $headers The headers.
			 * @param string|null $body The body.
			 * @param string|null $actingUserId The acting user.
			 * @return SocialGatewayResult The scripted answer.
			 */
			public function request(
				string $credentialRef,
				string $method,
				string $path,
				array $headers = [],
				?string $body = null,
				?string $actingUserId = null,
			): SocialGatewayResult {
				$this->seen[] = [
					'method' => $method,
					'path' => $path,
					'headers' => $headers,
					'body' => $body,
					'actingUserId' => $actingUserId,
				];

				return (array_shift($this->answers) ?? SocialGatewayResult::failed(
					code: SocialGatewayResult::UNAVAILABLE,
					reason: 'no scripted answer left',
				));
			}
		};
	}

	/**
	 * A gateway that reports every network ready and answers nothing.
	 *
	 * @return SocialBrokerGateway The gateway.
	 */
	private function readyGateway(): SocialBrokerGateway {
		$seen = [];

		return $this->recordingGateway(answers: [], seen: $seen);
	}

	/**
	 * A resolved post for one network.
	 *
	 * @param string $network The network.
	 * @param array<int, array<string, mixed>> $media The media.
	 *
	 * @return SocialPublishRequest The request.
	 */
	private function post(string $network, array $media = []): SocialPublishRequest {
		return new SocialPublishRequest(
			network: $network,
			body: 'OpenRegister 3.0 is uit.',
			link: 'https://conduction.nl/nieuws/openregister-3-0-is-uit',
			media: $media,
			credentialRef: '00000000-0000-0000-0000-000000000000',
			externalAccountId: 'acct-1',
			accountKind: 'organisation',
			handle: '@conduction',
			actingUserId: 'ruben',
		);
	}

	/**
	 * Mastodon publishes a status, with the link inside the text because
	 * Mastodon has no separate link field.
	 *
	 * @return void
	 */
	public function testMastodonPostsAStatus(): void {
		$call = (new MastodonAdapter($this->readyGateway()))->publishCall(request: $this->post(network: 'mastodon'));

		$this->assertSame('POST', $call->method);
		$this->assertSame('/api/v1/statuses', $call->path);
		$this->assertStringContainsString('OpenRegister 3.0 is uit.', $call->payload['status']);
		$this->assertStringContainsString('https://conduction.nl/nieuws/', $call->payload['status']);
		$this->assertSame('public', $call->payload['visibility']);
	}

	/**
	 * Bluesky writes an `app.bsky.feed.post` record into the account's own
	 * repository, addressed by its DID.
	 *
	 * @return void
	 */
	public function testBlueskyCreatesARepositoryRecord(): void {
		$call = (new BlueskyAdapter($this->readyGateway()))->publishCall(request: $this->post(network: 'bluesky'));

		$this->assertSame('POST', $call->method);
		$this->assertSame('/xrpc/com.atproto.repo.createRecord', $call->path);
		$this->assertSame('acct-1', $call->payload['repo']);
		$this->assertSame(BlueskyAdapter::POST_COLLECTION, $call->payload['collection']);
		$this->assertSame(BlueskyAdapter::POST_COLLECTION, $call->payload['record']['$type']);
		$this->assertStringContainsString('OpenRegister 3.0 is uit.', $call->payload['record']['text']);
	}

	/**
	 * LinkedIn posts as the connected ORGANISATION when the account is a
	 * company page.
	 *
	 * @return void
	 */
	public function testLinkedInPostsAsTheOrganisation(): void {
		$adapter = new LinkedInAdapter($this->readyGateway());
		$call = $adapter->publishCall(request: $this->post(network: 'linkedin'));

		$this->assertSame('POST', $call->method);
		$this->assertSame('/rest/posts', $call->path);
		$this->assertSame('urn:li:organization:acct-1', $call->payload['author']);
		$this->assertSame('PUBLISHED', $call->payload['lifecycleState']);
		$this->assertSame(LinkedInAdapter::API_VERSION, $call->headers['LinkedIn-Version']);
	}

	/**
	 * The author URN is the ONE field that differs between a company page and
	 * a colleague's own profile.
	 *
	 * @return void
	 */
	public function testLinkedInPostsAsTheMemberForAPersonalAccount(): void {
		$adapter = new LinkedInAdapter($this->readyGateway());
		$request = new SocialPublishRequest(
			network: 'linkedin',
			body: 'Hallo',
			credentialRef: '00000000-0000-0000-0000-000000000000',
			externalAccountId: 'abc123',
			accountKind: 'person',
		);

		$this->assertSame('urn:li:person:abc123', $adapter->authorUrn(request: $request));
	}

	/**
	 * X posts a tweet.
	 *
	 * @return void
	 */
	public function testXPostsATweet(): void {
		$call = (new XAdapter($this->readyGateway()))->publishCall(request: $this->post(network: 'x'));

		$this->assertSame('POST', $call->method);
		$this->assertSame('/2/tweets', $call->path);
		$this->assertStringContainsString('OpenRegister 3.0 is uit.', $call->payload['text']);
	}

	/**
	 * X is the one network that charges, for a post and for a read.
	 *
	 * @return void
	 */
	public function testXIsTheOnlyNetworkThatCharges(): void {
		$gateway = $this->readyGateway();
		$this->assertGreaterThan(0.0, (new XAdapter($gateway))->costPerPost());
		$this->assertGreaterThan(0.0, (new XAdapter($gateway))->costPerRead());
		$this->assertSame(0.0, (new MastodonAdapter($gateway))->costPerPost());
		$this->assertSame(0.0, (new LinkedInAdapter($gateway))->costPerRead());
	}

	/**
	 * A Facebook page posts to its own feed, with the link as its own field so
	 * the preview card renders.
	 *
	 * @return void
	 */
	public function testFacebookPostsToThePageFeed(): void {
		$call = (new FacebookPageAdapter($this->readyGateway()))->publishCall(request: $this->post(network: 'facebook'));

		$this->assertSame('POST', $call->method);
		$this->assertSame('/' . FacebookPageAdapter::GRAPH_VERSION . '/acct-1/feed', $call->path);
		$this->assertSame('OpenRegister 3.0 is uit.', $call->payload['message']);
		$this->assertSame('https://conduction.nl/nieuws/openregister-3-0-is-uit', $call->payload['link']);
	}

	/**
	 * Instagram creates a media container first and publishes it second.
	 *
	 * @return void
	 */
	public function testInstagramCreatesAContainerThenPublishesIt(): void {
		$seen = [];
		$gateway = $this->recordingGateway(
			answers: [
				SocialGatewayResult::succeeded(status: 200, body: ['id' => 'container-1']),
				SocialGatewayResult::succeeded(status: 200, body: ['id' => 'media-9']),
			],
			seen: $seen,
		);

		$outcome = (new InstagramBusinessAdapter($gateway))->publish(
			request: $this->post(network: 'instagram', media: [['url' => 'https://conduction.nl/img/or3.jpg']]),
		);

		$this->assertTrue($outcome->accepted);
		$this->assertSame('media-9', $outcome->externalId);
		$this->assertCount(2, $seen);
		$this->assertSame('/' . FacebookPageAdapter::GRAPH_VERSION . '/acct-1/media', $seen[0]['path']);
		$this->assertSame('/' . FacebookPageAdapter::GRAPH_VERSION . '/acct-1/media_publish', $seen[1]['path']);
		$this->assertStringContainsString('container-1', (string)$seen[1]['body']);
	}

	/**
	 * When the container step fails, the publish step is NOT attempted.
	 * Attempting it would report the second call's error and hide the one that
	 * actually happened.
	 *
	 * @return void
	 */
	public function testInstagramDoesNotPublishWhenTheContainerFails(): void {
		$seen = [];
		$gateway = $this->recordingGateway(
			answers: [
				SocialGatewayResult::failed(
					code: SocialGatewayResult::REJECTED_BY_NETWORK,
					reason: 'The image could not be fetched.',
				),
			],
			seen: $seen,
		);

		$outcome = (new InstagramBusinessAdapter($gateway))->publish(
			request: $this->post(network: 'instagram', media: [['url' => 'https://conduction.nl/img/or3.jpg']]),
		);

		$this->assertFalse($outcome->accepted);
		$this->assertCount(1, $seen, 'the publish step must not be attempted after a failed container');
	}

	/**
	 * Instagram needs media with a public address, and says so before any call
	 * is made rather than after one.
	 *
	 * @return void
	 */
	public function testInstagramRefusesATextOnlyPostWithoutCallingAnything(): void {
		$seen = [];
		$gateway = $this->recordingGateway(answers: [], seen: $seen);

		$outcome = (new InstagramBusinessAdapter($gateway))->publish(request: $this->post(network: 'instagram'));

		$this->assertFalse($outcome->accepted);
		$this->assertSame([], $seen);
	}

	/**
	 * Threads shapes the documented two-step request, and refuses before it
	 * because no provider is filed for it.
	 *
	 * @return void
	 */
	public function testThreadsShapesItsRequestAndRefusesUntilAProviderIsFiled(): void {
		$gateway = $this->readyGateway();
		$adapter = new ThreadsAdapter($gateway);
		$call = $adapter->publishCall(request: $this->post(network: 'threads'));

		$this->assertSame('POST', $call->method);
		$this->assertSame('/v1.0/acct-1/threads', $call->path);
		$this->assertSame('TEXT', $call->payload['media_type']);
		$this->assertSame(
			'/v1.0/acct-1/threads_publish',
			$adapter->publishContainerCall(request: $this->post(network: 'threads'), containerId: 'c-1')->path,
		);

		$this->assertSame('', $adapter->brokerProvider(), 'no Threads provider is filed upstream');
	}

	/**
	 * A network with no developer application filed fails TYPED and never
	 * reaches the broker.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredNetworkFailsTypedWithoutCallingTheBroker(): void {
		$seen = [];
		$gateway = $this->recordingGateway(
			answers: [],
			seen: $seen,
			readiness: SocialBrokerGateway::NOT_CONFIGURED,
		);

		$outcome = (new XAdapter($gateway))->publish(request: $this->post(network: 'x'));

		$this->assertFalse($outcome->accepted);
		$this->assertSame(SocialGatewayResult::NOT_CONFIGURED, $outcome->failureCode);
		$this->assertSame([], $seen);
	}

	/**
	 * No adapter sets a host and no adapter sets an authorization header. Both
	 * belong to the broker, and an adapter that could set either could send
	 * the grant somewhere else.
	 *
	 * @return void
	 */
	public function testNoAdapterNamesAHostOrAnAuthorizationHeader(): void {
		$gateway = $this->readyGateway();
		$adapters = [
			new MastodonAdapter($gateway),
			new BlueskyAdapter($gateway),
			new LinkedInAdapter($gateway),
			new XAdapter($gateway),
			new FacebookPageAdapter($gateway),
			new InstagramBusinessAdapter($gateway),
			new ThreadsAdapter($gateway),
		];

		foreach ($adapters as $adapter) {
			$this->assertInstanceOf(SocialNetworkAdapter::class, $adapter);
			$call = $adapter->publishCall(
				request: $this->post(network: $adapter->network(), media: [['url' => 'https://conduction.nl/i.jpg']]),
			);

			$this->assertStringStartsWith('/', $call->path, $adapter->network() . ' must pass a path, never a URL');
			$this->assertStringNotContainsString('://', $call->path, $adapter->network() . ' must not name a host');

			foreach (array_keys($call->requestHeaders()) as $header) {
				$this->assertNotSame(
					'authorization',
					strtolower((string)$header),
					$adapter->network() . ' must not set an authorization header',
				);
			}
		}
	}
}//end class
