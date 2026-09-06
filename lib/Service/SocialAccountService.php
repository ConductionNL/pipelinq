<?php

/**
 * Pipelinq SocialAccountService.
 *
 * Connecting, reconnecting and revoking a social account, and the one guard
 * that says who may act on one.
 *
 * The connect flow is deliberately split across the browser and this service,
 * and the split is where the security lives. This service answers WHAT to
 * connect (the broker provider, the scopes, the app grant, where to come back
 * to) and refuses when the network has no developer application filed. The
 * browser then posts that to OpenRegister's own connect endpoint with the
 * user's own session, walks the network's consent screen, and comes back. The
 * authorization code, the token and the refresh token never pass through
 * Pipelinq at any point in that journey; the only thing that lands here
 * afterwards is a credential UUID.
 *
 * {@see attachCredential()} is the one method a client can push a payload
 * into, so it takes exactly one field out of it and re-reads everything else
 * from the broker. Handing it a token, a refresh token or a client secret
 * changes nothing, which is asserted rather than asserted-in-a-comment.
 *
 * WHO MAY ACT ON AN ACCOUNT is not `ObjectOwnerAccessPolicy::mayAccess()`.
 * That policy admits the `sales` group on any owned object, which is right for
 * a contract and wrong for a colleague's own LinkedIn profile: it would let a
 * co-worker post as them. A `person` account admits its owner and a Nextcloud
 * administrator, and nobody else.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCP\IGroupManager;

/**
 * The social account lifecycle and its ownership boundary.
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Connect, reconnect, attach,
 *  revoke, status sync and the ownership guard are one account lifecycle;
 *  splitting them would put the guard somewhere the lifecycle has to reach
 *  across for, which is how a guard gets skipped.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same reason: the lifecycle
 *  is several independently-simple steps, each of which is a short method, and
 *  the complexity is the sum rather than any one tangle.
 */
class SocialAccountService {
	/**
	 * The account schema slug, matching the register fragment.
	 *
	 * @var string
	 */
	public const SCHEMA = 'socialAccount';

	/**
	 * App-config key that may override the schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'social_account_schema';

	/**
	 * The status of a connection nobody has completed yet.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * The status of a working connection.
	 *
	 * @var string
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * The status of a connection whose grant has ended.
	 *
	 * @var string
	 */
	public const STATUS_RELINK_NEEDED = 'relink_needed';

	/**
	 * The status of a connection somebody revoked.
	 *
	 * @var string
	 */
	public const STATUS_DISABLED = 'disabled';

	/**
	 * The status of an account on a network with nothing filed.
	 *
	 * @var string
	 */
	public const STATUS_NOT_CONFIGURED = 'not_configured';

	/**
	 * A personal account: its owner and an administrator, and nobody else.
	 *
	 * @var string
	 */
	public const KIND_PERSON = 'person';

	/**
	 * The notification subject sent to an account's owner when its grant dies.
	 *
	 * @var string
	 */
	public const SUBJECT_RELINK_NEEDED = 'social_relink_needed';

	/**
	 * Fields a client may set when creating or updating an account. Everything
	 * else, and `credentialRef` above all, is written by this service.
	 *
	 * @var array<int, string>
	 */
	public const CLIENT_WRITABLE = [
		'network',
		'kind',
		'handle',
		'displayName',
		'profileUrl',
		'externalAccountId',
		'instanceBaseUrl',
		'clientId',
		'publishMode',
	];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store The register-scoped object plumbing.
	 * @param SocialAdapterRegistry $registry The network adapters and their readiness.
	 * @param BrokerCredentialReader $credentials Metadata-only reads of a brokered credential.
	 * @param ObjectOwnerAccessPolicy $policy Owner-or-privileged policy, for company accounts.
	 * @param IGroupManager $groupManager Group manager, for the administrator half of the personal guard.
	 * @param NotificationService $notifications Nextcloud notifications, for the reconnect prompt.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListObjectStore $store,
		private readonly SocialAdapterRegistry $registry,
		private readonly BrokerCredentialReader $credentials,
		private readonly ObjectOwnerAccessPolicy $policy,
		private readonly IGroupManager $groupManager,
		private readonly NotificationService $notifications,
	) {
	}//end __construct()

	/**
	 * Every account, with each network's readiness.
	 *
	 * @return array{data: array<int, array<string, mixed>>, readiness: array<string, array{state: string, reason: string}>}
	 *         The accounts and the per-network readiness.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function listAccounts(): array {
		return [
			'data' => $this->store->findAll(schemaSlug: $this->schema()),
			'readiness' => $this->registry->readiness(),
		];
	}//end listAccounts()

	/**
	 * Only the accounts the composer may pick and the daily pull should read.
	 *
	 * A revoked account keeps its row so the publications that already went out
	 * still name it, which is exactly why "every account" and "every usable
	 * account" are two different questions and two different methods.
	 *
	 * @return array<int, array<string, mixed>> The active accounts.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function activeAccounts(): array {
		$out = [];
		foreach ($this->store->findAll(schemaSlug: $this->schema()) as $row) {
			if (($row['active'] ?? true) !== true) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}//end activeAccounts()

	/**
	 * One account, or null.
	 *
	 * @param string $accountId The account UUID or slug.
	 *
	 * @return array<string, mixed>|null The account, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function getAccount(string $accountId): ?array {
		return $this->store->find(schemaSlug: $this->schema(), id: $accountId);
	}//end getAccount()

	/**
	 * Whether this user may publish as, reconnect or revoke this account.
	 *
	 * A `person` account admits its owner and a Nextcloud administrator. An
	 * `organisation` account admits anyone the app's own privileged-group
	 * policy admits, which is the company's marketers.
	 *
	 * @param string $uid The caller.
	 * @param array<string, mixed> $account The account.
	 *
	 * @return bool True when the caller may act on it.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-personal-account-belongs-to-the-person-who-connected-it
	 */
	public function mayActOn(string $uid, array $account): bool {
		if (trim($uid) === '') {
			return false;
		}

		if ((string)($account['kind'] ?? '') !== self::KIND_PERSON) {
			return $this->policy->isPrivileged(uid: $uid);
		}

		if (trim((string)($account['ownerUserId'] ?? '')) === $uid) {
			return true;
		}

		return $this->groupManager->isAdmin($uid);
	}//end mayActOn()

	/**
	 * Create an account row, before anything is connected.
	 *
	 * @param array<string, mixed> $payload The client's fields.
	 * @param string $uid The user creating it, stamped as the owner.
	 *
	 * @return array{error?: string, account?: array<string, mixed>} The created account, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-personal-account-belongs-to-the-person-who-connected-it
	 */
	public function createAccount(array $payload, string $uid): array {
		$network = trim((string)($payload['network'] ?? ''));
		if ($this->registry->forNetwork(network: $network) === null) {
			return ['error' => 'Pick a network Pipelinq can publish to.'];
		}

		if (trim((string)($payload['handle'] ?? '')) === '') {
			return ['error' => 'An account needs the handle its own network shows.'];
		}

		$account = $this->clientFields(payload: $payload);
		$account['ownerUserId'] = $uid;
		$account['credentialRef'] = '';
		$account['active'] = true;
		$account['followerCount'] = 0;

		$readiness = $this->registry->readinessFor(network: $network);
		$account['status'] = self::STATUS_PENDING;
		$account['statusReason'] = '';
		if ($readiness['state'] === SocialBrokerGateway::NOT_CONFIGURED) {
			$account['status'] = self::STATUS_NOT_CONFIGURED;
			$account['statusReason'] = $readiness['reason'];
		}

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $account);
		if ($saved === null) {
			return ['error' => 'The account could not be saved.'];
		}

		return ['account' => $saved];
	}//end createAccount()

	/**
	 * The parameters the browser needs to start, or restart, a connection.
	 *
	 * Nothing is written here and no outbound call is made. The browser posts
	 * these to OpenRegister's own connect endpoint with the user's session, so
	 * the authorization code never passes through Pipelinq.
	 *
	 * @param string $accountId The account to connect.
	 * @param string $uid The caller.
	 * @param string $returnUrl A path on this instance to come back to.
	 *
	 * @return array{error?: string, connect?: array<string, mixed>} The parameters, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function connectRequest(string $accountId, string $uid, string $returnUrl): array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return ['error' => 'That account does not exist.'];
		}

		if ($this->mayActOn(uid: $uid, account: $account) === false) {
			return ['error' => 'You may not connect this account.'];
		}

		$network = (string)($account['network'] ?? '');
		$readiness = $this->registry->readinessFor(network: $network);
		if ($readiness['state'] === SocialBrokerGateway::NOT_CONFIGURED) {
			// Refuse, and say what is missing. The account is left as it is: a
			// row sitting in `pending` would read as a connection under way.
			$this->stampStatus(
				accountId: $accountId,
				account: $account,
				status: self::STATUS_NOT_CONFIGURED,
				reason: $readiness['reason'],
			);

			return ['error' => $readiness['reason']];
		}

		$scope = 'organisation';
		if ((string)($account['kind'] ?? '') === self::KIND_PERSON) {
			$scope = 'personal';
		}

		$connect = [
			'provider' => $this->registry->providerFor(network: $network),
			'scope' => $scope,
			'allowedApps' => [SocialBrokerGateway::APP_ID],
			'name' => trim((string)($account['displayName'] ?? $account['handle'] ?? $network)),
			'returnUrl' => $returnUrl,
			'readiness' => $readiness,
		];

		return ['connect' => array_merge($connect, $this->optionalConnectFields(account: $account))];
	}//end connectRequest()

	/**
	 * The connect parameters that are present only when the account carries
	 * them: its own scopes, its own server, a tenant's own client id, and the
	 * credential a reconnect re-authorises.
	 *
	 * Naming the credential is what makes a reconnect a RECONNECT: the broker
	 * overrides that id in place, so every account and every scheduled post
	 * pointing at it keeps working.
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return array<string, mixed> The fields that apply.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	private function optionalConnectFields(array $account): array {
		$out = [];

		$scopes = ($account['scopes'] ?? []);
		if (is_array($scopes) === true && $scopes !== []) {
			$out['scopes'] = array_values(array_map('strval', $scopes));
		}

		foreach (['instanceBaseUrl' => 'instanceBaseUrl', 'clientId' => 'clientId', 'credentialRef' => 'credentialId'] as $from => $to) {
			$value = trim((string)($account[$from] ?? ''));
			if ($value !== '') {
				$out[$to] = $value;
			}
		}

		return $out;
	}//end optionalConnectFields()

	/**
	 * Record the credential a completed connection produced.
	 *
	 * EXACTLY ONE FIELD is taken from the payload, and even that one is
	 * verified against the broker before it is written. A payload carrying a
	 * token, a refresh token or a client secret changes nothing at all.
	 *
	 * @param string $accountId The account.
	 * @param string $uid The caller, who must own the credential too.
	 * @param array<string, mixed> $payload The client's payload.
	 *
	 * @return array{error?: string, account?: array<string, mixed>} The updated account, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function attachCredential(string $accountId, string $uid, array $payload): array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return ['error' => 'That account does not exist.'];
		}

		if ($this->mayActOn(uid: $uid, account: $account) === false) {
			return ['error' => 'You may not connect this account.'];
		}

		$provider = $this->registry->providerFor(network: (string)($account['network'] ?? ''));
		$credential = $this->resolveCredential(payload: $payload, provider: $provider, uid: $uid);
		if ($credential === null) {
			return ['error' => 'No connection was found for this account. Start the connection again.'];
		}

		if ((string)($credential['owner'] ?? '') !== $uid && $this->groupManager->isAdmin($uid) === false) {
			return ['error' => 'That connection belongs to somebody else.'];
		}

		$update = $this->withCredential(account: $account, credential: $credential);
		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $update, id: $accountId);
		if ($saved === null) {
			return ['error' => 'The connection could not be saved.'];
		}

		return ['account' => $saved];
	}//end attachCredential()

	/**
	 * Revoke a connection: clear the reference, disable the row, keep the history.
	 *
	 * The row is disabled rather than deleted on purpose. Every publication
	 * that already went out names this account, and deleting it would leave
	 * those pointing at nothing.
	 *
	 * @param string $accountId The account.
	 * @param string $uid The caller.
	 *
	 * @return array{error?: string, account?: array<string, mixed>} The updated account, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function revoke(string $accountId, string $uid): array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return ['error' => 'That account does not exist.'];
		}

		if ($this->mayActOn(uid: $uid, account: $account) === false) {
			return ['error' => 'You may not revoke this account.'];
		}

		$update = $account;
		$update['credentialRef'] = '';
		$update['status'] = self::STATUS_DISABLED;
		$update['statusReason'] = 'The connection was revoked.';
		$update['active'] = false;
		$update['expiresAt'] = '';

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $update, id: $accountId);
		if ($saved === null) {
			return ['error' => 'The connection could not be revoked.'];
		}

		return ['account' => $saved];
	}//end revoke()

	/**
	 * Mark an account as needing a reconnect, with the reason that produced it.
	 *
	 * Called by the publishing and metrics paths when the broker reports the
	 * grant is gone, which is the one failure a retry cannot mend.
	 *
	 * @param string $accountId The account.
	 * @param string $reason Why it needs reconnecting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function markRelinkNeeded(string $accountId, string $reason): void {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return;
		}

		if ((string)($account['status'] ?? '') === self::STATUS_RELINK_NEEDED) {
			// Already known. Notifying again on every scheduled post would turn
			// one dead grant into a stream of identical notifications.
			return;
		}

		$this->stampStatus(
			accountId: $accountId,
			account: $account,
			status: self::STATUS_RELINK_NEEDED,
			reason: $reason,
		);

		$owner = trim((string)($account['ownerUserId'] ?? ''));
		if ($owner === '') {
			return;
		}

		$this->notifications->sendNotification(
			userId: $owner,
			subject: self::SUBJECT_RELINK_NEEDED,
			parameters: [
				'handle' => (string)($account['handle'] ?? ''),
				'network' => (string)($account['network'] ?? ''),
				'title' => (string)($account['displayName'] ?? $account['handle'] ?? ''),
			],
			objectType: 'socialAccount',
			objectId: $accountId,
		);
	}//end markRelinkNeeded()

	/**
	 * Record a freshly read follower count.
	 *
	 * @param string $accountId The account.
	 * @param int $followers The count the network reported.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function recordFollowerCount(string $accountId, int $followers): void {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return;
		}

		$account['followerCount'] = max(0, $followers);
		$account['followerCountAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$this->store->save(schemaSlug: $this->schema(), payload: $account, id: $accountId);
	}//end recordFollowerCount()

	/**
	 * Re-read one account's status from the broker and store what it says.
	 *
	 * The broker is the authority on whether a grant still works, so this
	 * mirrors rather than decides. An account with no credential is left alone.
	 *
	 * @param string $accountId The account.
	 *
	 * @return array<string, mixed>|null The updated account, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function syncStatus(string $accountId): ?array {
		$account = $this->getAccount(accountId: $accountId);
		if ($account === null) {
			return null;
		}

		$ref = trim((string)($account['credentialRef'] ?? ''));
		if ($ref === '') {
			return $account;
		}

		$status = $this->credentials->status(credentialRef: $ref);
		if ($status === '' || $status === (string)($account['status'] ?? '')) {
			return $account;
		}

		return $this->stampStatus(accountId: $accountId, account: $account, status: $status, reason: '');
	}//end syncStatus()

	/**
	 * The schema slug, honouring an app-config override.
	 *
	 * @return string The schema slug.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function schema(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA);
	}//end schema()

	/**
	 * The account as it looks once a credential is attached to it.
	 *
	 * Exactly one value comes from the CLIENT (the credential id, and only
	 * after the broker confirmed it). Everything else is copied from what the
	 * broker itself says about that credential, so a payload carrying a token,
	 * a handle or a status of its own changes nothing.
	 *
	 * @param array<string, mixed> $account The account as read.
	 * @param array<string, mixed> $credential The broker's own metadata.
	 *
	 * @return array<string, mixed> The account to store.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	private function withCredential(array $account, array $credential): array {
		$account['credentialRef'] = (string)($credential['id'] ?? '');
		$account['status'] = (string)($credential['status'] ?? self::STATUS_ACTIVE);
		$account['statusReason'] = '';
		$account['connectedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$account['expiresAt'] = (string)($credential['expiresAt'] ?? '');
		$account['active'] = true;

		$identity = [
			'accountId' => 'externalAccountId',
			'accountHandle' => 'handle',
			'accountDisplayName' => 'displayName',
		];
		foreach ($identity as $from => $to) {
			$value = trim((string)($credential[$from] ?? ''));
			if ($value !== '') {
				$account[$to] = $value;
			}
		}

		$scopes = ($credential['scopes'] ?? null);
		if (is_array($scopes) === true) {
			$account['scopes'] = array_values(array_map('strval', $scopes));
		}

		return $account;
	}//end withCredential()

	/**
	 * Take only the fields a client may set.
	 *
	 * @param array<string, mixed> $payload The client's payload.
	 *
	 * @return array<string, mixed> The accepted subset.
	 */
	private function clientFields(array $payload): array {
		$out = [];
		foreach (self::CLIENT_WRITABLE as $field) {
			if (array_key_exists($field, $payload) === true) {
				$out[$field] = $payload[$field];
			}
		}

		return $out;
	}//end clientFields()

	/**
	 * The credential a completed connection produced: the one the client named
	 * when it named one, otherwise the newest this user connected for that
	 * provider.
	 *
	 * @param array<string, mixed> $payload The client's payload.
	 * @param string $provider The broker provider.
	 * @param string $uid The caller.
	 *
	 * @return array<string, mixed>|null The credential metadata, or null.
	 */
	private function resolveCredential(array $payload, string $provider, string $uid): ?array {
		$named = trim((string)($payload['credentialRef'] ?? ''));
		if ($named !== '') {
			return $this->credentials->read(credentialRef: $named);
		}

		return $this->credentials->findLatest(provider: $provider, ownerUid: $uid);
	}//end resolveCredential()

	/**
	 * Write a status and its reason onto an account.
	 *
	 * @param string $accountId The account.
	 * @param array<string, mixed> $account The account as read.
	 * @param string $status The status to store.
	 * @param string $reason The reason to store.
	 *
	 * @return array<string, mixed>|null The updated account, or null.
	 */
	private function stampStatus(string $accountId, array $account, string $status, string $reason): ?array {
		$account['status'] = $status;
		$account['statusReason'] = $reason;

		return $this->store->save(schemaSlug: $this->schema(), payload: $account, id: $accountId);
	}//end stampStatus()
}//end class
