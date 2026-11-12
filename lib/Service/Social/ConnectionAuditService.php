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
 * @spec openspec/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
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
 * @spec openspec/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
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
	 * The AppView method and answer key of each Bluesky list direction.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const BLUESKY_LISTS = [
		'following' => ['app.bsky.graph.getFollows', 'follows'],
		'followers' => ['app.bsky.graph.getFollowers', 'followers'],
	];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 * @param ConnectorEgress $egress The single outbound seam.
	 * @param ITimeFactory $time Time factory for the audit stamp.
	 *
	 * @spec openspec/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
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
	 * @spec openspec/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-page-reads-one-collection-and-renders-the-reasons
	 */
	public function rows(): array {
		return $this->store->findAll(schemaSlug: $this->schemaSlug());
	}//end rows()

	/**
	 * Re-run the audit and store one row per client and network.
	 *
	 * @return array{pairs: int, answered: int, unknown: int}
	 *
	 * @spec openspec/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
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
	 * @spec openspec/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
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

		return $this->withFollowVerdicts(row: $row, account: $account);
	}//end audit()

	/**
	 * The row with both follow verdicts from our account's graph, and the
	 * reason of the first list that could not be read.
	 *
	 * @param array<string, mixed> $row     The row so far (network and counterpart handle).
	 * @param array<string, mixed> $account Our account.
	 *
	 * @return array<string, mixed>
	 */
	private function withFollowVerdicts(array $row, array $account): array {
		$following = $this->listOf(network: $row['network'], account: $account, direction: 'following');
		$followers = $this->listOf(network: $row['network'], account: $account, direction: 'followers');
		if ($following['readable'] === false && $followers['readable'] === false) {
			$row['reason'] = $following['reason'];
			return $row;
		}

		$handle = mb_strtolower(ltrim($row['counterpartHandle'], '@'), 'UTF-8');
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
	}//end withFollowVerdicts()

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
			return self::unreadable(reason: 'The connected Mastodon handle does not name an instance.');
		}

		[$user, $instance] = explode('@', $handle, 2);
		$id = $this->mastodonAccountId(user: $user, instance: $instance);
		if ($id === '') {
			return self::unreadable(reason: 'The instance ' . $instance . ' did not answer the account lookup.');
		}

		$list = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: ('https://' . $instance . '/api/v1/accounts/' . rawurlencode($id) . '/' . $direction),
			config: ['query' => ['limit' => (string)self::MAX_FOLLOWS]]
		);
		$rows = $list->json();
		if ($list->succeeded === false || $rows === null) {
			return self::unreadable(reason: 'The instance ' . $instance . ' does not publish this list.');
		}

		return ['readable' => true, 'handles' => self::lowerHandles(rows: $rows, field: 'acct'), 'reason' => ''];
	}//end mastodonList()

	/**
	 * The instance's id of our Mastodon account, '' when the lookup failed.
	 *
	 * @param string $user     The user part of the handle.
	 * @param string $instance The instance host.
	 *
	 * @return string
	 */
	private function mastodonAccountId(string $user, string $instance): string {
		$lookup = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: ('https://' . $instance . '/api/v1/accounts/lookup'),
			config: ['query' => ['acct' => ($user . '@' . $instance)]]
		);
		$id = trim((string)(($lookup->json() ?? [])['id'] ?? ''));
		if ($lookup->succeeded === false) {
			return '';
		}

		return $id;
	}//end mastodonAccountId()

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
			return self::unreadable(reason: 'The connected Bluesky account has no handle.');
		}

		[$method, $key] = (self::BLUESKY_LISTS[$direction] ?? self::BLUESKY_LISTS['following']);
		$list = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: (FediverseWatchReader::BLUESKY_APPVIEW . '/xrpc/' . $method),
			config: ['query' => ['actor' => $handle, 'limit' => (string)self::MAX_FOLLOWS]]
		);
		$decoded = $list->json();
		if ($list->succeeded === false || is_array($decoded[$key] ?? null) === false) {
			return self::unreadable(reason: 'The Bluesky AppView did not answer the ' . $direction . ' list.');
		}

		return ['readable' => true, 'handles' => self::lowerHandles(rows: (array)$decoded[$key], field: 'handle'), 'reason' => ''];
	}//end blueskyList()

	/**
	 * A list that could not be read, with the reason.
	 *
	 * @param string $reason Why.
	 *
	 * @return array{readable: bool, handles: array<int, string>, reason: string}
	 */
	private static function unreadable(string $reason): array {
		return ['readable' => false, 'handles' => [], 'reason' => $reason];
	}//end unreadable()

	/**
	 * The non-empty handles of a list, lowercased; rows that are not maps are skipped.
	 *
	 * @param array<int|string, mixed> $rows  The list rows.
	 * @param string                   $field The row field holding the handle.
	 *
	 * @return array<int, string>
	 */
	private static function lowerHandles(array $rows, string $field): array {
		$handles = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$handle = mb_strtolower(trim((string)($row[$field] ?? '')), 'UTF-8');
			if ($handle !== '') {
				$handles[] = $handle;
			}
		}

		return $handles;
	}//end lowerHandles()

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
	 * @spec openspec/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say
	 */
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: 'socialConnection_schema', default: self::SCHEMA_SLUG);
	}//end schemaSlug()
}//end class
