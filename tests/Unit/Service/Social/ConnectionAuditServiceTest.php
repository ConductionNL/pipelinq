<?php

/**
 * Tests for ConnectionAuditService.
 *
 * The value of this audit is entirely in its third answer. A network that will
 * not say must come back as `unknown` with a reason, never as `no`: a `no` is
 * something a marketer acts on, and it would be wrong roughly half the time.
 * Every test here is about that boundary.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Social
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Competitor\FediverseWatchReader;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Social\ConnectionAuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Social\ConnectionAuditService
 * @uses \OCA\Pipelinq\Service\Egress\EgressResult
 */
class ConnectionAuditServiceTest extends TestCase {

	/**
	 * The mocked store.
	 *
	 * @var ListObjectStore&MockObject
	 */
	private ListObjectStore $store;

	/**
	 * The mocked egress seam.
	 *
	 * @var ConnectorEgress&MockObject
	 */
	private ConnectorEgress $egress;

	/**
	 * The service under test.
	 *
	 * @var ConnectionAuditService
	 */
	private ConnectionAuditService $audit;

	/**
	 * Build the service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(ListObjectStore::class);
		$this->store->method('idOf')->willReturnCallback(
			static function (?array $payload): string {
				return (string)($payload['id'] ?? '');
			}
		);
		$this->store->method('schemaSlug')->willReturnCallback(
			static function (string $configKey, string $default): string {
				return $default;
			}
		);
		$this->egress = $this->createMock(ConnectorEgress::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1788600000);

		$this->audit = new ConnectionAuditService(store: $this->store, egress: $this->egress, time: $time);
	}//end setUp()

	/**
	 * A LinkedIn pair is unknown in both directions, with a reason naming
	 * why, and never `no`.
	 *
	 * @return void
	 */
	public function testLinkedInIsUnknownWithAReason(): void {
		$row = $this->audit->audit(
			account: ['id' => 'acct-1', 'network' => 'linkedin', 'handle' => 'conduction'],
			client: ['id' => 'client-1'],
			profile: ['network' => 'linkedin', 'handle' => 'voorbeeld-bv', 'url' => 'https://www.linkedin.com/company/voorbeeld-bv']
		);

		$this->assertSame('unknown', $row['weFollowThem']);
		$this->assertSame('unknown', $row['theyFollowUs']);
		$this->assertSame(ConnectionAuditService::UNANSWERABLE['linkedin'], $row['reason']);
	}//end testLinkedInIsUnknownWithAReason()

	/**
	 * No unanswerable network ever reports a `no`.
	 *
	 * @return void
	 */
	public function testNeverReportsFalseForAnUnanswerableNetwork(): void {
		foreach (array_keys(ConnectionAuditService::UNANSWERABLE) as $network) {
			$row = $this->audit->audit(
				account: ['id' => 'acct-1', 'network' => $network, 'handle' => 'ons'],
				client: ['id' => 'client-1'],
				profile: ['network' => $network, 'handle' => 'hun', 'url' => '']
			);

			$this->assertSame('unknown', $row['weFollowThem'], $network);
			$this->assertSame('unknown', $row['theyFollowUs'], $network);
			$this->assertNotSame('', $row['reason'], $network);
		}
	}//end testNeverReportsFalseForAnUnanswerableNetwork()

	/**
	 * The answerable set is exactly the two networks with a public graph.
	 *
	 * @return void
	 */
	public function testOnlyMastodonAndBlueskyAreAnswerable(): void {
		$this->assertSame(['mastodon', 'bluesky'], ConnectionAuditService::ANSWERABLE);
		foreach (ConnectionAuditService::ANSWERABLE as $network) {
			$this->assertArrayNotHasKey($network, ConnectionAuditService::UNANSWERABLE);
		}
	}//end testOnlyMastodonAndBlueskyAreAnswerable()

	/**
	 * A Mastodon instance that refuses its lists answers unknown with its
	 * own reason, on the same path a network without an API takes. That is
	 * why the reason is a per-row value.
	 *
	 * @return void
	 */
	public function testAHiddenMastodonListIsUnknownWithItsOwnReason(): void {
		$this->egress->method('readUrl')->willReturn(
			EgressResult::failed(failure: EgressResult::REFUSED, reason: 'answered 401', status: 401)
		);

		$row = $this->audit->audit(
			account: ['id' => 'acct-1', 'network' => 'mastodon', 'handle' => 'conduction@mastodon.nl'],
			client: ['id' => 'client-1'],
			profile: ['network' => 'mastodon', 'handle' => 'voorbeeld@mastodon.example.org', 'url' => '']
		);

		$this->assertSame('unknown', $row['weFollowThem']);
		$this->assertStringContainsString('mastodon.nl', $row['reason']);
		$this->assertNotSame(ConnectionAuditService::UNANSWERABLE['linkedin'] ?? '', $row['reason']);
	}//end testAHiddenMastodonListIsUnknownWithItsOwnReason()

	/**
	 * A readable Mastodon graph answers yes and no, which is the only place
	 * a `no` may come from.
	 *
	 * @return void
	 */
	public function testAReadableMastodonGraphAnswersYesAndNo(): void {
		$this->egress->method('readUrl')->willReturnCallback(
			static function (string $configKey, string $url): EgressResult {
				if (str_contains($url, '/lookup') === true) {
					return EgressResult::success(body: '{"id":"42","acct":"conduction"}');
				}

				if (str_contains($url, '/following') === true) {
					return EgressResult::success(body: '[{"acct":"voorbeeld@mastodon.example.org"}]');
				}

				return EgressResult::success(body: '[]');
			}
		);

		$row = $this->audit->audit(
			account: ['id' => 'acct-1', 'network' => 'mastodon', 'handle' => 'conduction@mastodon.nl'],
			client: ['id' => 'client-1'],
			profile: ['network' => 'mastodon', 'handle' => 'voorbeeld@mastodon.example.org', 'url' => '']
		);

		$this->assertSame('yes', $row['weFollowThem']);
		$this->assertSame('no', $row['theyFollowUs']);
		$this->assertSame('', $row['reason']);
	}//end testAReadableMastodonGraphAnswersYesAndNo()

	/**
	 * A client with no social profile produces no row at all.
	 *
	 * @return void
	 */
	public function testAClientWithNoHandleProducesNoRow(): void {
		$this->store->method('findAll')->willReturnCallback(
			static function (string $schemaSlug, array $filters = []): array {
				if ($schemaSlug === 'socialAccount') {
					return [['id' => 'acct-1', 'network' => 'linkedin', 'handle' => 'conduction']];
				}

				if ($schemaSlug === 'client') {
					return [['id' => 'client-1', 'name' => 'Zonder handles']];
				}

				return [];
			}
		);
		$this->store->expects($this->never())->method('save');

		$summary = $this->audit->run();

		$this->assertSame(0, $summary['pairs']);
	}//end testAClientWithNoHandleProducesNoRow()

	/**
	 * A client whose network we have not connected produces no row either:
	 * there is nothing to look from.
	 *
	 * @return void
	 */
	public function testAHandleOnAnUnconnectedNetworkProducesNoRow(): void {
		$this->store->method('findAll')->willReturnCallback(
			static function (string $schemaSlug, array $filters = []): array {
				if ($schemaSlug === 'socialAccount') {
					return [['id' => 'acct-1', 'network' => 'mastodon', 'handle' => 'conduction@mastodon.nl']];
				}

				if ($schemaSlug === 'client') {
					return [
						[
							'id' => 'client-1',
							'socialProfiles' => [['network' => 'linkedin', 'handle' => 'voorbeeld-bv']],
						],
					];
				}

				return [];
			}
		);
		$this->store->expects($this->never())->method('save');

		$this->assertSame(0, $this->audit->run()['pairs']);
	}//end testAHandleOnAnUnconnectedNetworkProducesNoRow()

	/**
	 * A pair that can be audited is stored, and the summary counts it as
	 * unanswered when the network will not say.
	 *
	 * @return void
	 */
	public function testAnAuditedPairIsStoredAndCounted(): void {
		$this->store->method('findAll')->willReturnCallback(
			static function (string $schemaSlug, array $filters = []): array {
				if ($schemaSlug === 'socialAccount') {
					return [['id' => 'acct-1', 'network' => 'linkedin', 'handle' => 'conduction']];
				}

				if ($schemaSlug === 'client') {
					return [
						[
							'id' => 'client-1',
							'socialProfiles' => [['network' => 'linkedin', 'handle' => 'voorbeeld-bv', 'url' => '']],
						],
					];
				}

				return [];
			}
		);
		$this->store->expects($this->once())->method('save');

		$summary = $this->audit->run();

		$this->assertSame(1, $summary['pairs']);
		$this->assertSame(1, $summary['unknown']);
		$this->assertSame(0, $summary['answered']);
	}//end testAnAuditedPairIsStoredAndCounted()

	/**
	 * A Bluesky pair: both lists read from the AppView with their method and
	 * query, handles matched case-insensitively; and the reasons when one or
	 * both lists cannot be read, or the account has no handle.
	 *
	 * @return void
	 */
	public function testABlueskyPairAndItsReasons(): void {
		$calls = [];
		$answers = [
			'getFollows' => EgressResult::success(body: '{"follows":[{"handle":"Voorbeeld.bsky.social"},"x",{"handle":""}]}'),
			'getFollowers' => EgressResult::success(body: '{"followers":"none"}'),
		];
		$this->egress->method('readUrl')->willReturnCallback(
			static function (string $configKey, string $url, array $config = []) use (&$calls, &$answers): EgressResult {
				$calls[] = [$configKey, $url, $config];
				return $answers[substr($url, (strrpos($url, '.') + 1))];
			}
		);
		$pair = static fn (ConnectionAuditService $audit, string $handle): array => $audit->audit(
			account: ['id' => 'acct-b', 'network' => 'bluesky', 'handle' => $handle],
			client: ['id' => 'client-1'],
			profile: ['network' => 'bluesky', 'handle' => '@voorbeeld.bsky.social', 'url' => 'https://bsky.app/profile/voorbeeld']
		);

		$row = $pair($this->audit, ' @conduction.nl ');
		$this->assertSame(['yes', 'unknown', 'The Bluesky AppView did not answer the followers list.'], [$row['weFollowThem'], $row['theyFollowUs'], $row['reason']]);
		$this->assertSame(
			[
				[CompetitorWatchService::SOURCE_KEY, FediverseWatchReader::BLUESKY_APPVIEW . '/xrpc/app.bsky.graph.getFollows', ['query' => ['actor' => 'conduction.nl', 'limit' => '200']]],
				[CompetitorWatchService::SOURCE_KEY, FediverseWatchReader::BLUESKY_APPVIEW . '/xrpc/app.bsky.graph.getFollowers', ['query' => ['actor' => 'conduction.nl', 'limit' => '200']]],
			],
			$calls
		);
		$this->assertSame(['accountId' => 'acct-b', 'network' => 'bluesky', 'clientId' => 'client-1', 'counterpartHandle' => '@voorbeeld.bsky.social', 'counterpartUrl' => 'https://bsky.app/profile/voorbeeld'], array_slice($row, 0, 5));
		$this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1788600000), $row['seenAt']);

		$answers['getFollows'] = EgressResult::failed(failure: EgressResult::REFUSED, reason: 'no', status: 500);
		$row = $pair($this->audit, 'conduction.nl');
		$this->assertSame(['unknown', 'unknown', 'The Bluesky AppView did not answer the following list.'], [$row['weFollowThem'], $row['theyFollowUs'], $row['reason']]);

		$answers['getFollowers'] = EgressResult::success(body: '{"followers":[{"handle":"other.bsky.social"}]}');
		$row = $pair($this->audit, 'conduction.nl');
		$this->assertSame(['unknown', 'no', 'The Bluesky AppView did not answer the following list.'], [$row['weFollowThem'], $row['theyFollowUs'], $row['reason']]);

		$calls = [];
		$row = $pair($this->audit, '  ');
		$this->assertSame(['unknown', 'unknown', 'The connected Bluesky account has no handle.'], [$row['weFollowThem'], $row['theyFollowUs'], $row['reason']]);
		$this->assertSame([], $calls);
	}//end testABlueskyPairAndItsReasons()

	/**
	 * Mastodon: a handle without an instance and a failed lookup each name their reason.
	 *
	 * @return void
	 */
	public function testMastodonLookupReasons(): void {
		$calls = [];
		$this->egress->method('readUrl')->willReturnCallback(
			static function (string $configKey, string $url, array $config = []) use (&$calls): EgressResult {
				$calls[] = [$url, $config];
				return EgressResult::success(body: '{"acct":"conduction"}');
			}
		);
		$pair = fn (string $handle): array => $this->audit->audit(
			account: ['id' => 'acct-1', 'network' => 'mastodon', 'handle' => $handle],
			client: ['id' => 'client-1'],
			profile: ['network' => 'mastodon', 'handle' => 'v@x.org', 'url' => '']
		);

		$this->assertSame('The connected Mastodon handle does not name an instance.', $pair('@conduction')['reason']);
		$this->assertSame([], $calls);
		$this->assertSame('The instance mastodon.nl did not answer the account lookup.', $pair(' @conduction@mastodon.nl')['reason']);
		$this->assertSame([['https://mastodon.nl/api/v1/accounts/lookup', ['query' => ['acct' => 'conduction@mastodon.nl']]], ['https://mastodon.nl/api/v1/accounts/lookup', ['query' => ['acct' => 'conduction@mastodon.nl']]]], $calls);
	}//end testMastodonLookupReasons()
}//end class
