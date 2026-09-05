<?php

/**
 * Unit tests for SocialMetricsService.
 *
 * The normalisation is asserted against each network's OWN payload shape,
 * because that is the thing that goes wrong quietly: Meta and LinkedIn
 * withdrew reach and impressions in June 2026, and a report reading those
 * names would simply have gone blank one morning. A number a network does not
 * report has to stay zero rather than be borrowed from an adjacent one.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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
 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\Social\BlueskyAdapter;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use OCA\Pipelinq\Service\Social\FacebookPageAdapter;
use OCA\Pipelinq\Service\Social\LinkedInAdapter;
use OCA\Pipelinq\Service\Social\MastodonAdapter;
use OCA\Pipelinq\Service\Social\SocialAdapterCall;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialNetworkAdapter;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\SocialAccountService;
use OCA\Pipelinq\Service\SocialMetricsService;
use OCA\Pipelinq\Tests\Unit\Service\Social\InMemoryObjectStore;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The daily pull, the normalisation and the engagement ranking.
 *
 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The pull joins six
 *  collaborators, so its test constructs six doubles.
 */
class SocialMetricsServiceTest extends TestCase {
	/**
	 * The in-memory store.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * The publication rows.
	 *
	 * @var SocialPublicationStore
	 */
	private SocialPublicationStore $publications;

	/**
	 * The scripted gateway answers, keyed by the path that was called.
	 *
	 * @var array<string, SocialGatewayResult>
	 */
	private array $answers = [];

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryObjectStore(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		);
		$this->publications = new SocialPublicationStore($this->store);
	}

	/**
	 * A gateway that answers per path, and fails anything unscripted.
	 *
	 * @return SocialBrokerGateway The gateway.
	 */
	private function gateway(): SocialBrokerGateway {
		$answers = &$this->answers;

		return new class(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$answers,
		) extends SocialBrokerGateway {
			/**
			 * @param ContainerInterface $container The container.
			 * @param LoggerInterface $logger The logger.
			 * @param array<string, SocialGatewayResult> $answers The scripted answers.
			 */
			public function __construct(
				ContainerInterface $container,
				LoggerInterface $logger,
				private array &$answers,
			) {
				parent::__construct(container: $container, logger: $logger);
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
				foreach ($this->answers as $prefix => $answer) {
					if (str_starts_with($path, $prefix) === true) {
						return $answer;
					}
				}

				return SocialGatewayResult::failed(
					code: SocialGatewayResult::UNAVAILABLE,
					reason: 'unscripted path: ' . $path,
				);
			}
		};
	}

	/**
	 * The service under test, over real adapters so the normalisation asserted
	 * is the one that ships.
	 *
	 * @param SocialBrokerGateway|null $gateway The gateway to use.
	 *
	 * @return SocialMetricsService The service.
	 */
	private function service(?SocialBrokerGateway $gateway = null): SocialMetricsService {
		$gateway = ($gateway ?? $this->gateway());

		$registry = $this->createMock(SocialAdapterRegistry::class);
		$registry->method('readiness')->willReturn([]);
		$registry->method('forNetwork')->willReturnCallback(
			static function (string $network) use ($gateway): ?SocialNetworkAdapter {
				return match ($network) {
					'mastodon' => new MastodonAdapter($gateway),
					'bluesky' => new BlueskyAdapter($gateway),
					'linkedin' => new LinkedInAdapter($gateway),
					'facebook' => new FacebookPageAdapter($gateway),
					default => null,
				};
			}
		);

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn(true);

		$accounts = new SocialAccountService(
			$this->store,
			$registry,
			$this->createMock(BrokerCredentialReader::class),
			$policy,
			$this->createMock(IGroupManager::class),
			$this->createMock(NotificationService::class),
		);

		$budget = $this->createMock(BudgetService::class);
		$budget->method('canSend')->willReturn(true);

		return new SocialMetricsService(
			$this->publications,
			$accounts,
			$registry,
			$gateway,
			$budget,
			$this->createMock(IAppConfig::class),
		);
	}

	/**
	 * Seed one account and one published publication on it.
	 *
	 * @param string $network The network.
	 * @param string $externalId The network's own id for the item.
	 *
	 * @return string The publication id.
	 */
	private function seedPublished(string $network, string $externalId): string {
		$accountId = 'acc-' . $network;
		$this->store->seed(
			schemaSlug: SocialAccountService::SCHEMA,
			id: $accountId,
			payload: [
				'network' => $network,
				'kind' => 'organisation',
				'handle' => '@conduction',
				'ownerUserId' => 'ruben',
				'credentialRef' => 'cred-1',
				'externalAccountId' => 'did:plc:abc',
				'active' => true,
				'followerCount' => 1000,
			],
		);

		$row = $this->publications->open(postId: 'post-1', accountId: $accountId, network: $network);
		$row['status'] = SocialPublicationStore::PUBLISHED;
		$row['externalId'] = $externalId;
		$saved = $this->publications->save(publication: $row);

		return $this->publications->idOf(publication: $saved);
	}

	/**
	 * Each network's own payload reduces to the same five numbers, and a
	 * number a network does not report stays zero.
	 *
	 * @return void
	 */
	public function testEachNetworkPayloadNormalisesToTheSameFiveNumbers(): void {
		$mastodonId = $this->seedPublished(network: 'mastodon', externalId: '109');
		$linkedinId = $this->seedPublished(network: 'linkedin', externalId: 'urn:li:share:7');

		$this->answers = [
			'/api/v1/statuses/109' => SocialGatewayResult::succeeded(status: 200, body: [
				'favourites_count' => 12,
				'reblogs_count' => 4,
				'replies_count' => 3,
			]),
			'/rest/socialActions/' => SocialGatewayResult::succeeded(status: 200, body: [
				'likesSummary' => ['totalLikes' => 30],
				'commentsSummary' => ['totalFirstLevelComments' => 5],
			]),
			'/api/v1/accounts/verify_credentials' => SocialGatewayResult::succeeded(
				status: 200,
				body: ['followers_count' => 900],
			),
		];

		$service = $this->service();
		$service->pullOne(publication: $this->publications->find(publicationId: $mastodonId));
		$service->pullOne(publication: $this->publications->find(publicationId: $linkedinId));

		$mastodon = $this->publications->find(publicationId: $mastodonId);
		$this->assertSame(
			['views' => 0, 'likes' => 12, 'comments' => 3, 'shares' => 4, 'clicks' => 0],
			$mastodon['metrics'],
		);
		$this->assertNotSame('', $mastodon['metricsAt']);

		$linkedin = $this->publications->find(publicationId: $linkedinId);
		$this->assertSame(
			['views' => 0, 'likes' => 30, 'comments' => 5, 'shares' => 0, 'clicks' => 0],
			$linkedin['metrics'],
			'LinkedIn withdrew impressions, so views stays zero rather than borrowing another number',
		);
	}

	/**
	 * The provider's own payload is kept beside the normalised numbers, so a
	 * later normalisation can be recomputed without a second pull.
	 *
	 * @return void
	 */
	public function testTheRawPayloadIsKeptBesideTheNormalisedNumbers(): void {
		$publicationId = $this->seedPublished(network: 'mastodon', externalId: '109');
		$this->answers = [
			'/api/v1/statuses/109' => SocialGatewayResult::succeeded(status: 200, body: [
				'favourites_count' => 1,
				'something_new_mastodon_added' => 42,
			]),
		];

		$this->service()->pullOne(publication: $this->publications->find(publicationId: $publicationId));

		$stored = $this->publications->find(publicationId: $publicationId);
		$this->assertSame(42, $stored['rawMetrics']['something_new_mastodon_added']);
		$this->assertSame(1, $stored['metrics']['likes']);
	}

	/**
	 * One failing pull does not stop the rest, and the failing one KEEPS its
	 * previous numbers rather than dropping to zero.
	 *
	 * @return void
	 */
	public function testOneFailingPullDoesNotStopTheRest(): void {
		$goodId = $this->seedPublished(network: 'mastodon', externalId: '109');
		$badId = $this->seedPublished(network: 'linkedin', externalId: 'urn:li:share:7');

		$stale = $this->publications->find(publicationId: $badId);
		$stale['metrics'] = ['views' => 0, 'likes' => 7, 'comments' => 0, 'shares' => 0, 'clicks' => 0];
		$this->publications->save(publication: $stale);

		$this->answers = [
			'/api/v1/statuses/109' => SocialGatewayResult::succeeded(status: 200, body: ['favourites_count' => 12]),
			'/rest/socialActions/' => SocialGatewayResult::failed(
				code: SocialGatewayResult::RELINK_NEEDED,
				reason: 'The connection has ended.',
			),
			'/api/v1/accounts/verify_credentials' => SocialGatewayResult::succeeded(
				status: 200,
				body: ['followers_count' => 900],
			),
		];

		$summary = $this->service()->pullAll();

		$this->assertSame(1, $summary['publications']);
		$this->assertSame(1, $summary['failed']);
		$this->assertSame(12, $this->publications->find(publicationId: $goodId)['metrics']['likes']);
		$this->assertSame(
			7,
			$this->publications->find(publicationId: $badId)['metrics']['likes'],
			'an unreadable pull keeps the previous numbers rather than zeroing them',
		);
		$this->assertSame(
			SocialAccountService::STATUS_RELINK_NEEDED,
			$this->store->find(SocialAccountService::SCHEMA, 'acc-linkedin')['status'],
		);
	}

	/**
	 * Follower counts are refreshed per account.
	 *
	 * @return void
	 */
	public function testFollowerCountsAreRefreshedPerAccount(): void {
		$this->seedPublished(network: 'mastodon', externalId: '109');
		$this->answers = [
			'/api/v1/accounts/verify_credentials' => SocialGatewayResult::succeeded(
				status: 200,
				body: ['followers_count' => 1234],
			),
		];

		$this->assertSame(1, $this->service()->refreshFollowerCounts());

		$account = $this->store->find(SocialAccountService::SCHEMA, 'acc-mastodon');
		$this->assertSame(1234, $account['followerCount']);
		$this->assertNotSame('', $account['followerCountAt']);
	}

	/**
	 * A network whose allow-rules reach no follower endpoint reports nothing
	 * rather than a zero that would read as a measurement.
	 *
	 * @return void
	 */
	public function testANetworkWithNoFollowerEndpointIsSkipped(): void {
		$this->seedPublished(network: 'facebook', externalId: '1_2');

		$this->assertSame(0, $this->service()->refreshFollowerCounts());
		$this->assertNull(
			(new FacebookPageAdapter($this->gateway()))->followersCall(account: ['externalAccountId' => 'p1']),
		);
	}

	/**
	 * The ranking is engagement over followers, not raw counts: 30 on 1,000
	 * outranks 40 on 4,000.
	 *
	 * @return void
	 */
	public function testTheRankingUsesEngagementRateNotRawCounts(): void {
		$small = $this->rankRow(accountId: 'acc-small', followers: 1000, likes: 30);
		$large = $this->rankRow(accountId: 'acc-large', followers: 4000, likes: 40);

		$this->assertGreaterThan($large['engagementRate'], $small['engagementRate']);
	}

	/**
	 * An account with no followers recorded shows no rate, and never an
	 * infinity that would sort to the top of every ranking it appears in.
	 *
	 * @return void
	 */
	public function testAnAccountWithNoFollowersYieldsNoRate(): void {
		$row = $this->rankRow(accountId: 'acc-none', followers: 0, likes: 5);

		$this->assertNull($row['engagementRate']);
		$this->assertSame(5, $row['engagement']);
	}

	/**
	 * A publication with no numbers yet sorts LAST rather than disappearing:
	 * it was published, it just has nothing to say.
	 *
	 * @return void
	 */
	public function testAPublicationWithNoNumbersSortsLastRatherThanVanishing(): void {
		$this->seedPublished(network: 'mastodon', externalId: '109');
		$measured = $this->publications->forPost(postId: 'post-1')[0];
		$measured['metrics'] = ['views' => 0, 'likes' => 50, 'comments' => 0, 'shares' => 0, 'clicks' => 0];
		$this->publications->save(publication: $measured);

		$this->store->seed(
			schemaSlug: SocialAccountService::SCHEMA,
			id: 'acc-quiet',
			payload: ['network' => 'mastodon', 'active' => true, 'followerCount' => 100],
		);
		$this->publications->open(postId: 'post-2', accountId: 'acc-quiet', network: 'mastodon');

		$ranking = $this->service()->ranking();

		$this->assertCount(2, $ranking);
		$this->assertSame('acc-mastodon', $ranking[0]['accountId']);
		$this->assertSame('acc-quiet', $ranking[1]['accountId']);
	}

	/**
	 * One ranking row for a made-up publication and follower count.
	 *
	 * @param string $accountId The account.
	 * @param int $followers Its followers.
	 * @param int $likes The likes on the publication.
	 *
	 * @return array<string, mixed> The ranking row.
	 */
	private function rankRow(string $accountId, int $followers, int $likes): array {
		return $this->service()->rankRow(
			publication: [
				'id' => 'pub-' . $accountId,
				'accountId' => $accountId,
				'network' => 'mastodon',
				'metrics' => ['views' => 0, 'likes' => $likes, 'comments' => 0, 'shares' => 0, 'clicks' => 0],
			],
			followers: [$accountId => $followers],
		);
	}
}//end class
