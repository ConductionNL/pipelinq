<?php

/**
 * Tests for PortalServiceAccount.
 *
 * Picking the account validates it and puts it in the service group; running
 * as it swaps the session subject with setVolatileActiveUser() and always
 * restores the previous one; an unusable account refuses with 503 before the
 * operation runs.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use InvalidArgumentException;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PortalServiceAccount.
 */
class PortalServiceAccountTest extends TestCase {

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Accounts by uid.
	 *
	 * @var array<string, IUser>
	 */
	private array $users = [];

	/**
	 * Members of the service group, or null when the group does not exist.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $members = null;

	/**
	 * The user on the session.
	 *
	 * @var IUser|null
	 */
	private ?IUser $sessionUser = null;

	/**
	 * Every user set on the session, in order.
	 *
	 * @var array<int, string|null>
	 */
	private array $volatile = [];

	/**
	 * An account.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $enabled Whether it is enabled.
	 *
	 * @return IUser The account.
	 */
	private function user(string $uid, bool $enabled = true): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		$this->users[$uid] = $user;
		return $user;
	}//end user()

	/**
	 * The service over in-memory config, users, groups and session.
	 *
	 * @return PortalServiceAccount The service.
	 */
	private function service(): PortalServiceAccount {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($this->users[$uid] ?? null));

		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturnCallback(fn (IUser $user): bool => in_array($user->getUID(), ($this->members ?? []), true));
		$group->method('addUser')->willReturnCallback(
			function (IUser $user): void {
				$this->members[] = $user->getUID();
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn (string $gid): ?IGroup => ($this->members === null ? null : $group));
		$groups->method('createGroup')->willReturnCallback(
			function (string $gid) use ($group): IGroup {
				$this->members = [];
				return $group;
			}
		);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid): bool => $gid === PortalServiceAccount::GROUP && in_array($uid, ($this->members ?? []), true)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->sessionUser = $user;
				$this->volatile[] = $user?->getUID();
			}
		);
		$session->expects($this->never())->method('setUser');

		return new PortalServiceAccount($appConfig, $users, $groups, $session, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Picking an enabled account creates the group, enrols it and stores it.
	 *
	 * @return void
	 */
	public function testAssignEnrolsTheAccountAndStoresIt(): void {
		$this->user(uid: 'portal-service');

		$status = $this->service()->assign(userId: 'portal-service');

		$this->assertSame(['userId' => 'portal-service', 'usable' => true, 'reason' => null], $status);
		$this->assertSame(['portal-service'], $this->members);
		$this->assertSame('portal-service', $this->config[PortalServiceAccount::CONFIG_KEY]);
	}//end testAssignEnrolsTheAccountAndStoresIt()

	/**
	 * An unknown or disabled account cannot be picked, and nothing is stored.
	 *
	 * @return void
	 */
	public function testAssignRefusesAnUnknownOrDisabledAccount(): void {
		$this->user(uid: 'off', enabled: false);
		$service = $this->service();

		foreach (['nobody', 'off', ''] as $uid) {
			try {
				$service->assign(userId: $uid);
				$this->fail('Assigning "'.$uid.'" must be refused.');
			} catch (InvalidArgumentException $e) {
				$this->assertArrayNotHasKey(PortalServiceAccount::CONFIG_KEY, $this->config);
			}
		}
	}//end testAssignRefusesAnUnknownOrDisabledAccount()

	/**
	 * The status names why an account cannot act.
	 *
	 * @return void
	 */
	public function testStatusNamesTheReason(): void {
		$this->assertSame(PortalServiceAccount::REASON_UNSET, $this->service()->status()['reason']);

		$this->config[PortalServiceAccount::CONFIG_KEY] = 'ghost';
		$this->assertSame(PortalServiceAccount::REASON_UNKNOWN, $this->service()->status()['reason']);

		$this->user(uid: 'off', enabled: false);
		$this->config[PortalServiceAccount::CONFIG_KEY] = 'off';
		$this->assertSame(PortalServiceAccount::REASON_DISABLED, $this->service()->status()['reason']);

		$this->user(uid: 'loose');
		$this->config[PortalServiceAccount::CONFIG_KEY] = 'loose';
		$this->assertSame(PortalServiceAccount::REASON_NOT_IN_GROUP, $this->service()->status()['reason']);
	}//end testStatusNamesTheReason()

	/**
	 * runAs swaps to the account and restores the caller, also on a throw.
	 *
	 * @return void
	 */
	public function testRunAsRestoresTheCallerEvenOnAThrow(): void {
		$this->user(uid: 'portal-service');
		$this->members = ['portal-service'];
		$this->config[PortalServiceAccount::CONFIG_KEY] = 'portal-service';
		$this->sessionUser = $this->user(uid: 'alice');
		$service = $this->service();

		$seen = $service->runAs(fn (): ?string => $this->sessionUser?->getUID());
		$this->assertSame('portal-service', $seen);
		$this->assertSame('alice', $this->sessionUser?->getUID());

		try {
			$service->runAs(
				static function (): void {
					throw new RuntimeException('boom');
				}
			);
			$this->fail('The throw must reach the caller.');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertSame('alice', $this->sessionUser?->getUID());
		$this->assertSame(['portal-service', 'alice', 'portal-service', 'alice'], $this->volatile);
	}//end testRunAsRestoresTheCallerEvenOnAThrow()

	/**
	 * An unusable account refuses with 503 and the operation never runs.
	 *
	 * @return void
	 */
	public function testRunAsRefusesWith503AndDoesNotRun(): void {
		$ran = false;

		try {
			$this->service()->runAs(
				function () use (&$ran): void {
					$ran = true;
				}
			);
			$this->fail('An unset account must refuse.');
		} catch (PortalException $e) {
			$this->assertSame(503, $e->getStatus());
		}

		$this->assertFalse($ran);
		$this->assertSame([], $this->volatile);
	}//end testRunAsRefusesWith503AndDoesNotRun()
}//end class
