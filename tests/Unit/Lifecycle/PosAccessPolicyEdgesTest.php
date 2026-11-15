<?php

/**
 * Edge cases of PosAccessPolicy: the cashier match, the default POS group, an
 * unset manager group and the order in which a decision is reached.
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

use OCA\Pipelinq\Lifecycle\ConfiguredGroup;
use OCA\Pipelinq\Lifecycle\ConfiguredGroupGate;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the POS access policy.
 */
#[CoversClass(PosAccessPolicy::class)]
#[UsesClass(ConfiguredGroupGate::class)]
#[UsesClass(ConfiguredGroup::class)]
class PosAccessPolicyEdgesTest extends TestCase {

	/**
	 * An app config that answers each key's default, as on a fresh install.
	 *
	 * @return IAppConfig
	 */
	private function defaultConfig(): IAppConfig {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $default
		);
		return $appConfig;
	}//end defaultConfig()

	/**
	 * The cashier must match exactly; a numeric cashier is compared as a string.
	 *
	 * @return void
	 */
	public function testTheCashierMustMatchExactly(): void {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturn(false);
		$policy = new PosAccessPolicy(appConfig: $this->defaultConfig(), groupManager: $groupManager);

		$this->assertTrue(condition: $policy->canAccessTransaction(object: ['cashier' => 'kassa1'], userId: 'kassa1'));
		$this->assertTrue(condition: $policy->canAccessTransaction(object: ['cashier' => 42], userId: '42'));
		$this->assertFalse(condition: $policy->canAccessTransaction(object: ['cashier' => 'Kassa1'], userId: 'kassa1'));
		$this->assertFalse(condition: $policy->canAccessTransaction(object: ['cashier' => ''], userId: 'kassa1'));
		$this->assertFalse(condition: $policy->canAccessTransaction(object: [], userId: 'kassa1'));
	}//end testTheCashierMustMatchExactly()

	/**
	 * An unconfigured POS group falls back to the `pos` group.
	 *
	 * @return void
	 */
	public function testTheDefaultPosGroupIsAsked(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueString')
			->with('pipelinq', PosAccessPolicy::POS_GROUP_KEY, PosAccessPolicy::POS_GROUP_DEFAULT)
			->willReturn(PosAccessPolicy::POS_GROUP_DEFAULT);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->expects($this->once())->method('isInGroup')->with('anna', 'pos')->willReturn(true);
		$policy = new PosAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);

		$this->assertTrue(condition: $policy->canAccessTransaction(object: ['cashier' => 'kassa1'], userId: 'anna'));
	}//end testTheDefaultPosGroupIsAsked()

	/**
	 * A POS group configured as empty admits no member, so only the cashier and admins remain.
	 *
	 * @return void
	 */
	public function testAnEmptyPosGroupAdmitsNoMember(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $u): bool => $u === 'root');
		$groupManager->expects($this->never())->method('isInGroup');
		$policy = new PosAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);

		$this->assertFalse(condition: $policy->canAccessTransaction(object: ['cashier' => 'kassa1'], userId: 'anna'));
		$this->assertFalse(condition: $policy->isPosUser(userId: 'anna'));
		$this->assertTrue(condition: $policy->canAccessTransaction(object: ['cashier' => 'kassa1'], userId: 'kassa1'));
		$this->assertTrue(condition: $policy->isPosUser(userId: 'root'));
	}//end testAnEmptyPosGroupAdmitsNoMember()

	/**
	 * The manager group has no default: unset, only an admin is a manager.
	 *
	 * @return void
	 */
	public function testAnUnsetManagerGroupLeavesOnlyAdmins(): void {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $u): bool => $u === 'root');
		$groupManager->expects($this->never())->method('isInGroup');
		$policy = new PosAccessPolicy(appConfig: $this->defaultConfig(), groupManager: $groupManager);

		$this->assertFalse(condition: $policy->isManager(userId: 'kassa1'));
		$this->assertFalse(condition: $policy->isManager(userId: ''));
		$this->assertTrue(condition: $policy->isManager(userId: 'root'));
	}//end testAnUnsetManagerGroupLeavesOnlyAdmins()

	/**
	 * An admin is admitted before the cashier or any group is looked at.
	 *
	 * @return void
	 */
	public function testAnAdminIsAdmittedWithoutAGroupLookup(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->never())->method('getValueString');
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->expects($this->once())->method('isAdmin')->with('root')->willReturn(true);
		$groupManager->expects($this->never())->method('isInGroup');
		$policy = new PosAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);

		$this->assertTrue(condition: $policy->canAccessTransaction(object: ['cashier' => 'kassa1'], userId: 'root'));
	}//end testAnAdminIsAdmittedWithoutAGroupLookup()

	/**
	 * An empty user id is refused before anything is asked, even when the cashier is empty too.
	 *
	 * @return void
	 */
	public function testAnEmptyUserIsRefusedBeforeAnyLookup(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->never())->method('getValueString');
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->expects($this->never())->method('isAdmin');
		$groupManager->expects($this->never())->method('isInGroup');
		$policy = new PosAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);

		$this->assertFalse(condition: $policy->canAccessTransaction(object: ['cashier' => ''], userId: ''));
		$this->assertFalse(condition: $policy->isPosUser(userId: ''));
	}//end testAnEmptyUserIsRefusedBeforeAnyLookup()
}//end class
