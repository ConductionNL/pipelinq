<?php

/**
 * ExportJobSwitchOffNotifier: one notification per administrator.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-administrators-are-told-once-when-a-job-is-switched-off-req-efs-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\ExportJobSwitchOffNotifier;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Every member of the admin group gets one notification naming the job, the
 * failures and the last error; without an admin group nobody is told.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportJobSwitchOffNotifier
 */
class ExportJobSwitchOffNotifierTest extends TestCase {

	/**
	 * A user with this uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Each administrator gets one notification about the job.
	 *
	 * @return void
	 */
	public function testEveryAdministratorIsToldOnce(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user('ann'), $this->user('bob')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->once())->method('get')->with('admin')->willReturn($group);

		$sent = [];
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->exactly(2))->method('sendNotification')->willReturnCallback(
			function (string $userId, string $subject, array $parameters, string $objectType = '', string $objectId = '') use (&$sent): void {
				$sent[] = [$userId, $subject, $parameters, $objectType, $objectId];
			}
		);

		(new ExportJobSwitchOffNotifier(notificationService: $notifications, groupManager: $groups))->notifySwitchedOff(
			job: ['id' => 'j-1', 'name' => 'Leads and pipeline value'],
			failures: 3,
			lastError: 'connection refused'
		);

		$parameters = ['title' => 'Leads and pipeline value', 'failures' => 3, 'error' => 'connection refused'];
		$this->assertSame(
			[
				['ann', 'export_job_switched_off', $parameters, 'exportJob', 'j-1'],
				['bob', 'export_job_switched_off', $parameters, 'exportJob', 'j-1'],
			],
			$sent
		);
	}//end testEveryAdministratorIsToldOnce()

	/**
	 * A job known only by its uuid is still named by it.
	 *
	 * @return void
	 */
	public function testAJobKnownByItsUuidIsReferencedByIt(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user('ann')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('sendNotification')->with(
			'ann',
			'export_job_switched_off',
			['title' => '', 'failures' => 1, 'error' => ''],
			'exportJob',
			'u-9'
		);

		(new ExportJobSwitchOffNotifier(notificationService: $notifications, groupManager: $groups))->notifySwitchedOff(
			job: ['uuid' => 'u-9'],
			failures: 1,
			lastError: ''
		);
	}//end testAJobKnownByItsUuidIsReferencedByIt()

	/**
	 * Without an admin group nothing is sent.
	 *
	 * @return void
	 */
	public function testWithoutAnAdminGroupNobodyIsTold(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn(null);
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('sendNotification');

		(new ExportJobSwitchOffNotifier(notificationService: $notifications, groupManager: $groups))->notifySwitchedOff(
			job: ['id' => 'j-1', 'name' => 'Nightly'],
			failures: 3,
			lastError: 'boom'
		);
	}//end testWithoutAnAdminGroupNobodyIsTold()
}//end class
