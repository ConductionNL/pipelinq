<?php

/**
 * Unit tests for ObjectOwnerAccessPolicy.
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

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy
 */
class ObjectOwnerAccessPolicyTest extends TestCase {

	/**
	 * Build the policy over a group map and an optional configured CRM group.
	 *
	 * @param array<string,array<int,string>> $groups     Groups per user.
	 * @param string                          $configured The crm_group setting.
	 *
	 * @return ObjectOwnerAccessPolicy The policy.
	 */
	private function policy(array $groups, string $configured = ''): ObjectOwnerAccessPolicy {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, ($groups[$uid] ?? []), true)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (
				$app === 'pipelinq' && $key === ObjectOwnerAccessPolicy::CRM_GROUP_KEY ? $configured : $default
			)
		);

		return new ObjectOwnerAccessPolicy(groupManager: $groupManager, appConfig: $appConfig);
	}//end policy()

	/**
	 * An empty uid never gets access, even to an object with an empty owner.
	 *
	 * @return void
	 */
	public function testEmptyUidIsRefused(): void {
		$policy = $this->policy(['' => ['admin']]);

		$this->assertFalse($policy->mayAccess('', ['ownerId' => '']));
		$this->assertFalse($policy->isPrivileged(''));
	}//end testEmptyUidIsRefused()

	/**
	 * The owner may access their own object; the owner value is trimmed.
	 *
	 * @return void
	 */
	public function testOwnerMayAccess(): void {
		$policy = $this->policy([]);

		$this->assertTrue($policy->mayAccess('alice', ['ownerId' => ' alice ']));
		$this->assertFalse($policy->mayAccess('bob', ['ownerId' => 'alice']));
		$this->assertFalse($policy->mayAccess('bob', []));
	}//end testOwnerMayAccess()

	/**
	 * A custom owner field is honoured instead of ownerId.
	 *
	 * @return void
	 */
	public function testCustomOwnerField(): void {
		$policy = $this->policy([]);

		$this->assertTrue($policy->mayAccess('alice', ['assignee' => 'alice', 'ownerId' => 'bob'], 'assignee'));
		$this->assertFalse($policy->mayAccess('bob', ['assignee' => 'alice', 'ownerId' => 'bob'], 'assignee'));
	}//end testCustomOwnerField()

	/**
	 * Members of admin or sales are privileged and may access any object.
	 *
	 * @return void
	 */
	public function testBuiltInPrivilegedGroups(): void {
		$policy = $this->policy(['ann' => ['admin'], 'sam' => ['sales'], 'joe' => ['users']]);

		$this->assertTrue($policy->isPrivileged('ann'));
		$this->assertTrue($policy->isPrivileged('sam'));
		$this->assertFalse($policy->isPrivileged('joe'));
		$this->assertTrue($policy->mayAccess('sam', ['ownerId' => 'alice']));
		$this->assertFalse($policy->mayAccess('joe', ['ownerId' => 'alice']));
	}//end testBuiltInPrivilegedGroups()

	/**
	 * The configured CRM group adds to the built-in list.
	 *
	 * @return void
	 */
	public function testConfiguredCrmGroupIsPrivileged(): void {
		$policy = $this->policy(['kcc' => ['klantcontact']], 'klantcontact');

		$this->assertTrue($policy->isPrivileged('kcc'));
		$this->assertTrue($policy->mayAccess('kcc', ['ownerId' => 'alice']));
	}//end testConfiguredCrmGroupIsPrivileged()

	/**
	 * Without a configured CRM group nobody outside the built-in groups is privileged.
	 *
	 * @return void
	 */
	public function testUnsetCrmGroupGrantsNothing(): void {
		$policy = $this->policy(['kcc' => ['klantcontact', '']]);

		$this->assertFalse($policy->isPrivileged('kcc'));
	}//end testUnsetCrmGroupGrantsNothing()
}//end class
