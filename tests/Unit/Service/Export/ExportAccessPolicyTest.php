<?php

/**
 * ExportAccessPolicy: who may manage exports.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\ExportAccessPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Admins always, members of the configured analyst group too, no one else.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportAccessPolicy
 */
class ExportAccessPolicyTest extends TestCase {

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The configured analyst group.
	 *
	 * @var string
	 */
	private string $analystGroup = '';

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
	}//end setUp()

	/**
	 * The policy under test.
	 *
	 * @return ExportAccessPolicy
	 */
	private function policy(): ExportAccessPolicy {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame(['pipelinq', ExportAccessPolicy::ANALYST_GROUP_KEY], [$app, $key]);
				return $this->analystGroup;
			}
		);

		return new ExportAccessPolicy($appConfig, $this->groups);
	}//end policy()

	/**
	 * An empty user id is refused without asking anyone.
	 *
	 * @return void
	 */
	public function testAnEmptyUserIsRefused(): void {
		$this->groups->expects($this->never())->method('isAdmin');

		$this->assertFalse($this->policy()->isExportAdmin(userId: ''));
	}//end testAnEmptyUserIsRefused()

	/**
	 * An admin is allowed without a group lookup.
	 *
	 * @return void
	 */
	public function testAnAdminIsAllowed(): void {
		$this->analystGroup = 'analysts';
		$this->groups->expects($this->never())->method('isInGroup');

		$this->assertTrue($this->policy()->isExportAdmin(userId: 'admin'));
	}//end testAnAdminIsAllowed()

	/**
	 * Without an analyst group a non-admin is refused.
	 *
	 * @return void
	 */
	public function testWithoutAnAnalystGroupOnlyAdminsAreAllowed(): void {
		$this->groups->expects($this->never())->method('isInGroup');

		$this->assertFalse($this->policy()->isExportAdmin(userId: 'ann'));
	}//end testWithoutAnAnalystGroupOnlyAdminsAreAllowed()

	/**
	 * With an analyst group, membership of that group decides.
	 *
	 * @return void
	 */
	public function testAnalystGroupMembershipDecides(): void {
		$this->analystGroup = 'analysts';
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($group === 'analysts' && $uid === 'ann')
		);
		$policy = $this->policy();

		$this->assertTrue($policy->isExportAdmin(userId: 'ann'));
		$this->assertFalse($policy->isExportAdmin(userId: 'bob'));
	}//end testAnalystGroupMembershipDecides()
}//end class
