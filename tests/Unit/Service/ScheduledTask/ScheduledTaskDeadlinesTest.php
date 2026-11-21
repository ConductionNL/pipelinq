<?php

/**
 * Scheduled task deadlines: the expiry cut comes from the configured seconds
 * (240 minutes when unset or invalid), an assignee is told about a task, and
 * an expired task reaches its assignee and the escalation group once each;
 * a notification that fails is logged and never stops the others.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\ScheduledTask
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\ScheduledTask;

use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\ScheduledTask\ScheduledTaskDeadlines;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Service\ScheduledTask\ScheduledTaskDeadlines
 */
class ScheduledTaskDeadlinesTest extends TestCase {

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	private NotificationService&MockObject $notifications;

	private IGroupManager&MockObject $groups;

	private LoggerInterface&MockObject $logger;

	private ScheduledTaskDeadlines $deadlines;

	/**
	 * Deadlines over mocked notification, config, group and logger boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'pipelinq' ? ($this->config[$key] ?? $default) : 'wrong-app')
		);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->groups        = $this->createMock(IGroupManager::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->deadlines     = new ScheduledTaskDeadlines($this->notifications, $appConfig, $this->groups, $this->logger);

	}//end setUp()

	/**
	 * A group whose members have these uids.
	 *
	 * @param array<int, string> $uids The members.
	 *
	 * @return IGroup The group.
	 */
	private function groupOf(array $uids): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn(
			array_map(fn (string $uid): IUser => $this->createConfiguredMock(IUser::class, ['getUID' => $uid]), $uids)
		);

		return $group;

	}//end groupOf()

	/**
	 * Record the users notifyTaskExpired was called for, with its arguments.
	 *
	 * @return \ArrayObject<int, array<string, string>> Filled as calls arrive.
	 */
	private function recordExpired(): \ArrayObject {
		$calls = new \ArrayObject();
		$this->notifications->method('notifyTaskExpired')->willReturnCallback(
			function (string $title, string $userId, string $objectId, string $deadline = '') use ($calls): void {
				$calls[] = ['title' => $title, 'userId' => $userId, 'objectId' => $objectId, 'deadline' => $deadline];
			}
		);

		return $calls;

	}//end recordExpired()

	public function testTheExpiryCutDefaultsTo240Minutes(): void {
		$this->assertSame(240, $this->deadlines->expiryCutMinutes());

	}//end testTheExpiryCutDefaultsTo240Minutes()

	public function testTheExpiryCutIsTheConfiguredSecondsInWholeMinutes(): void {
		$this->config['task_expiry.escalation_threshold_seconds'] = '3659';
		$this->assertSame(60, $this->deadlines->expiryCutMinutes());

		$this->config['task_expiry.escalation_threshold_seconds'] = '0';
		$this->assertSame(0, $this->deadlines->expiryCutMinutes());

	}//end testTheExpiryCutIsTheConfiguredSecondsInWholeMinutes()

	public function testAnInvalidOrNegativeExpiryCutFallsBackTo240(): void {
		foreach (['soon', '-60', ''] as $value) {
			$this->config['task_expiry.escalation_threshold_seconds'] = $value;
			$this->assertSame(240, $this->deadlines->expiryCutMinutes(), $value);
		}

	}//end testAnInvalidOrNegativeExpiryCutFallsBackTo240()

	public function testTheAssigneeIsToldAboutTheTask(): void {
		$this->notifications->expects($this->once())->method('notifyAssignment')->with(
			'task',
			'Call back',
			'anna',
			'task-1',
			'system'
		);

		$this->deadlines->notifyAssignee(['id' => 'task-1', 'subject' => 'Call back', 'assigneeUserId' => 'anna']);

	}//end testTheAssigneeIsToldAboutTheTask()

	public function testAnUntitledTaskIsCalledScheduledTask(): void {
		$this->notifications->expects($this->once())->method('notifyAssignment')->with('task', 'Scheduled task', 'anna', '', 'system');

		$this->deadlines->notifyAssignee(['assigneeUserId' => 'anna']);

	}//end testAnUntitledTaskIsCalledScheduledTask()

	public function testAnUnassignedTaskNotifiesNobody(): void {
		$this->notifications->expects($this->never())->method('notifyAssignment');

		$this->deadlines->notifyAssignee(['id' => 'task-1']);
		$this->deadlines->notifyAssignee(['id' => 'task-1', 'assigneeUserId' => '']);

	}//end testAnUnassignedTaskNotifiesNobody()

	public function testAFailedAssignmentNotificationIsLogged(): void {
		$failure = new RuntimeException('notifier down');
		$this->notifications->method('notifyAssignment')->willThrowException($failure);
		$this->logger->expects($this->once())->method('error')
			->with('ScheduledTaskService: notification dispatch failed', ['exception' => $failure]);

		$this->deadlines->notifyAssignee(['id' => 'task-1', 'assigneeUserId' => 'anna']);

	}//end testAFailedAssignmentNotificationIsLogged()

	public function testAnExpiredTaskReachesTheAssigneeAndTheEscalationGroupOnceEach(): void {
		$this->config['task_expiry.escalation_group'] = 'leads';
		$this->groups->method('get')->with('leads')->willReturn($this->groupOf(['lead-1', 'anna', 'lead-2']));
		$calls = $this->recordExpired();

		$this->deadlines->notifyExpired(['id' => 'task-1', 'subject' => 'Call back', 'assigneeUserId' => 'anna', 'deadline' => '2026-11-12T09:00:00Z']);

		$this->assertSame(['anna', 'lead-1', 'lead-2'], array_column($calls->getArrayCopy(), 'userId'));
		$this->assertSame(
			['title' => 'Call back', 'userId' => 'anna', 'objectId' => 'task-1', 'deadline' => '2026-11-12T09:00:00Z'],
			$calls[0]
		);

	}//end testAnExpiredTaskReachesTheAssigneeAndTheEscalationGroupOnceEach()

	public function testWithoutAnEscalationGroupOnlyTheAssigneeIsTold(): void {
		$this->groups->expects($this->never())->method('get');
		$calls = $this->recordExpired();

		$this->deadlines->notifyExpired(['id' => 'task-1', 'assigneeUserId' => 'anna']);

		$this->assertSame(['anna'], array_column($calls->getArrayCopy(), 'userId'));
		$this->assertSame('Scheduled task', $calls[0]['title']);
		$this->assertSame('', $calls[0]['deadline']);

	}//end testWithoutAnEscalationGroupOnlyTheAssigneeIsTold()

	public function testAMissingEscalationGroupLeavesTheAssigneeOnly(): void {
		$this->config['task_expiry.escalation_group'] = 'deleted';
		$this->groups->method('get')->willReturn(null);
		$calls = $this->recordExpired();

		$this->deadlines->notifyExpired(['id' => 'task-1', 'assigneeUserId' => 'anna']);

		$this->assertSame(['anna'], array_column($calls->getArrayCopy(), 'userId'));

	}//end testAMissingEscalationGroupLeavesTheAssigneeOnly()

	public function testAnUnassignedExpiredTaskStillEscalates(): void {
		$this->config['task_expiry.escalation_group'] = 'leads';
		$this->groups->method('get')->willReturn($this->groupOf(['lead-1']));
		$calls = $this->recordExpired();

		$this->deadlines->notifyExpired(['id' => 'task-1']);

		$this->assertSame(['lead-1'], array_column($calls->getArrayCopy(), 'userId'));

	}//end testAnUnassignedExpiredTaskStillEscalates()

	public function testOneFailedExpiryNotificationDoesNotStopTheRest(): void {
		$this->config['task_expiry.escalation_group'] = 'leads';
		$this->groups->method('get')->willReturn($this->groupOf(['lead-1']));
		$reached = [];
		$this->notifications->method('notifyTaskExpired')->willReturnCallback(
			function (string $title, string $userId) use (&$reached): void {
				if ($userId === 'anna') {
					throw new RuntimeException('mailbox full');
				}

				$reached[] = $userId;
			}
		);
		$this->logger->expects($this->once())->method('error')
			->with('ScheduledTaskService: expiry notification dispatch failed', $this->arrayHasKey('exception'));

		$this->deadlines->notifyExpired(['id' => 'task-1', 'assigneeUserId' => 'anna']);

		$this->assertSame(['lead-1'], $reached);

	}//end testOneFailedExpiryNotificationDoesNotStopTheRest()
}//end class
