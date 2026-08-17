<?php

/**
 * Unit tests for PosAccessPolicy.
 *
 * The policy is the authorization core that closes the POS IDOR: it decides
 * whether a caller may drive a cashier-level transition on a given transaction
 * (cashier-owner OR POS-group OR admin), whether they are a POS operator, and
 * whether they are a POS manager. Each predicate fails closed.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PosAccessPolicy.
 */
class PosAccessPolicyTest extends TestCase {
	/**
	 * Build a policy with the given admin / group behaviour.
	 *
	 * @param array<string, bool> $admins Map of uid => isAdmin.
	 * @param array<string, string> $config Map of config key => value.
	 * @param array<string, array<int, string>> $groups Map of uid => groups they are in.
	 *
	 * @return PosAccessPolicy The policy under test.
	 */
	private function policy(array $admins, array $config, array $groups): PosAccessPolicy {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($config): string {
				return $config[$key] ?? $default;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			fn (string $uid): bool => ($admins[$uid] ?? false)
		);
		$groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $group): bool => in_array($group, ($groups[$uid] ?? []), true)
		);

		return new PosAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);
	}//end policy()

	/**
	 * The owning cashier may access their own transaction.
	 *
	 * @return void
	 */
	public function testOwningCashierAllowed(): void {
		$policy = $this->policy([], [], []);
		$this->assertTrue($policy->canAccessTransaction(['cashier' => 'alice'], 'alice'));
	}//end testOwningCashierAllowed()

	/**
	 * A non-owner, non-group, non-admin user is DENIED — this is the IDOR fix.
	 *
	 * @return void
	 */
	public function testNonOwnerDeniedClosesIdor(): void {
		// Default POS group 'pos'; bob is not in it and is not admin.
		$policy = $this->policy(['bob' => false], [], ['bob' => []]);
		$this->assertFalse($policy->canAccessTransaction(['cashier' => 'alice'], 'bob'));
	}//end testNonOwnerDeniedClosesIdor()

	/**
	 * A member of the (default) POS group may access any transaction.
	 *
	 * @return void
	 */
	public function testPosGroupMemberAllowed(): void {
		$policy = $this->policy(['carol' => false], [], ['carol' => ['pos']]);
		$this->assertTrue($policy->canAccessTransaction(['cashier' => 'alice'], 'carol'));
	}//end testPosGroupMemberAllowed()

	/**
	 * A custom POS group is honoured from app config.
	 *
	 * @return void
	 */
	public function testConfiguredPosGroupHonoured(): void {
		$policy = $this->policy(['dan' => false], ['pos_group' => 'kassa'], ['dan' => ['kassa']]);
		$this->assertTrue($policy->canAccessTransaction(['cashier' => 'alice'], 'dan'));
	}//end testConfiguredPosGroupHonoured()

	/**
	 * An admin may always access.
	 *
	 * @return void
	 */
	public function testAdminAllowed(): void {
		$policy = $this->policy(['root' => true], [], []);
		$this->assertTrue($policy->canAccessTransaction(['cashier' => 'alice'], 'root'));
	}//end testAdminAllowed()

	/**
	 * An empty user is never allowed (fail closed).
	 *
	 * @return void
	 */
	public function testEmptyUserDenied(): void {
		$policy = $this->policy([], [], []);
		$this->assertFalse($policy->canAccessTransaction(['cashier' => 'alice'], ''));
	}//end testEmptyUserDenied()

	/**
	 * isManager: admin yes; non-admin without configured group no (fail closed);
	 * configured manager-group member yes.
	 *
	 * @return void
	 */
	public function testManagerGate(): void {
		$admin = $this->policy(['boss' => true], [], []);
		$this->assertTrue($admin->isManager('boss'));

		$noGroup = $this->policy(['clerk' => false], [], []);
		$this->assertFalse($noGroup->isManager('clerk'));
		$this->assertFalse($noGroup->isManager(''));

		$member = $this->policy(['mgr' => false], ['pos_manager_group' => 'pos-managers'], ['mgr' => ['pos-managers']]);
		$this->assertTrue($member->isManager('mgr'));
	}//end testManagerGate()

	/**
	 * isPosUser: POS-group member or admin yes; outsider no.
	 *
	 * @return void
	 */
	public function testPosUserGate(): void {
		$member = $this->policy(['c' => false], [], ['c' => ['pos']]);
		$this->assertTrue($member->isPosUser('c'));

		$outsider = $this->policy(['x' => false], [], ['x' => []]);
		$this->assertFalse($outsider->isPosUser('x'));
		$this->assertFalse($outsider->isPosUser(''));

		$admin = $this->policy(['root' => true], [], []);
		$this->assertTrue($admin->isPosUser('root'));
	}//end testPosUserGate()
}//end class
