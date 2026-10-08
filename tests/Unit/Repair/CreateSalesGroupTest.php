<?php

/**
 * Unit tests for CreateSalesGroup.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/create-sales-group/specs/notifications/spec.md#requirement-the-group-the-notification-rules-address-exists-req-raf-071
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\CreateSalesGroup;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The sales group exists after install and upgrade, and an existing one is left alone.
 */
class CreateSalesGroupTest extends TestCase {

	/**
	 * A missing group is created with its display name, and the creation is logged.
	 *
	 * @return void
	 */
	public function testCreatesTheGroupWhenItIsMissing(): void {
		$group = $this->createMock(IGroup::class);
		$group->expects($this->once())->method('setDisplayName')->with('Sales')->willReturn(true);
		// Never a member: an administrator decides who is in sales.
		$group->expects($this->never())->method('addUser');
		$group->expects($this->never())->method('removeUser');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->with('sales')->willReturn(false);
		$groupManager->expects($this->once())->method('createGroup')->with('sales')->willReturn($group);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->stringContains('Created the group sales'));

		(new CreateSalesGroup(groupManager: $groupManager, logger: $logger))->run($this->createMock(IOutput::class));
	}//end testCreatesTheGroupWhenItIsMissing()

	/**
	 * An existing group, its name and its members are not touched.
	 *
	 * @return void
	 */
	public function testLeavesAnExistingGroupAlone(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->with('sales')->willReturn(true);
		$groupManager->expects($this->never())->method('createGroup');
		$groupManager->expects($this->never())->method('get');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		(new CreateSalesGroup(groupManager: $groupManager, logger: $logger))->run($this->createMock(IOutput::class));
	}//end testLeavesAnExistingGroupAlone()

	/**
	 * A failed creation warns rather than throwing, so the rest of the repair runs.
	 *
	 * @return void
	 */
	public function testWarnsWhenTheGroupCannotBeCreated(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(false);
		$groupManager->method('createGroup')->willReturn(null);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		(new CreateSalesGroup(groupManager: $groupManager, logger: $this->createMock(LoggerInterface::class)))->run($output);
	}//end testWarnsWhenTheGroupCannotBeCreated()

	/**
	 * The step runs on a fresh install and after every upgrade.
	 *
	 * Nextcloud runs post-migration only on an upgrade, and install only on
	 * a first install, so the step has to be in both.
	 *
	 * @return void
	 */
	public function testTheStepRunsOnInstallAndAfterUpgrade(): void {
		$info = simplexml_load_file(__DIR__.'/../../../appinfo/info.xml');
		$this->assertNotFalse($info);
		$step = CreateSalesGroup::class;
		$install = array_map('strval', $info->xpath('/info/repair-steps/install/step'));
		$post = array_map('strval', $info->xpath('/info/repair-steps/post-migration/step'));
		$this->assertContains($step, $install);
		$this->assertContains($step, $post);
	}//end testTheStepRunsOnInstallAndAfterUpgrade()

	/**
	 * Every group a notification rule addresses is the group this step creates.
	 *
	 * @return void
	 */
	public function testEveryAddressedGroupIsCreated(): void {
		$root = __DIR__.'/../../../lib/Settings/';
		$files = array_merge([$root.'pipelinq_register.json'], (glob($root.'register.d/*.json') ?: []));
		$addressed = [];
		foreach ($files as $file) {
			$data = json_decode((string) file_get_contents($file), true);
			foreach (($data['components']['schemas'] ?? []) as $schema) {
				foreach (($schema['x-openregister-notifications'] ?? []) as $name => $rule) {
					foreach (($rule['recipients'] ?? []) as $recipient) {
						if (($recipient['kind'] ?? '') === 'groups') {
							foreach ($recipient['groups'] as $gid) {
								$addressed[$gid][] = $name;
							}
						}
					}
				}
			}
		}

		// The control: the five rules the cloud check named really address a group.
		$this->assertEqualsCanonicalizing(
			['newContact', 'newLead', 'leadWon', 'newEnquiry', 'newTicket'],
			($addressed['sales'] ?? [])
		);
		$this->assertSame([CreateSalesGroup::GROUP_ID], array_keys($addressed));
	}//end testEveryAddressedGroupIsCreated()
}//end class
