<?php

/**
 * Pipelinq ConnectionAuditService.
 *
 * Joins the accounts phase 3 connected to the handles a client carries, and
 * answers two questions per pair: do we follow them, and do they follow us.
 *
 * THE THIRD ANSWER IS THE POINT. Only Mastodon and Bluesky publish a follower
 * and following list a reader may fetch. LinkedIn exposes follower counts, not
 * a list an audit can search; X puts the lookup behind a paid tier this fleet
 * does not buy; Meta exposes no follower list for a page or a business account
 * at all. For those, this service answers `unknown` with the reason, and never
 * `no`. A `no` is something a marketer acts on, and it would be wrong roughly
 * half the time.
 *
 * The reason is stored per ROW rather than looked up per network, because a
 * Mastodon instance that has hidden its lists answers `unknown` on exactly the
 * same path a network without an API does, and the marketer needs to be able
 * to tell those two apart.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Social
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Competitor\FediverseWatchReader;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Who follows whom, and where the question cannot be answered.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is the
 *  three-valued answer itself: every read has a yes, a no and a reason it
 *  could not say, and each of the two readable networks has its own shape
 *  for all three. Collapsing any of those branches is exactly the defect
 *  this class exists to prevent, because a network that will not answer
 *  would then be recorded as a no.
 */
class ConnectionAuditService {

	/**
	 * The `socialConnection` schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'socialConnection';

	/**
	 * The networks whose follower and following lists can be read.
	 *
	 * @var array<int, string>
	 */
	public const ANSWERABLE = ['mastodon', 'bluesky'];

	/**
	 * Why each other network cannot be answered. These are rendered on the
	 * page verbatim, so they say what is true rather than "not supported".
	 *
	 * @var array<string, string>
	 */
	public const UNANSWERABLE = [
		'linkedin' => 'LinkedIn exposes follower counts, not a list an audit can search.',
		'x' => 'X puts follower lookup behind a paid API tier this instance does not use.',
		'facebook' => 'Meta exposes no follower list for a page.',
		'instagram' => 'Meta exposes no follower list for a business account.',
		'threads' => 'Threads exposes no follower list.',
	];

	/**
	 * Follows read from one account, at most.
	 *
	 * @var int
	 */
	public const MAX_FOLLOWS = 200;

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 * @param ConnectorEgress $egress The single outbound seam.
	 * @param ITimeFactory $time Time factory for the audit stamp.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
	 */
	public function __construct(
		private ListObjectStore $store,
		private ConnectorEgress $egress,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The stored audit rows.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-page-reads-one-collection-and-renders-the-reasons
	 */
	public function rows(): array {
		return $this->store->findAll(schemaSlug: $this->schemaSlug());
	}//end rows()

	/**
	 * Re-run the audit and store one row per client and network.
	 *
	 * @return array{pairs: int, answered: int, unknown: int}
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
	 */
	public function run(): array {
		$accounts = $this->accountsByNetwork();
		$summary = ['pairs' => 0, 'answered' => 0, 'unknown' => 0];
		foreach ($this->store->findAll(schemaSlug: 'client') as $client) {
			foreach ($this->handlesOf(client: $client) as $profile) {
				$network = (string)$profile['network'];
				$account = ($accounts[$network] ?? null);
				if ($account === null) {
					continue;
				}

				$row = $this->audit(account: $account, client: $client, profile: $profile);
				$this->persist(row: $row);
				$summary['pairs']++;
				if ($row['weFollowThem'] === 'unknown' && $row['theyFollowUs'] === 'unknown') {
					$summary['unknown']++;
					continue;
				}

				$summary['answered']++;
			}
		}

		return $summary;
	}//end run()

	/**
	 * Audit one pair.
	 *
	 * @param array<string, mixed> $account Our connected account.
	 * @param array<string, mixed> $client The client.
	 * @param array{network: string, handle: string, url: string} $profile Their profile.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
	 */
	public function audit(array $account, array $client, array $profile): array {
		$network = $profile['network'];
		$row = [
			'accountId' => $this->store->idOf(payload: $account),
			'network' => $network,
			'clientId' => $this->store->idOf(payload: $client),
			'counterpartHandle' => $profile['handle'],
			'counterpartUrl' => $profile['url'],
			'weFollowThem' => 'unknown',
			'theyFollowUs' => 'unknown',
			'reason' => (self::UNANSWERABLE[$network] ?? 'This network does not publish a follower list.'),
			'seenAt' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime()),
		];

		if (in_array($network, self::ANSWERABLE, true) === false) {
			return $row;
		}

		$following = $this->listOf(network: $network, account: $account, direction: 'following');
		$followers = $this->listOf(network: $network, account: $account, direction: 'followers');
		if ($following['readable'] === false && $followers['readable'] === false) {
			$row['reason'] = $following['reason'];
			return $row;
		}

		$handle = mb_strtolower(ltrim($profile['handle'], '@'), 'UTF-8');
		$row['reason'] = '';
		$row['weFollowThem'] = $this->verdict(list: $following, handle: $handle);
		$row['theyFollowUs'] = $this->verdict(list: $followers, handle: $handle);
		if ($row['weFollowThem'] === 'unknown') {
			$row['reason'] = $following['reason'];
		}

		if ($row['theyFollowUs'] === 'unknown' && $row['reason'] === '') {
			$row['reason'] = $followers['reason'];
		}

		return $row;
	}//end audit()

	/**
	 * Yes, no or unknown, from a list that may not have been readable.
	 *
	 * @param array{readable: bool, handles: array<int, string>, reason: string} $list The list.
	 * @param string $handle The handle to look for, lowercase and without the at sign.
	 *
	 * @return string `yes`, `no` or `unknown`.
	 */
	private function verdict(array $list, string $handle): string {
		if ($list['readable'] === false) {
			return 'unknown';
		}

		if (in_array($handle, $list['handles'], true) === true) {
			return 'yes';
		}

		return 'no';
	}//end verdict()

	/**
	 * Read one direction of one account's graph.
	 *
	 * @param string $network `mastodon` or `bluesky`.
	 * @param array<string, mixed> $account Our account.
	 * @param string $direction `following` or `followers`.
	 *
	 * @return array{readable: bool, handles: array<int, string>, reason: string}
	 */
	private function listOf(string $network, array $account, string $direction): array {
		if ($network === 'bluesky') {
			return $this->blueskyList(account: $account, direction: $direction);
		}

		return $this->mastodonList(account: $account, direction: $direction);
	}//end listOf()

	/**
	 * A Mastodon account's following or followers, if the instance says.
	 *
	 * @param array<string, mixed> $account Our account.
	 * @param string $direction `following` or `followers`.
	 *
	 * @return array{readable: bool, handles: array<int, string>, reason: string}
	 */
	private function mastodonList(array $account, string $direction): array {
		$handle = ltrim(trim((string)($account['handle'] ?? '')), '@');
		if (str_contains($handle, '@') === false) {
			return ['readable' => false, 'handles' => [], 'reason' => 'The connected Mastodon handle does not name an instance.'];
		}

		[$user, $instance] = explode('@', $handle, 2);
		$base = ('https://' . $instance);
		$lookup = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: ($base . '/api/v1/accounts/lookup'),
			config: ['query' => ['acct' => ($user . '@' . $instance)]]
		);
		$id = trim((string)(($lookup->json() ?? [])['id'] ?? ''));
		if ($lookup->succeeded === false || $id === '') {
			return ['readable' => false, 'handles' => [], 'reason' => 'The instance ' . $instance . ' did not answer the account lookup.'];
		}

		$list = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: ($base . '/api/v1/accounts/' . rawurlencode($id) . '/' . $direction),
			config: ['query' => ['limit' => (string)self::MAX_FOLLOWS]]
		);
		$rows = $list->json();
		if ($list->succeeded === false || $rows === null) {
			return ['readable' => false, 'handles' => [], 'reason' => 'The instance ' . $instance . ' does not publish this list.'];
		}

		$handles = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$acct = mb_strtolower(trim((string)($row['acct'] ?? '')), 'UTF-8');
			if ($acct !== '') {
				$handles[] = $acct;
			}
		}

		return ['readable' => true, 'handles' => $handles, 'reason' => ''];
	}//end mastodonList()

	/**
	 * A Bluesky account's follows or followers from the public AppView.
	 *
	 * @param array<string, mixed> $account Our account.
	 * @param string $direction `following` or `followers`.
	 *
	 * @return array{readable: bool, handles: array<int, string>, reason: string}
	 */
	private function blueskyList(array $account, string $direction): array {
		$handle = ltrim(trim((string)($account['handle'] ?? '')), '@');
		if ($handle === '') {
			return ['readable' => false, 'handles' => [], 'reason' => 'The connected Bluesky account has no handle.'];
		}

		$method = 'app.bsky.graph.getFollows';
		$key = 'follows';
		if ($direction === 'followers') {
			$method = 'app.bsky.graph.getFollowers';
			$key = 'followers';
		}

		$list = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: (FediverseWatchReader::BLUESKY_APPVIEW . '/xrpc/' . $method),
			config: ['query' => ['actor' => $handle, 'limit' => (string)self::MAX_FOLLOWS]]
		);
		$decoded = $list->json();
		if ($list->succeeded === false || is_array($decoded[$key] ?? null) === false) {
			return ['readable' => false, 'handles' => [], 'reason' => 'The Bluesky AppView did not answer the ' . $direction . ' list.'];
		}

		$handles = [];
		foreach ((array)$decoded[$key] as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$actor = mb_strtolower(trim((string)($row['handle'] ?? '')), 'UTF-8');
			if ($actor !== '') {
				$handles[] = $actor;
			}
		}

		return ['readable' => true, 'handles' => $handles, 'reason' => ''];
	}//end blueskyList()

	/**
	 * The connected accounts, one per network. When a tenant has several on
	 * one network the first is used; the audit is about the organisation's
	 * relationship, not about which of two accounts happens to follow.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function accountsByNetwork(): array {
		$out = [];
		foreach ($this->store->findAll(schemaSlug: 'socialAccount') as $account) {
			$network = trim((string)($account['network'] ?? ''));
			if ($network !== '' && array_key_exists($network, $out) === false) {
				$out[$network] = $account;
			}
		}

		return $out;
	}//end accountsByNetwork()

	/**
	 * The social handles a client carries.
	 *
	 * @param array<string, mixed> $client The client.
	 *
	 * @return array<int, array{network: string, handle: string, url: string}>
	 */
	private function handlesOf(array $client): array {
		$profiles = ($client['socialProfiles'] ?? []);
		if (is_array($profiles) === false) {
			return [];
		}

		$out = [];
		foreach ($profiles as $profile) {
			if (is_array($profile) === false) {
				continue;
			}

			$handle = trim((string)($profile['handle'] ?? ''));
			$network = trim((string)($profile['network'] ?? ''));
			if ($handle === '' || $network === '') {
				continue;
			}

			$out[] = ['network' => $network, 'handle' => $handle, 'url' => trim((string)($profile['url'] ?? ''))];
		}

		return $out;
	}//end handlesOf()

	/**
	 * Store one row, replacing the previous answer for the same pair.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return void
	 */
	private function persist(array $row): void {
		$existing = $this->store->findAll(
			schemaSlug: $this->schemaSlug(),
			filters: ['network' => (string)$row['network'], 'counterpartHandle' => (string)$row['counterpartHandle']]
		);
		$id = null;
		foreach ($existing as $found) {
			$id = $this->store->idOf(payload: $found);
			break;
		}

		$this->store->save(schemaSlug: $this->schemaSlug(), payload: $row, id: $id);
	}//end persist()

	/**
	 * The schema slug in use.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
	 */
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: 'socialConnection_schema', default: self::SCHEMA_SLUG);
	}//end schemaSlug()
}//end class
