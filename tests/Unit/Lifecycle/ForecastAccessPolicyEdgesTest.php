<?php

/**
 * Edge cases of ForecastAccessPolicy: unknown levels, quota rights, an unset
 * manager group and the exact group lookup.
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
use OCA\Pipelinq\Lifecycle\ForecastAccessPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the forecast access policy.
 */
#[CoversClass(ForecastAccessPolicy::class)]
#[UsesClass(ConfiguredGroupGate::class)]
#[UsesClass(ConfiguredGroup::class)]
class ForecastAccessPolicyEdgesTest extends TestCase {

	/**
	 * A policy whose manager group is `managers` with `alice` in it, and `root` as admin.
	 *
	 * @param string $managerGroup The configured manager group id.
	 *
	 * @return ForecastAccessPolicy
	 */
	private function policy(string $managerGroup='managers'): ForecastAccessPolicy {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($managerGroup);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $u): bool => $u === 'root');
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $u, string $g): bool => $g === 'managers' && $u === 'alice'
		);

		return new ForecastAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);
	}//end policy()

	/**
	 * Levels and whether a manager may override and set a quota there.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function levels(): array {
		return [
			'rep'                 => ['rep', true],
			'team'                => ['team', true],
			'division'            => ['division', true],
			'company'             => ['company', false],
			'an unknown level'    => ['region', false],
			'a level in capitals' => ['Team', false],
			'no level'            => ['', false],
		];
	}//end levels()

	/**
	 * A manager overrides and sets quotas only at rep, team and division level, spelled exactly.
	 *
	 * @param string $level   The forecast level.
	 * @param bool   $manages Whether the manager manages it.
	 *
	 * @return void
	 */
	#[DataProvider('levels')]
	public function testAManagerManagesOnlyTheThreeLevels(string $level, bool $manages): void {
		$policy = $this->policy();
		$this->assertSame(expected: $manages, actual: $policy->canOverride(userId: 'alice', level: $level));
		$this->assertSame(expected: $manages, actual: $policy->canSetQuota(userId: 'alice', level: $level));
		$this->assertSame(expected: $manages, actual: $policy->canSetQuota(userId: 'root', level: $level));
	}//end testAManagerManagesOnlyTheThreeLevels()

	/**
	 * A rep may neither set quotas nor override, not even on their own level.
	 *
	 * @return void
	 */
	public function testARepSetsNoQuota(): void {
		$policy = $this->policy();
		$this->assertFalse(condition: $policy->canSetQuota(userId: 'john.doe', level: 'rep'));
		$this->assertFalse(condition: $policy->canOverride(userId: 'john.doe', level: 'team'));
		$this->assertFalse(condition: $policy->canSetQuota(userId: '', level: 'rep'));
	}//end testARepSetsNoQuota()

	/**
	 * A rep reads their own rep forecast only; a missing or other owner, or another level, is refused.
	 *
	 * @return void
	 */
	public function testARepReadsOnlyTheirOwnRepForecast(): void {
		$policy = $this->policy();
		$this->assertTrue(condition: $policy->canRead(userId: 'john.doe', level: 'rep', ownerId: 'john.doe'));
		$this->assertFalse(condition: $policy->canRead(userId: 'john.doe', level: 'rep', ownerId: null));
		$this->assertFalse(condition: $policy->canRead(userId: 'john.doe', level: 'rep', ownerId: 'John.Doe'));
		$this->assertFalse(condition: $policy->canRead(userId: 'john.doe', level: 'Rep', ownerId: 'john.doe'));
		$this->assertFalse(condition: $policy->canRead(userId: 'john.doe', level: 'division', ownerId: 'john.doe'));
	}//end testARepReadsOnlyTheirOwnRepForecast()

	/**
	 * With no manager group configured only an admin is a manager; group membership is never asked.
	 *
	 * @return void
	 */
	public function testAnUnsetManagerGroupLeavesOnlyAdmins(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueString')
			->with('pipelinq', ForecastAccessPolicy::MANAGER_GROUP_KEY, '')
			->willReturn('');
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $u): bool => $u === 'root');
		$groupManager->expects($this->never())->method('isInGroup');
		$policy = new ForecastAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);

		$this->assertFalse(condition: $policy->isManager(userId: 'alice'));
		$this->assertTrue(condition: $policy->isManager(userId: 'root'));
	}//end testAnUnsetManagerGroupLeavesOnlyAdmins()

	/**
	 * Membership is asked of the configured group, for the user in question.
	 *
	 * @return void
	 */
	public function testMembershipIsAskedOfTheConfiguredGroup(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('forecast-leads');
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->expects($this->once())->method('isInGroup')->with('bob', 'forecast-leads')->willReturn(true);
		$policy = new ForecastAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);

		$this->assertTrue(condition: $policy->canRead(userId: 'bob', level: 'company', ownerId: null));
	}//end testMembershipIsAskedOfTheConfiguredGroup()
}//end class
