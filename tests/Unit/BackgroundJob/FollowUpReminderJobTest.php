<?php

/**
 * Unit tests for FollowUpReminderJob.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/calendar-deepening/tasks.md#3-followupreminderjob
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\FollowUpReminderJob;
use OCA\Pipelinq\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Window edges, the once-per-occurrence marker, the no-op without the leaf or register, and group assignees.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\FollowUpReminderJob
 */
class FollowUpReminderJobTest extends TestCase {

	/**
	 * The fixed current time of every run.
	 *
	 * @var int
	 */
	private const NOW = 1800000000;

	/**
	 * The calendar leaf's service id.
	 *
	 * @var string
	 */
	private const LEAF = 'OCA\\OpenRegister\\Service\\CalendarLinkService';

	/**
	 * OpenRegister's object service id.
	 *
	 * @var string
	 */
	private const OBJECTS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The app config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [
		'register' => 'reg-1',
		'lead_schema' => 'lead-s',
		'task_schema' => 'task-s',
	];

	/**
	 * Rows per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Linked events per lead.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $events = [];

	/**
	 * Notifications sent: [userId, subject, parameters, objectType, objectId].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $sent = [];

	/**
	 * Which OpenRegister services the container holds.
	 *
	 * @var array<string, bool>
	 */
	private array $services = [self::OBJECTS => true, self::LEAF => true];

	/**
	 * A moment relative to now.
	 *
	 * @param int $seconds Seconds from now.
	 *
	 * @return string The ISO 8601 moment.
	 */
	private function at(int $seconds): string {
		return gmdate('Y-m-d\TH:i:sP', (self::NOW + $seconds));
	}//end at()

	/**
	 * Build the job over the in-memory doubles.
	 *
	 * @param IGroupManager|null $groups The group manager.
	 *
	 * @return FollowUpReminderJob
	 */
	private function job(?IGroupManager $groups = null): FollowUpReminderJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$appConfig->method('getKeys')->willReturnCallback(fn (): array => array_keys($this->config));
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			}
		);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('sendNotification')->willReturnCallback(
			function (string $userId, string $subject, array $parameters, string $objectType = '', string $objectId = ''): void {
				$this->sent[] = [$userId, $subject, $parameters, $objectType, $objectId];
			}
		);

		$objects = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param FollowUpReminderJobTest $test The test holding the rows.
			 */
			public function __construct(private FollowUpReminderJobTest $test) {
			}//end __construct()

			/**
			 * The rows of the filtered schema.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config): array {
				return $this->test->rowsOf(schema: $config['filters']['schema'], register: $config['filters']['register']);
			}//end findAll()
		};
		$leaf = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param FollowUpReminderJobTest $test The test holding the events.
			 */
			public function __construct(private FollowUpReminderJobTest $test) {
			}//end __construct()

			/**
			 * The linked events of a lead.
			 *
			 * @param string $objectUuid The lead.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function getLinkedEvents(string $objectUuid): array {
				return $this->test->eventsOf(leadId: $objectUuid);
			}//end getLinkedEvents()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(fn (string $id): bool => ($this->services[$id] ?? false));
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				self::OBJECTS => $objects,
				self::LEAF => $leaf,
			}
		);

		return new FollowUpReminderJob(
			$time,
			$appConfig,
			$notifications,
			$container,
			($groups ?? $this->createMock(IGroupManager::class)),
			$this->createMock(LoggerInterface::class)
		);
	}//end job()

	/**
	 * The rows the object service double returns.
	 *
	 * @param string $schema   The schema.
	 * @param string $register The register.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function rowsOf(string $schema, string $register): array {
		if ($register !== 'reg-1') {
			throw new RuntimeException('unexpected register ' . $register);
		}

		return ($this->rows[$schema] ?? []);
	}//end rowsOf()

	/**
	 * The events the leaf double returns.
	 *
	 * @param string $leadId The lead.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function eventsOf(string $leadId): array {
		if ($leadId === 'lead-broken') {
			throw new RuntimeException('caldav down');
		}

		return ($this->events[$leadId] ?? []);
	}//end eventsOf()

	/**
	 * Run the job once.
	 *
	 * @param FollowUpReminderJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(FollowUpReminderJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * A lead with an assignee and one meeting starting in an hour.
	 *
	 * @return void
	 */
	private function leadWithMeeting(): void {
		$this->rows['lead-s'] = [['id' => 'lead-1', 'title' => 'Fiber rollout', 'assignee' => 'anna', 'status' => 'open']];
		$this->events['lead-1'] = [['uid' => 'ev-1', 'summary' => 'Site visit', 'dtstart' => $this->at(3600)]];
	}//end leadWithMeeting()

	/**
	 * The assignee is reminded of the meeting, linked to the lead.
	 *
	 * @return void
	 */
	public function testTheLeadAssigneeIsRemindedOfALinkedMeeting(): void {
		$this->leadWithMeeting();

		$this->runJob($this->job());

		$this->assertSame(
			[['anna', 'followup_event_upcoming', ['title' => 'Site visit', 'lead' => 'Fiber rollout', 'start' => $this->at(3600)], 'lead', 'lead-1']],
			$this->sent
		);
	}//end testTheLeadAssigneeIsRemindedOfALinkedMeeting()

	/**
	 * A second run inside the window sends nothing new.
	 *
	 * @return void
	 */
	public function testASecondRunDoesNotRemindAgain(): void {
		$this->leadWithMeeting();
		$job = $this->job();

		$this->runJob($job);
		$this->runJob($job);

		$this->assertCount(1, $this->sent);
	}//end testASecondRunDoesNotRemindAgain()

	/**
	 * A moved meeting is a new occurrence and is reminded again.
	 *
	 * @return void
	 */
	public function testAMovedMeetingIsANewOccurrence(): void {
		$this->leadWithMeeting();
		$job = $this->job();
		$this->runJob($job);

		$this->events['lead-1'][0]['dtstart'] = $this->at(7200);
		$this->runJob($job);

		$this->assertSame([$this->at(3600), $this->at(7200)], array_map(static fn (array $sent): string => $sent[2]['start'], $this->sent));
	}//end testAMovedMeetingIsANewOccurrence()

	/**
	 * The window includes now and its end, and excludes what is past or beyond it.
	 *
	 * @return void
	 */
	public function testWindowEdges(): void {
		$job = $this->job();
		$end = (self::NOW + 86400);

		$this->assertTrue($job->inWindow(moment: $this->at(0), now: self::NOW, windowEnd: $end));
		$this->assertTrue($job->inWindow(moment: $this->at(86400), now: self::NOW, windowEnd: $end));
		$this->assertFalse($job->inWindow(moment: $this->at(-1), now: self::NOW, windowEnd: $end));
		$this->assertFalse($job->inWindow(moment: $this->at(86401), now: self::NOW, windowEnd: $end));
		$this->assertFalse($job->inWindow(moment: 'not a date', now: self::NOW, windowEnd: $end));
		$this->assertFalse($job->inWindow(moment: null, now: self::NOW, windowEnd: $end));
	}//end testWindowEdges()

	/**
	 * The window comes from app config, and falls back to 24 hours when unset or not positive.
	 *
	 * @return void
	 */
	public function testTheWindowIsConfigurable(): void {
		$this->assertSame(24, $this->job()->windowHours());

		$this->config['followup_reminder_window_hours'] = '2';
		$this->assertSame(2, $this->job()->windowHours());

		$this->config['followup_reminder_window_hours'] = '0';
		$this->assertSame(24, $this->job()->windowHours());
	}//end testTheWindowIsConfigurable()

	/**
	 * A meeting beyond a narrowed window is not reminded yet.
	 *
	 * @return void
	 */
	public function testAMeetingBeyondTheWindowWaits(): void {
		$this->leadWithMeeting();
		$this->events['lead-1'][0]['dtstart'] = $this->at(3 * 3600);
		$this->config['followup_reminder_window_hours'] = '2';

		$this->runJob($this->job());

		$this->assertSame([], $this->sent);
	}//end testAMeetingBeyondTheWindowWaits()

	/**
	 * Closed leads, leads without an assignee and events without a UID are skipped; a failing read is skipped too.
	 *
	 * @return void
	 */
	public function testOnlyOpenAssignedLeadsAndIdentifiedEventsAreReminded(): void {
		$this->rows['lead-s'] = [
			['id' => 'lead-won', 'assignee' => 'anna', 'status' => 'won'],
			['id' => 'lead-free', 'assignee' => '', 'status' => 'open'],
			['id' => 'lead-broken', 'assignee' => 'anna', 'status' => 'open'],
			['@self' => ['id' => 'lead-2'], 'title' => 'Second', 'assignee' => 'bram', 'status' => 'open'],
		];
		$soon = $this->at(600);
		$this->events = [
			'lead-won' => [['uid' => 'a', 'dtstart' => $soon]],
			'lead-free' => [['uid' => 'b', 'dtstart' => $soon]],
			'lead-2' => [['summary' => 'No uid', 'dtstart' => $soon], ['uid' => 'c', 'summary' => 'Call', 'dtstart' => $soon]],
		];

		$this->runJob($this->job());

		$this->assertSame([['bram', 'lead-2']], array_map(static fn (array $sent): array => [$sent[0], $sent[4]], $this->sent));
	}//end testOnlyOpenAssignedLeadsAndIdentifiedEventsAreReminded()

	/**
	 * Without a register the job does nothing, and never asks OpenRegister.
	 *
	 * @return void
	 */
	public function testNoRegisterIsANoOp(): void {
		$this->leadWithMeeting();
		$this->config['register'] = '';
		$this->services = [];

		$this->runJob($this->job());

		$this->assertSame([], $this->sent);
	}//end testNoRegisterIsANoOp()

	/**
	 * Without OpenRegister nothing is sent.
	 *
	 * @return void
	 */
	public function testNoOpenRegisterIsANoOp(): void {
		$this->leadWithMeeting();
		$this->services = [];

		$this->runJob($this->job());

		$this->assertSame([], $this->sent);
	}//end testNoOpenRegisterIsANoOp()

	/**
	 * Without the calendar leaf no meeting is reminded, but task deadlines still are.
	 *
	 * @return void
	 */
	public function testWithoutTheLeafOnlyTasksAreReminded(): void {
		$this->leadWithMeeting();
		$this->services = [self::OBJECTS => true];
		$this->rows['task-s'] = [['id' => 'task-1', 'type' => 'followUpTask', 'status' => 'open', 'subject' => 'Call back', 'deadline' => $this->at(60), 'assigneeUserId' => 'anna']];

		$this->runJob($this->job());

		$this->assertSame(['followup_task_due'], array_column($this->sent, 1));
	}//end testWithoutTheLeafOnlyTasksAreReminded()

	/**
	 * A follow-up task due in the window reminds its assignee once, linked to its lead.
	 *
	 * @return void
	 */
	public function testAFollowUpTaskDueSoonIsRemindedOnceLinkedToItsLead(): void {
		$this->rows['task-s'] = [
			['id' => 'task-1', 'type' => 'followUpTask', 'status' => 'in_progress', 'subject' => 'Send quote', 'deadline' => $this->at(1800), 'assigneeUserId' => 'anna', 'leadId' => 'lead-9'],
			['id' => 'task-2', 'type' => 'followUpTask', 'status' => 'open', 'subject' => 'No lead', 'deadline' => $this->at(1800), 'assigneeUserId' => 'bram'],
			['id' => 'task-3', 'type' => 'callbackRequest', 'status' => 'open', 'deadline' => $this->at(1800), 'assigneeUserId' => 'anna'],
			['id' => 'task-4', 'type' => 'followUpTask', 'status' => 'completed', 'deadline' => $this->at(1800), 'assigneeUserId' => 'anna'],
			['id' => 'task-5', 'type' => 'followUpTask', 'status' => 'open', 'deadline' => $this->at(-60), 'assigneeUserId' => 'anna'],
		];
		$job = $this->job();

		$this->runJob($job);
		$this->runJob($job);

		$this->assertSame(
			[
				['anna', 'followup_task_due', ['title' => 'Send quote', 'deadline' => $this->at(1800)], 'lead', 'lead-9'],
				['bram', 'followup_task_due', ['title' => 'No lead', 'deadline' => $this->at(1800)], 'task', 'task-2'],
			],
			$this->sent
		);
	}//end testAFollowUpTaskDueSoonIsRemindedOnceLinkedToItsLead()

	/**
	 * A task assigned to a group reminds every member.
	 *
	 * @return void
	 */
	public function testAGroupAssigneeRemindsEveryMember(): void {
		$members = [];
		foreach (['anna', 'bram'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$members[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($members);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnMap([['sales', $group], ['gone', null]]);

		$job = $this->job(groups: $groups);

		$this->assertSame(['anna', 'bram'], $job->assignees(task: ['assigneeGroupId' => 'sales']));
		$this->assertSame(['carla'], $job->assignees(task: ['assigneeUserId' => 'carla', 'assigneeGroupId' => 'sales']));
		$this->assertSame([], $job->assignees(task: ['assigneeGroupId' => 'gone']));
		$this->assertSame([], $job->assignees(task: []));
	}//end testAGroupAssigneeRemindsEveryMember()

	/**
	 * Markers older than twice the window are removed; others and unrelated keys stay.
	 *
	 * @return void
	 */
	public function testStaleMarkersArePruned(): void {
		$this->config['followup_notified_old'] = (string)(self::NOW - (49 * 3600));
		$this->config['followup_notified_new'] = (string)(self::NOW - 3600);

		$this->runJob($this->job());

		$this->assertArrayNotHasKey('followup_notified_old', $this->config);
		$this->assertArrayHasKey('followup_notified_new', $this->config);
		$this->assertSame('reg-1', $this->config['register']);
	}//end testStaleMarkersArePruned()
}//end class
