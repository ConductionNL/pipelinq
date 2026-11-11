<?php

/**
 * Unit tests for SocialAccountService.
 *
 * Two rules are asserted here and nowhere else.
 *
 * NO SECRET EVER LANDS ON AN ACCOUNT. `attachCredential()` is the one method a
 * client can push a payload into, so the test hands it a payload carrying a
 * token, a refresh token and a client secret and then reads every stored value
 * back looking for them.
 *
 * A PERSONAL ACCOUNT IS ITS OWNER'S. `ObjectOwnerAccessPolicy::mayAccess()`
 * admits the `sales` group on any owned object, which is right for a contract
 * and wrong for a colleague's own LinkedIn profile. The guard here admits the
 * owner and an administrator, and the test proves a second marketer is refused.
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
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\SocialAccountService;
use OCA\Pipelinq\Tests\Unit\Service\Social\InMemoryObjectStore;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The account lifecycle, its secret refusal and its ownership boundary.
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */
class SocialAccountServiceTest extends TestCase {
	/**
	 * The in-memory store the service reads and writes.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * The credential reader double.
	 *
	 * @var BrokerCredentialReader
	 */
	private BrokerCredentialReader $credentials;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groups;

	/**
	 * The service under test.
	 *
	 * @var SocialAccountService
	 */
	private SocialAccountService $service;

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

		$registry = $this->createMock(SocialAdapterRegistry::class);
		$registry->method('providerFor')->willReturnCallback(
			static fn (string $network): string => ($network === 'threads' ? '' : $network)
		);
		$registry->method('readinessFor')->willReturnCallback(
			static function (string $network): array {
				if ($network === 'threads') {
					return [
						'state' => SocialBrokerGateway::NOT_CONFIGURED,
						'reason' => 'No developer application is filed for Threads yet.',
					];
				}

				return ['state' => SocialBrokerGateway::READY, 'reason' => ''];
			}
		);
		$registry->method('readiness')->willReturn([]);
		$registry->method('forNetwork')->willReturn(null);

		$this->credentials = $this->createMock(BrokerCredentialReader::class);
		$this->groups = $this->createMock(IGroupManager::class);

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, ['ruben', 'marieke'], true)
		);

		$this->service = new SocialAccountService(
			$this->store,
			$registry,
			$this->credentials,
			$policy,
			$this->groups,
			$this->createMock(NotificationService::class),
		);
	}

	/**
	 * Seed one account row.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @param string $id The id to give it.
	 *
	 * @return array<string, mixed> The seeded account.
	 */
	private function seedAccount(array $overrides = [], string $id = 'acc-1'): array {
		return $this->store->seed(
			schemaSlug: SocialAccountService::SCHEMA,
			id: $id,
			payload: array_merge(
				[
					'network' => 'mastodon',
					'kind' => 'organisation',
					'handle' => '@conduction@mastodon.nl',
					'displayName' => 'Conduction',
					'ownerUserId' => 'ruben',
					'credentialRef' => '',
					'status' => SocialAccountService::STATUS_PENDING,
					'active' => true,
					'publishMode' => 'api',
				],
				$overrides,
			),
		);
	}

	/**
	 * Connecting asks the broker for a connection bound to Pipelinq, and
	 * writes nothing but the refusal path.
	 *
	 * @return void
	 */
	public function testConnectAsksTheBrokerAndStoresOnlyTheReference(): void {
		$this->seedAccount();

		$result = $this->service->connectRequest(
			accountId: 'acc-1',
			uid: 'ruben',
			returnUrl: '/apps/pipelinq/social-accounts/acc-1',
		);

		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame('mastodon', $result['connect']['provider']);
		$this->assertSame([SocialBrokerGateway::APP_ID], $result['connect']['allowedApps']);
		$this->assertSame('organisation', $result['connect']['scope']);
		$this->assertSame('', (string)$this->store->find(SocialAccountService::SCHEMA, 'acc-1')['credentialRef']);
	}

	/**
	 * A reconnect names the EXISTING credential so the broker overrides it in
	 * place, which is what keeps every scheduled post pointing at it working.
	 *
	 * @return void
	 */
	public function testReconnectReauthorisesTheSameCredentialInPlace(): void {
		$this->seedAccount([
			'credentialRef' => 'cred-9',
			'status' => SocialAccountService::STATUS_RELINK_NEEDED,
		]);

		$result = $this->service->connectRequest(
			accountId: 'acc-1',
			uid: 'ruben',
			returnUrl: '/apps/pipelinq/social-accounts/acc-1',
		);

		$this->assertSame('cred-9', $result['connect']['credentialId']);
		$this->assertSame('cred-9', $this->store->find(SocialAccountService::SCHEMA, 'acc-1')['credentialRef']);
	}

	/**
	 * A network with no developer application filed refuses with a reason and
	 * does NOT leave the account looking like a connection is under way.
	 *
	 * @return void
	 */
	public function testAnUnfiledNetworkRefusesTheConnectWithAReason(): void {
		$this->seedAccount(['network' => 'threads']);

		$result = $this->service->connectRequest(
			accountId: 'acc-1',
			uid: 'ruben',
			returnUrl: '/apps/pipelinq/social-accounts/acc-1',
		);

		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('Threads', $result['error']);
		$this->assertSame(
			SocialAccountService::STATUS_NOT_CONFIGURED,
			$this->store->find(SocialAccountService::SCHEMA, 'acc-1')['status'],
		);
	}

	/**
	 * A payload carrying a token, a refresh token and a client secret changes
	 * nothing: only the credential id is taken, and only after the broker
	 * confirmed it.
	 *
	 * @return void
	 */
	public function testASecretInTheConnectResponseIsNeverStored(): void {
		$this->seedAccount();
		$this->credentials->method('read')->willReturn([
			'id' => 'cred-1',
			'owner' => 'ruben',
			'status' => 'active',
			'expiresAt' => '2026-12-01T00:00:00Z',
			'accountHandle' => '@conduction@mastodon.nl',
		]);

		$this->service->attachCredential(
			accountId: 'acc-1',
			uid: 'ruben',
			payload: [
				'credentialRef' => 'cred-1',
				'accessToken' => 'YOUR_TOKEN_HERE',
				'refresh_token' => 'YOUR_TOKEN_HERE',
				'clientSecret' => 'YOUR_TOKEN_HERE',
				'status' => 'i-say-so',
			],
		);

		$stored = $this->store->find(SocialAccountService::SCHEMA, 'acc-1');
		$this->assertSame('cred-1', $stored['credentialRef']);
		$this->assertSame('active', $stored['status'], 'the status comes from the broker, not the body');
		$this->assertStringNotContainsString('YOUR_TOKEN_HERE', json_encode($stored));
		foreach (['accessToken', 'refresh_token', 'clientSecret'] as $forbidden) {
			$this->assertArrayNotHasKey($forbidden, $stored);
		}
	}

	/**
	 * A credential somebody else owns cannot be attached to an account.
	 *
	 * @return void
	 */
	public function testACredentialOwnedBySomebodyElseIsRefused(): void {
		$this->seedAccount();
		$this->credentials->method('read')->willReturn(['id' => 'cred-1', 'owner' => 'marieke']);
		$this->groups->method('isAdmin')->willReturn(false);

		$result = $this->service->attachCredential(
			accountId: 'acc-1',
			uid: 'ruben',
			payload: ['credentialRef' => 'cred-1'],
		);

		$this->assertArrayHasKey('error', $result);
		$this->assertSame('', $this->store->find(SocialAccountService::SCHEMA, 'acc-1')['credentialRef']);
	}

	/**
	 * Revoking clears the reference, disables the row and leaves it readable,
	 * so the publications that already went out still name the account.
	 *
	 * @return void
	 */
	public function testRevokeClearsTheReferenceAndKeepsThePublications(): void {
		$this->seedAccount(['credentialRef' => 'cred-1', 'status' => SocialAccountService::STATUS_ACTIVE]);
		$this->store->seed('socialPublication', 'pub-1', ['accountId' => 'acc-1', 'status' => 'published']);

		$result = $this->service->revoke(accountId: 'acc-1', uid: 'ruben');

		$this->assertArrayNotHasKey('error', $result);
		$stored = $this->store->find(SocialAccountService::SCHEMA, 'acc-1');
		$this->assertSame('', $stored['credentialRef']);
		$this->assertSame(SocialAccountService::STATUS_DISABLED, $stored['status']);
		$this->assertFalse($stored['active']);
		$this->assertNotNull($this->store->find('socialPublication', 'pub-1'));
		$this->assertSame('acc-1', $this->store->find('socialPublication', 'pub-1')['accountId']);
	}

	/**
	 * A colleague, even one in a marketing group, may not act on somebody
	 * else's personal account.
	 *
	 * @return void
	 */
	public function testAnotherUserMayNotActOnAPersonalAccount(): void {
		$account = $this->seedAccount(['kind' => 'person', 'ownerUserId' => 'ruben']);
		$this->groups->method('isAdmin')->willReturn(false);

		$this->assertTrue($this->service->mayActOn(uid: 'ruben', account: $account));
		$this->assertFalse($this->service->mayActOn(uid: 'marieke', account: $account));

		$refused = $this->service->revoke(accountId: 'acc-1', uid: 'marieke');
		$this->assertArrayHasKey('error', $refused);
	}

	/**
	 * An administrator may act on any account.
	 *
	 * @return void
	 */
	public function testAnAdministratorMayActOnAnyAccount(): void {
		$account = $this->seedAccount(['kind' => 'person', 'ownerUserId' => 'ruben']);
		$this->groups->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => ($uid === 'admin')
		);

		$this->assertTrue($this->service->mayActOn(uid: 'admin', account: $account));
	}

	/**
	 * A company account is reachable by any marketer, without an
	 * administrator's rights.
	 *
	 * @return void
	 */
	public function testACompanyAccountIsReachableByAnyMarketer(): void {
		$account = $this->seedAccount(['kind' => 'organisation']);

		$this->assertTrue($this->service->mayActOn(uid: 'marieke', account: $account));
		$this->assertFalse($this->service->mayActOn(uid: 'somebody-else', account: $account));
	}

	/**
	 * A dead grant marks the account and notifies its owner ONCE. Notifying on
	 * every scheduled post would turn one dead grant into a stream.
	 *
	 * @return void
	 */
	public function testARelinkIsRecordedAndNotifiedOnlyOnce(): void {
		$this->seedAccount(['credentialRef' => 'cred-1', 'status' => SocialAccountService::STATUS_ACTIVE]);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('sendNotification');

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$registry = $this->createMock(SocialAdapterRegistry::class);
		$registry->method('readiness')->willReturn([]);

		$service = new SocialAccountService(
			$this->store,
			$registry,
			$this->credentials,
			$policy,
			$this->groups,
			$notifications,
		);

		$service->markRelinkNeeded(accountId: 'acc-1', reason: 'The connection has ended.');
		$service->markRelinkNeeded(accountId: 'acc-1', reason: 'The connection has ended.');

		$stored = $this->store->find(SocialAccountService::SCHEMA, 'acc-1');
		$this->assertSame(SocialAccountService::STATUS_RELINK_NEEDED, $stored['status']);
		$this->assertSame('The connection has ended.', $stored['statusReason']);
	}
	/**
	 * Every connect request shape: a missing account and a person account of
	 * somebody else are refused with their own message; an unfiled network is
	 * refused with its reason and stamped not-configured; an organisation
	 * connects with the organisation scope and its display name; a person
	 * with the personal scope and the handle as name; the account's scopes,
	 * server, client id and credential come along only when present.
	 *
	 * @return void
	 */
	public function testEveryConnectRequestShape(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$this->assertSame(['error' => 'That account does not exist.'], $this->service->connectRequest(accountId: 'nope', uid: 'ruben', returnUrl: '/back'));

		$this->seedAccount(['kind' => 'person', 'ownerUserId' => 'jan'], 'acc-person');
		$this->assertSame(['error' => 'You may not connect this account.'], $this->service->connectRequest(accountId: 'acc-person', uid: 'piet', returnUrl: '/back'));
		$this->assertSame(['error' => 'That account does not exist.'], $this->service->attachCredential(accountId: 'nope', uid: 'ruben', payload: []));
		$this->assertSame(['error' => 'You may not connect this account.'], $this->service->attachCredential(accountId: 'acc-person', uid: 'piet', payload: []));

		$this->seedAccount(['network' => 'threads'], 'acc-threads');
		$this->assertSame(['error' => 'No developer application is filed for Threads yet.'], $this->service->connectRequest(accountId: 'acc-threads', uid: 'ruben', returnUrl: '/back'));
		$stored = $this->store->find(SocialAccountService::SCHEMA, 'acc-threads');
		$this->assertSame([SocialAccountService::STATUS_NOT_CONFIGURED, 'No developer application is filed for Threads yet.'], [$stored['status'], $stored['statusReason']]);

		$this->seedAccount(['scopes' => ['read', 7], 'instanceBaseUrl' => ' https://mastodon.nl ', 'clientId' => ' ', 'credentialRef' => 'cred-9']);
		$this->assertSame(
			['connect' => [
				'provider' => 'mastodon',
				'scope' => 'organisation',
				'allowedApps' => [SocialBrokerGateway::APP_ID],
				'name' => 'Conduction',
				'returnUrl' => '/back',
				'readiness' => ['state' => SocialBrokerGateway::READY, 'reason' => ''],
				'scopes' => ['read', '7'],
				'instanceBaseUrl' => 'https://mastodon.nl',
				'credentialId' => 'cred-9',
			]],
			$this->service->connectRequest(accountId: 'acc-1', uid: 'ruben', returnUrl: '/back')
		);

		$this->seedAccount(['kind' => 'person', 'ownerUserId' => 'jan', 'displayName' => null, 'scopes' => []], 'acc-jan');
		$this->assertSame(
			['connect' => [
				'provider' => 'mastodon',
				'scope' => 'personal',
				'allowedApps' => [SocialBrokerGateway::APP_ID],
				'name' => '@conduction@mastodon.nl',
				'returnUrl' => '/me',
				'readiness' => ['state' => SocialBrokerGateway::READY, 'reason' => ''],
			]],
			$this->service->connectRequest(accountId: 'acc-jan', uid: 'jan', returnUrl: '/me')
		);
	}//end testEveryConnectRequestShape()

	/**
	 * Every relink rule: a missing account and one already waiting for a
	 * relink do nothing; otherwise the status is stamped and the owner is
	 * notified with the account's handle, network and title; an account
	 * without an owner is stamped but nobody is notified.
	 *
	 * @return void
	 */
	public function testEveryRelinkRule(): void {
		$sent = [];
		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('sendNotification')->willReturnCallback(
			static function (...$args) use (&$sent): void {
				$sent[] = $args;
			}
		);
		$service = new SocialAccountService($this->store, $this->createMock(SocialAdapterRegistry::class), $this->credentials, $this->createMock(ObjectOwnerAccessPolicy::class), $this->groups, $notifications);

		$service->markRelinkNeeded(accountId: 'nope', reason: 'r');
		$this->seedAccount(['status' => SocialAccountService::STATUS_RELINK_NEEDED, 'statusReason' => 'old'], 'acc-known');
		$service->markRelinkNeeded(accountId: 'acc-known', reason: 'new');
		$this->assertSame('old', $this->store->find(SocialAccountService::SCHEMA, 'acc-known')['statusReason']);

		$this->seedAccount(['ownerUserId' => ' '], 'acc-orphan');
		$service->markRelinkNeeded(accountId: 'acc-orphan', reason: 'gone');
		$this->assertSame([SocialAccountService::STATUS_RELINK_NEEDED, 'gone'], [$this->store->find(SocialAccountService::SCHEMA, 'acc-orphan')['status'], $this->store->find(SocialAccountService::SCHEMA, 'acc-orphan')['statusReason']]);
		$this->assertSame([], $sent);

		$this->seedAccount(['displayName' => null], 'acc-1');
		$service->markRelinkNeeded(accountId: 'acc-1', reason: 'gone');
		$this->assertCount(1, $sent);
		$this->assertSame(['ruben', SocialAccountService::SUBJECT_RELINK_NEEDED, ['handle' => '@conduction@mastodon.nl', 'network' => 'mastodon', 'title' => '@conduction@mastodon.nl'], 'socialAccount', 'acc-1'], array_slice($sent[0], 0, 5));
	}//end testEveryRelinkRule()
}//end class
