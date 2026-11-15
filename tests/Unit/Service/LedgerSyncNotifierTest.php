<?php

/**
 * Unit tests for LedgerSyncNotifier.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\LedgerSyncNotifier;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * LedgerSyncNotifier tells every administrator that a ledger sync failed.
 */
class LedgerSyncNotifierTest extends TestCase {

	/**
	 * Each admin gets one notification about the project.
	 *
	 * @return void
	 */
	public function testEveryAdminIsNotified(): void {
		$users = [];
		foreach (['admin', 'beheer'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		$sent = [];
		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('sendNotification')->willReturnCallback(
			static function (string $userId, string $subject, array $parameters, string $objectType='', string $objectId='') use (&$sent): void {
				$sent[] = [$userId, $subject, $parameters, $objectType, $objectId];
			}
		);

		(new LedgerSyncNotifier(notificationService: $notifications, groupManager: $groups))->notifyFailure(projectName: 'Verbouwing', eventType: 'invoice.created', uuid: 'proj-1');

		self::assertSame(
			[
				['admin', 'ledger_sync_failed', ['projectName' => 'Verbouwing', 'eventType' => 'invoice.created'], 'project', 'proj-1'],
				['beheer', 'ledger_sync_failed', ['projectName' => 'Verbouwing', 'eventType' => 'invoice.created'], 'project', 'proj-1'],
			],
			$sent
		);
	}//end testEveryAdminIsNotified()

	/**
	 * Without an admin group nobody is notified.
	 *
	 * @return void
	 */
	public function testWithoutAnAdminGroupNobodyIsNotified(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn(null);
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects(self::never())->method('sendNotification');

		(new LedgerSyncNotifier(notificationService: $notifications, groupManager: $groups))->notifyFailure(projectName: 'p', eventType: 'e', uuid: 'u');
	}//end testWithoutAnAdminGroupNobodyIsNotified()
}//end class
