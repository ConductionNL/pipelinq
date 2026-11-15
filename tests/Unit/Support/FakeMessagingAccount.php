<?php

/**
 * A real MessagingServiceAccount over in-memory users, groups, config and session.
 *
 * The session records who is acting, so a fake object store can stamp every
 * write with the acting user and a test can assert that a webhook write ran
 * as the messaging service account and as nobody else.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Builds the account and exposes the acting user.
 */
class FakeMessagingAccount {
	/**
	 * The uid every webhook write must run as in these tests.
	 *
	 * @var string
	 */
	public const UID = 'messaging-service';

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	public array $config = [];

	/**
	 * Accounts by uid.
	 *
	 * @var array<string, IUser>
	 */
	public array $users = [];

	/**
	 * Members of the messaging service group, or null while it does not exist.
	 *
	 * @var array<int, string>|null
	 */
	public ?array $members = null;

	/**
	 * The user the session carries right now.
	 *
	 * @var IUser|null
	 */
	public ?IUser $sessionUser = null;

	/**
	 * The account under test.
	 *
	 * @var MessagingServiceAccount
	 */
	public MessagingServiceAccount $account;

	/**
	 * Build the account.
	 *
	 * @param TestCase $test The test that owns the mocks.
	 */
	public function __construct(private TestCase $test) {
		$this->account = new MessagingServiceAccount(
			$this->appConfig(),
			$this->userManager(),
			$this->groupManager(),
			$this->session(),
			new NullLogger(),
		);
	}//end __construct()

	/**
	 * Configure a usable account: it exists, is enabled and in the group.
	 *
	 * @return self
	 */
	public function usable(): self {
		$this->user(uid: self::UID);
		$this->members = [self::UID];
		$this->config[MessagingServiceAccount::CONFIG_KEY] = self::UID;
		return $this;
	}//end usable()

	/**
	 * The uid acting right now, or null when nobody is.
	 *
	 * @return string|null The uid.
	 */
	public function actingUid(): ?string {
		return $this->sessionUser?->getUID();
	}//end actingUid()

	/**
	 * Make an account.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $enabled Whether it is enabled.
	 *
	 * @return IUser The account.
	 */
	public function user(string $uid, bool $enabled = true): IUser {
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		$this->users[$uid] = $user;
		return $user;
	}//end user()

	/**
	 * In-memory app config.
	 *
	 * @return IAppConfig The config.
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->mock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		return $appConfig;
	}//end appConfig()

	/**
	 * In-memory user manager.
	 *
	 * @return IUserManager The manager.
	 */
	private function userManager(): IUserManager {
		$users = $this->mock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($this->users[$uid] ?? null));
		return $users;
	}//end userManager()

	/**
	 * In-memory group manager with the one messaging service group.
	 *
	 * @return IGroupManager The manager.
	 */
	private function groupManager(): IGroupManager {
		$group = $this->mock(IGroup::class);
		$group->method('inGroup')->willReturnCallback(fn (IUser $user): bool => in_array($user->getUID(), ($this->members ?? []), true));
		$group->method('addUser')->willReturnCallback(
			function (IUser $user): void {
				$this->members[] = $user->getUID();
			}
		);

		$groups = $this->mock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn (string $gid): ?IGroup => ($this->members === null ? null : $group));
		$groups->method('createGroup')->willReturnCallback(
			function (string $gid) use ($group): IGroup {
				$this->members = [];
				return $group;
			}
		);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid): bool => $gid === MessagingServiceAccount::GROUP && in_array($uid, ($this->members ?? []), true)
		);
		return $groups;
	}//end groupManager()

	/**
	 * A session that only ever acts through setVolatileActiveUser().
	 *
	 * @return IUserSession The session.
	 */
	private function session(): IUserSession {
		$session = $this->mock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->sessionUser = $user;
			}
		);
		return $session;
	}//end session()

	/**
	 * A configurable stub of an interface.
	 *
	 * @param class-string $class The interface.
	 *
	 * @return mixed The stub.
	 */
	private function mock(string $class): mixed {
		return $this->test->getMockBuilder($class)->disableOriginalConstructor()->getMock();
	}//end mock()
}//end class
