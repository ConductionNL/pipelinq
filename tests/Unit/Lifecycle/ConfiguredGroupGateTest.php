<?php

/**
 * Unit tests for ConfiguredGroupGate and the billing handoff policy that uses it.
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

use OCA\Pipelinq\Lifecycle\BillingHandoffAccessPolicy;
use OCA\Pipelinq\Lifecycle\ConfiguredGroup;
use OCA\Pipelinq\Lifecycle\ConfiguredGroupGate;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Lifecycle\ConfiguredGroupGate
 * @covers \OCA\Pipelinq\Lifecycle\BillingHandoffAccessPolicy
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroup
 */
class ConfiguredGroupGateTest extends TestCase {

	/**
	 * The app config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groups    = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * The gate under test.
	 *
	 * @return ConfiguredGroupGate
	 */
	private function gate(): ConfiguredGroupGate {
		return new ConfiguredGroupGate(appConfig: $this->appConfig, groupManager: $this->groups);
	}//end gate()

	/**
	 * An empty user id is refused before anything is asked.
	 *
	 * @return void
	 */
	public function testAnEmptyUserIsRefusedWithoutAsking(): void {
		$this->groups->expects($this->never())->method('isAdmin');
		$this->groups->expects($this->never())->method('isInGroup');
		$this->appConfig->expects($this->never())->method('getValueString');

		$this->assertFalse($this->gate()->admits(userId: '', group: new ConfiguredGroup(key: 'k', default: 'sales')));
	}//end testAnEmptyUserIsRefusedWithoutAsking()

	/**
	 * An administrator is admitted without the group being read.
	 *
	 * @return void
	 */
	public function testAnAdminIsAdmittedWithoutReadingTheGroup(): void {
		$this->groups->expects($this->once())->method('isAdmin')->with('root')->willReturn(true);
		$this->appConfig->expects($this->never())->method('getValueString');

		$this->assertTrue($this->gate()->admits(userId: 'root', group: new ConfiguredGroup(key: 'k')));
	}//end testAnAdminIsAdmittedWithoutReadingTheGroup()

	/**
	 * A member of the configured group is admitted; the group is read under the app with its default.
	 *
	 * @return void
	 */
	public function testAMemberOfTheConfiguredGroupIsAdmitted(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$this->appConfig->expects($this->exactly(2))
			->method('getValueString')
			->with('pipelinq', 'pos_group', 'kassa')
			->willReturn('winkel');
		$this->groups->expects($this->exactly(2))
			->method('isInGroup')
			->willReturnMap([['ann', 'winkel', true], ['bob', 'winkel', false]]);

		$group = new ConfiguredGroup(key: 'pos_group', default: 'kassa');
		$this->assertTrue($this->gate()->admits(userId: 'ann', group: $group));
		$this->assertFalse($this->gate()->admits(userId: 'bob', group: $group));
	}//end testAMemberOfTheConfiguredGroupIsAdmitted()

	/**
	 * A group configured empty, with no default, admits no one and is never asked about.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredGroupAdmitsNoOne(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$this->appConfig->method('getValueString')->willReturn('');
		$this->groups->expects($this->never())->method('isInGroup');

		$this->assertFalse($this->gate()->admits(userId: 'ann', group: new ConfiguredGroup(key: 'pos_group')));
		$this->assertFalse($this->gate()->isMemberOf(userId: 'ann', group: new ConfiguredGroup(key: 'pos_group')));
	}//end testAnUnconfiguredGroupAdmitsNoOne()

	/**
	 * Membership alone does not consult the admin flag: an admin outside the group is no member.
	 *
	 * @return void
	 */
	public function testMembershipIgnoresTheAdminFlag(): void {
		$this->groups->expects($this->never())->method('isAdmin');
		$this->appConfig->method('getValueString')->willReturn('winkel');
		$this->groups->method('isInGroup')->with('root', 'winkel')->willReturn(false);

		$this->assertFalse($this->gate()->isMemberOf(userId: 'root', group: new ConfiguredGroup(key: 'pos_group')));
	}//end testMembershipIgnoresTheAdminFlag()

	/**
	 * The billing handoff manager is an admin or a member of the group under its own key, with no default.
	 *
	 * @return void
	 */
	public function testTheBillingHandoffManagerUsesItsOwnKey(): void {
		$this->groups->method('isAdmin')->willReturnMap([['root', true], ['ann', false], ['bob', false], ['', false]]);
		$this->appConfig->method('getValueString')
			->with('pipelinq', BillingHandoffAccessPolicy::MANAGER_GROUP_KEY, '')
			->willReturn('facturatie');
		$this->groups->method('isInGroup')->willReturnMap([['ann', 'facturatie', true], ['bob', 'facturatie', false]]);

		$policy = new BillingHandoffAccessPolicy(appConfig: $this->appConfig, groupManager: $this->groups);

		$this->assertSame('billing_handoff_manager_group', BillingHandoffAccessPolicy::MANAGER_GROUP_KEY);
		$this->assertTrue($policy->isManager(userId: 'root'));
		$this->assertTrue($policy->isManager(userId: 'ann'));
		$this->assertFalse($policy->isManager(userId: 'bob'));
		$this->assertFalse($policy->isManager(userId: ''));
	}//end testTheBillingHandoffManagerUsesItsOwnKey()
}//end class
