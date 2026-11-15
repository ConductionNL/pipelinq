<?php

/**
 * Behavioural tests for the AdminNotice trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Service\NoticedObject;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Util\AdminNotice;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Every admin gets the notice; without an admin group nobody does.
 *
 * @covers \OCA\Pipelinq\Util\AdminNotice
 * @uses \OCA\Pipelinq\Service\NoticedObject
 */
class AdminNoticeTest extends TestCase {

	/**
	 * Build a host over the given admin group and notification service.
	 *
	 * @param IGroup|null         $group         The admin group.
	 * @param NotificationService $notifications The notification service.
	 *
	 * @return object The host.
	 */
	private function host(?IGroup $group, NotificationService $notifications): object {
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('get')->willReturn($group);

		return new class($groups, $notifications) {
			use AdminNotice;

			/**
			 * @param IGroupManager       $groupManager        The groups.
			 * @param NotificationService $notificationService The notifications.
			 */
			public function __construct(private IGroupManager $groupManager, private NotificationService $notificationService) {
			}

			/**
			 * @return void
			 */
			public function notify(): void {
				$this->notifyAdmins(subject: 'thing_failed', parameters: ['title' => 't'], about: new NoticedObject(objectType: 'thing', objectId: 'o1'));
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testNotifyAdmins(): void {
		$alice = $this->createStub(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$bob = $this->createStub(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$group = $this->createStub(IGroup::class);
		$group->method('getUsers')->willReturn([$alice, $bob]);

		$sent = [];
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->exactly(2))->method('sendNotification')->willReturnCallback(
			static function (string $userId, string $subject, array $parameters, string $objectType, string $objectId) use (&$sent): void {
				$sent[] = [$userId, $subject, $parameters, $objectType, $objectId];
			}
		);
		$this->host($group, $notifications)->notify();
		$this->assertSame(
			[['alice', 'thing_failed', ['title' => 't'], 'thing', 'o1'], ['bob', 'thing_failed', ['title' => 't'], 'thing', 'o1']],
			$sent
		);

		$silent = $this->createMock(NotificationService::class);
		$silent->expects($this->never())->method('sendNotification');
		$this->host(null, $silent)->notify();
	}//end testNotifyAdmins()
}//end class
