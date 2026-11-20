<?php

/**
 * A Nextcloud with users, one group and a session, around a real BackgroundServiceAccount.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCA\Pipelinq\Service\BackgroundServiceAccount;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The world a background service account lives in, with the real account class on top.
 */
class FakeBackgroundAccount {
	public const UID = 'pipelinq-background';

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
	 * Members of the service group; null while the group does not exist.
	 *
	 * @var array<int, string>|null
	 */
	public ?array $members = null;

	/**
	 * Who the session says is acting.
	 *
	 * @var IUser|null
	 */
	public ?IUser $sessionUser = null;

	/**
	 * Every log line: level, message, context.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $logged = [];

	/**
	 * The real account.
	 *
	 * @var BackgroundServiceAccount
	 */
	public BackgroundServiceAccount $account;

	/**
	 * Constructor.
	 *
	 * @param TestCase $test The test that builds the stubs.
	 */
	public function __construct(private TestCase $test) {
		$world = $this;
		$logger = new class($world) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param FakeBackgroundAccount $world Where lines are kept.
			 */
			public function __construct(private FakeBackgroundAccount $world) {
			}//end __construct()

			/**
			 * Keep the line.
			 *
			 * @param mixed $level The level.
			 * @param string|\Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->world->logged[] = [(string)$level, (string)$message, $context];
			}//end log()
		};

		$this->account = new BackgroundServiceAccount($this->appConfig(), $this->userManager(), $this->groupManager(), $this->session(), $logger);
	}//end __construct()

	/**
	 * An enabled account, in the group, picked by an admin.
	 *
	 * @return self
	 */
	public function usable(): self {
		$this->user(uid: self::UID);
		$this->members = [self::UID];
		$this->config[BackgroundServiceAccount::CONFIG_KEY] = self::UID;
		return $this;
	}//end usable()

	/**
	 * Who is acting right now.
	 *
	 * @return string|null The uid, or null for nobody.
	 */
	public function actingUid(): ?string {
		return $this->sessionUser?->getUID();
	}//end actingUid()

	/**
	 * Add an account.
	 *
	 * @param string $uid The uid.
	 * @param bool $enabled Whether it is enabled.
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
	 * App config over the array.
	 *
	 * @return IAppConfig
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
	 * Users over the array.
	 *
	 * @return IUserManager
	 */
	private function userManager(): IUserManager {
		$users = $this->mock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($this->users[$uid] ?? null));
		return $users;
	}//end userManager()

	/**
	 * One group: the background service group.
	 *
	 * @return IGroupManager
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
			fn (string $uid, string $gid): bool => $gid === BackgroundServiceAccount::GROUP && in_array($uid, ($this->members ?? []), true)
		);
		return $groups;
	}//end groupManager()

	/**
	 * A session that remembers the volatile user.
	 *
	 * @return IUserSession
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
