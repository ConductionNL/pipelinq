<?php

/**
 * Unit tests for PosTransactionAccessGuard and PosTransactionRefundGuard.
 *
 * Pins each guard's allow / deny outcome per role and the exact refusal
 * message the lifecycle surfaces to the cashier.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Lifecycle\PosTransactionAccessGuard;
use OCA\Pipelinq\Lifecycle\PosTransactionRefundGuard;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Lifecycle\PosTransactionAccessGuard
 * @covers \OCA\Pipelinq\Lifecycle\PosTransactionRefundGuard
 * @uses   \OCA\Pipelinq\Lifecycle\PosAccessPolicy
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroupGate
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroup
 */
class PosTransactionAccessRefundGuardTest extends TestCase {

	private const ACCESS_DENIAL = 'U mag deze transactie niet wijzigen. Alleen de eigen kassamedewerker, '
		. 'een lid van de POS-groep of een beheerder is gemachtigd.';

	private const REFUND_DENIAL = 'Alleen een beheerder mag een transactie terugboeken.';

	/**
	 * Build a POS policy over admin / group maps and an app-config map.
	 *
	 * @param array<int,string>               $admins Admin uids.
	 * @param array<string,array<int,string>> $groups Groups per user.
	 * @param array<string,string>            $config App-config values by key.
	 *
	 * @return PosAccessPolicy The policy.
	 */
	private function policy(array $admins = [], array $groups = [], array $config = []): PosAccessPolicy {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, $admins, true)
		);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, ($groups[$uid] ?? []), true)
		);

		return new PosAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);
	}//end policy()

	/**
	 * The access guard refuses a stranger with the exact cashier-facing message.
	 *
	 * @return void
	 */
	public function testAccessGuardRefusesAStrangerWithItsMessage(): void {
		$guard = new PosTransactionAccessGuard(policy: $this->policy());

		$result = $guard->check(['cashier' => 'alice'], 'void', 'bob');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::ACCESS_DENIAL, $result->getMessage());
	}//end testAccessGuardRefusesAStrangerWithItsMessage()

	/**
	 * The access guard refuses an empty user even on a transaction with no cashier.
	 *
	 * @return void
	 */
	public function testAccessGuardRefusesAnEmptyUser(): void {
		$guard = new PosTransactionAccessGuard(policy: $this->policy(admins: ['']));

		$result = $guard->check([], 'void', '');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::ACCESS_DENIAL, $result->getMessage());
	}//end testAccessGuardRefusesAnEmptyUser()

	/**
	 * The owning cashier, an admin and a member of the configured POS group pass.
	 *
	 * @return void
	 */
	public function testAccessGuardAdmitsCashierAdminAndPosGroup(): void {
		$guard = new PosTransactionAccessGuard(
			policy: $this->policy(
				admins: ['root'],
				groups: ['dana' => ['kassa']],
				config: [PosAccessPolicy::POS_GROUP_KEY => 'kassa']
			)
		);

		$cashier = $guard->check(['cashier' => 'alice'], 'void', 'alice');
		$this->assertTrue($cashier->isAllowed());
		$this->assertNull($cashier->getMessage());

		$this->assertTrue($guard->check(['cashier' => 'alice'], 'void', 'root')->isAllowed());
		$this->assertTrue($guard->check(['cashier' => 'alice'], 'void', 'dana')->isAllowed());
	}//end testAccessGuardAdmitsCashierAdminAndPosGroup()

	/**
	 * Membership of the default `pos` group no longer counts once another group is configured.
	 *
	 * @return void
	 */
	public function testAccessGuardFollowsTheConfiguredGroupNotTheDefault(): void {
		$guard = new PosTransactionAccessGuard(
			policy: $this->policy(groups: ['erin' => ['pos']], config: [PosAccessPolicy::POS_GROUP_KEY => 'kassa'])
		);

		$this->assertFalse($guard->check(['cashier' => 'alice'], 'void', 'erin')->isAllowed());
	}//end testAccessGuardFollowsTheConfiguredGroupNotTheDefault()

	/**
	 * The refund guard refuses a POS user and the owning cashier who are not managers.
	 *
	 * @return void
	 */
	public function testRefundGuardRefusesNonManagers(): void {
		$guard = new PosTransactionRefundGuard(
			policy: $this->policy(
				groups: ['alice' => ['pos']],
				config: [PosAccessPolicy::MANAGER_GROUP_KEY => 'pos-managers']
			)
		);

		$result = $guard->check(['cashier' => 'alice'], 'refund', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::REFUND_DENIAL, $result->getMessage());
	}//end testRefundGuardRefusesNonManagers()

	/**
	 * Without a configured manager group only an admin may refund.
	 *
	 * @return void
	 */
	public function testRefundGuardWithoutManagerGroupAdmitsOnlyAdmins(): void {
		$guard = new PosTransactionRefundGuard(
			policy: $this->policy(admins: ['root'], groups: ['mia' => ['pos-managers']])
		);

		$this->assertFalse($guard->check([], 'refund', 'mia')->isAllowed());
		$this->assertTrue($guard->check([], 'refund', 'root')->isAllowed());
	}//end testRefundGuardWithoutManagerGroupAdmitsOnlyAdmins()

	/**
	 * A member of the configured manager group may refund, and an empty user may not.
	 *
	 * @return void
	 */
	public function testRefundGuardAdmitsManagerGroupMembers(): void {
		$guard = new PosTransactionRefundGuard(
			policy: $this->policy(
				admins: [''],
				groups: ['mia' => ['pos-managers'], '' => ['pos-managers']],
				config: [PosAccessPolicy::MANAGER_GROUP_KEY => 'pos-managers']
			)
		);

		$manager = $guard->check(['cashier' => 'alice'], 'refund', 'mia');
		$this->assertTrue($manager->isAllowed());
		$this->assertNull($manager->getMessage());

		$this->assertFalse($guard->check([], 'refund', '')->isAllowed());
	}//end testRefundGuardAdmitsManagerGroupMembers()
}//end class
