<?php

/**
 * Pipelinq FollowUpReminderJob.
 *
 * Reminds assignees of upcoming follow-ups: meetings linked to their open leads
 * through the calendar leaf, and follow-up tasks whose deadline falls due.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Util\CastingPayloadToArray;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that reminds assignees ahead of a follow-up.
 *
 * Two sources, both read-only:
 * - the calendar leaf's linked events of every open lead with an assignee,
 *   read through OpenRegister's CalendarLinkService (never CalDAV);
 * - open `followUpTask` tasks whose deadline falls inside the window.
 *
 * Each occurrence is reminded once: the marker key carries the event UID or
 * task id together with its start or deadline, so a moved meeting is a new
 * occurrence. Without OpenRegister, the calendar leaf or a configured register
 * the job logs once and does nothing. It writes to no calendar.
 *
 * Time-based dispatch needs a job: the notification dialect of OpenRegister
 * triggers on object lifecycle events, not on proximity to a date.
 *
 * @spec openspec/changes/calendar-deepening/tasks.md#3-followupreminderjob
 */
class FollowUpReminderJob extends TimedJob {
	use CastingPayloadToArray;

	/**
	 * Interval in seconds (15 minutes), as CallbackOverdueJob.
	 *
	 * @var int
	 */
	private const INTERVAL = 900;

	/**
	 * App config key for the look-ahead window in hours.
	 *
	 * @var string
	 */
	public const WINDOW_KEY = 'followup_reminder_window_hours';

	/**
	 * Default look-ahead window in hours.
	 *
	 * @var int
	 */
	public const DEFAULT_WINDOW_HOURS = 24;

	/**
	 * App config key prefix of the reminded-occurrence markers.
	 *
	 * @var string
	 */
	public const NOTIFIED_KEY_PREFIX = 'followup_notified_';

	/**
	 * Notification subject of an upcoming linked meeting.
	 *
	 * @var string
	 */
	public const SUBJECT_EVENT = 'followup_event_upcoming';

	/**
	 * Notification subject of a follow-up task falling due.
	 *
	 * @var string
	 */
	public const SUBJECT_TASK = 'followup_task_due';

	/**
	 * The calendar leaf's read service in OpenRegister.
	 *
	 * @var string
	 */
	private const CALENDAR_LEAF = 'OCA\\OpenRegister\\Service\\CalendarLinkService';

	/**
	 * OpenRegister's object service.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Task statuses that are still open.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_TASK_STATUSES = ['open', 'in_progress'];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time                The time factory.
	 * @param IAppConfig          $appConfig           The app config.
	 * @param NotificationService $notificationService The notification service.
	 * @param ContainerInterface  $container           The container, to reach OpenRegister optionally.
	 * @param IGroupManager       $groupManager        The group manager, for group assignees.
	 * @param LoggerInterface     $logger              The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IAppConfig $appConfig,
		private readonly NotificationService $notificationService,
		private readonly ContainerInterface $container,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Run one reminder pass.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-deepening/tasks.md#3-followupreminderjob
	 */
	protected function run(mixed $argument): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			$this->logger->info('FollowUpReminderJob: no register configured, skipping');
			return;
		}

		$objectService = $this->optionalService(serviceId: self::OBJECT_SERVICE);
		if ($objectService === null || method_exists($objectService, 'findAll') === false) {
			$this->logger->info('FollowUpReminderJob: OpenRegister is unavailable, skipping');
			return;
		}

		$now = $this->time->getTime();
		$windowEnd = ($now + ($this->windowHours() * 3600));

		$this->remindLeads(objectService: $objectService, register: $register, now: $now, windowEnd: $windowEnd);
		$this->remindTasks(objectService: $objectService, register: $register, now: $now, windowEnd: $windowEnd);
		$this->pruneStaleMarkers(now: $now);
	}//end run()

	/**
	 * The look-ahead window in hours; the default when unset or not positive.
	 *
	 * @return int The window in hours.
	 *
	 * @spec openspec/changes/calendar-deepening/tasks.md#3-followupreminderjob
	 */
	public function windowHours(): int {
		$hours = (int)$this->appConfig->getValueString(Application::APP_ID, self::WINDOW_KEY, (string)self::DEFAULT_WINDOW_HOURS);
		if ($hours <= 0) {
			return self::DEFAULT_WINDOW_HOURS;
		}

		return $hours;
	}//end windowHours()

	/**
	 * Whether a moment lies inside the window: from now up to and including its end.
	 *
	 * @param mixed $moment    The date-time string.
	 * @param int   $now       The current timestamp.
	 * @param int   $windowEnd The timestamp the window ends at.
	 *
	 * @return bool True when the moment is inside the window.
	 *
	 * @spec openspec/changes/calendar-deepening/tasks.md#3-followupreminderjob
	 */
	public function inWindow(mixed $moment, int $now, int $windowEnd): bool {
		if (is_string($moment) === false || $moment === '') {
			return false;
		}

		$timestamp = strtotime($moment);
		if ($timestamp === false) {
			return false;
		}

		return ($timestamp >= $now && $timestamp <= $windowEnd);
	}//end inWindow()

	/**
	 * Whether an occurrence was already reminded.
	 *
	 * @param string $occurrence The occurrence key (kind, id and moment).
	 *
	 * @return bool True when a marker exists.
	 *
	 * @spec openspec/changes/calendar-deepening/tasks.md#3-followupreminderjob
	 */
	public function wasRecentlyNotified(string $occurrence): bool {
		return $this->appConfig->getValueString(Application::APP_ID, $this->markerKey(occurrence: $occurrence), '') !== '';
	}//end wasRecentlyNotified()

	/**
	 * Mark an occurrence as reminded now.
	 *
	 * @param string $occurrence The occurrence key (kind, id and moment).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-deepening/tasks.md#3-followupreminderjob
	 */
	public function markNotified(string $occurrence): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			$this->markerKey(occurrence: $occurrence),
			(string)$this->time->getTime()
		);
	}//end markNotified()

	/**
	 * The app config key of an occurrence marker; hashed to stay within the key length.
	 *
	 * @param string $occurrence The occurrence key.
	 *
	 * @return string The app config key.
	 */
	private function markerKey(string $occurrence): string {
		return self::NOTIFIED_KEY_PREFIX . sha1($occurrence);
	}//end markerKey()

	/**
	 * Remind lead assignees of linked meetings starting inside the window.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param int    $now           The current timestamp.
	 * @param int    $windowEnd     The timestamp the window ends at.
	 *
	 * @return void
	 */
	private function remindLeads(object $objectService, string $register, int $now, int $windowEnd): void {
		$leaf = $this->optionalService(serviceId: self::CALENDAR_LEAF);
		if ($leaf === null || method_exists($leaf, 'getLinkedEvents') === false) {
			$this->logger->info('FollowUpReminderJob: calendar leaf unavailable, skipping meeting reminders');
			return;
		}

		foreach ($this->rows(objectService: $objectService, register: $register, schemaKey: 'lead_schema') as $lead) {
			$assignee = (string)($lead['assignee'] ?? '');
			$leadId = $this->objectId(row: $lead);
			if ($assignee === '' || $leadId === '' || (string)($lead['status'] ?? 'open') !== 'open') {
				continue;
			}

			foreach ($this->linkedEvents(leaf: $leaf, leadId: $leadId) as $event) {
				$this->remindEvent(
					event: $event,
					lead: ['id' => $leadId, 'title' => (string)($lead['title'] ?? ''), 'assignee' => $assignee],
					now: $now,
					windowEnd: $windowEnd
				);
			}
		}
	}//end remindLeads()

	/**
	 * Remind one lead's assignee of one linked event when it starts inside the window.
	 *
	 * @param array<string, mixed>  $event     The leaf's event row.
	 * @param array<string, string> $lead      The lead: id, title and assignee.
	 * @param int                   $now       The current timestamp.
	 * @param int                   $windowEnd The timestamp the window ends at.
	 *
	 * @return void
	 */
	private function remindEvent(array $event, array $lead, int $now, int $windowEnd): void {
		$uid = (string)($event['uid'] ?? '');
		$start = ($event['dtstart'] ?? null);
		if ($uid === '' || $this->inWindow(moment: $start, now: $now, windowEnd: $windowEnd) === false) {
			return;
		}

		$occurrence = 'event|' . $lead['id'] . '|' . $uid . '|' . (string)$start;
		if ($this->wasRecentlyNotified(occurrence: $occurrence) === true) {
			return;
		}

		$this->notificationService->sendNotification(
			userId: $lead['assignee'],
			subject: self::SUBJECT_EVENT,
			parameters: [
				'title' => (string)($event['summary'] ?? ''),
				'lead' => $lead['title'],
				'start' => (string)$start,
			],
			objectType: 'lead',
			objectId: $lead['id']
		);
		$this->markNotified(occurrence: $occurrence);
	}//end remindEvent()

	/**
	 * Remind assignees of open follow-up tasks whose deadline falls inside the window.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param int    $now           The current timestamp.
	 * @param int    $windowEnd     The timestamp the window ends at.
	 *
	 * @return void
	 */
	private function remindTasks(object $objectService, string $register, int $now, int $windowEnd): void {
		foreach ($this->rows(objectService: $objectService, register: $register, schemaKey: 'task_schema') as $task) {
			$taskId = $this->objectId(row: $task);
			$deadline = ($task['deadline'] ?? null);
			if ($taskId === '' || $this->isOpenFollowUp(task: $task) === false
				|| $this->inWindow(moment: $deadline, now: $now, windowEnd: $windowEnd) === false
			) {
				continue;
			}

			$occurrence = 'task|' . $taskId . '|' . (string)$deadline;
			if ($this->wasRecentlyNotified(occurrence: $occurrence) === true) {
				continue;
			}

			[$objectType, $objectId] = $this->taskLink(task: $task, taskId: $taskId);
			foreach ($this->assignees(task: $task) as $userId) {
				$this->notificationService->sendNotification(
					userId: $userId,
					subject: self::SUBJECT_TASK,
					parameters: ['title' => (string)($task['subject'] ?? ''), 'deadline' => (string)$deadline],
					objectType: $objectType,
					objectId: $objectId
				);
			}

			$this->markNotified(occurrence: $occurrence);
		}//end foreach
	}//end remindTasks()

	/**
	 * Whether a task is an open follow-up task.
	 *
	 * @param array<string, mixed> $task The task.
	 *
	 * @return bool True for an open or in-progress followUpTask.
	 */
	private function isOpenFollowUp(array $task): bool {
		return ($task['type'] ?? '') === 'followUpTask'
			&& in_array((string)($task['status'] ?? 'open'), self::OPEN_TASK_STATUSES, true) === true;
	}//end isOpenFollowUp()

	/**
	 * Where a task reminder links to: the lead it follows up, else the task itself.
	 *
	 * @param array<string, mixed> $task   The task.
	 * @param string               $taskId The task id.
	 *
	 * @return array{0: string, 1: string} The object type and id.
	 */
	private function taskLink(array $task, string $taskId): array {
		$leadId = (string)($task['leadId'] ?? '');
		if ($leadId !== '') {
			return ['lead', $leadId];
		}

		return ['task', $taskId];
	}//end taskLink()

	/**
	 * The users to remind of a task: its assignee, else the members of its group.
	 *
	 * @param array<string, mixed> $task The task.
	 *
	 * @return array<int, string> The user ids.
	 *
	 * @spec openspec/changes/calendar-deepening/tasks.md#3-followupreminderjob
	 */
	public function assignees(array $task): array {
		$userId = (string)($task['assigneeUserId'] ?? '');
		if ($userId !== '') {
			return [$userId];
		}

		$groupId = (string)($task['assigneeGroupId'] ?? '');
		if ($groupId === '') {
			return [];
		}

		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return [];
		}

		$userIds = [];
		foreach ($group->getUsers() as $user) {
			$userIds[] = $user->getUID();
		}

		return $userIds;
	}//end assignees()

	/**
	 * The rows of a configured schema as arrays; none when unconfigured or unreadable.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $schemaKey     The app config key of the schema.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(object $objectService, string $register, string $schemaKey): array {
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($schema === '') {
			return [];
		}

		try {
			$found = $objectService->findAll(config: ['filters' => ['register' => $register, 'schema' => $schema]]);
		} catch (Throwable $e) {
			$this->logger->warning('FollowUpReminderJob: failed to read ' . $schemaKey, ['exception' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach (($found ?? []) as $row) {
			$rows[] = $this->toArray(object: $row);
		}

		return $rows;
	}//end rows()

	/**
	 * The linked events of a lead through the calendar leaf; none when the read fails.
	 *
	 * @param object $leaf   The calendar leaf's read service.
	 * @param string $leadId The lead uuid.
	 *
	 * @return array<int, array<string, mixed>> The event rows.
	 */
	private function linkedEvents(object $leaf, string $leadId): array {
		try {
			$events = $leaf->getLinkedEvents(objectUuid: $leadId);
		} catch (Throwable $e) {
			$this->logger->warning('FollowUpReminderJob: failed to read linked events', ['exception' => $e->getMessage()]);
			return [];
		}

		if (is_array($events) === false) {
			return [];
		}

		return array_values(array_filter($events, 'is_array'));
	}//end linkedEvents()

	/**
	 * The uuid of an OpenRegister row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or '' when it has none.
	 */
	private function objectId(array $row): string {
		return (string)($row['id'] ?? $row['uuid'] ?? $row['@self']['id'] ?? '');
	}//end objectId()

	/**
	 * A service from the container, or null when the providing app is absent.
	 *
	 * @param string $serviceId The service id.
	 *
	 * @return object|null The service.
	 */
	private function optionalService(string $serviceId): ?object {
		try {
			if ($this->container->has($serviceId) === false) {
				return null;
			}

			$service = $this->container->get($serviceId);
		} catch (Throwable) {
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end optionalService()

	/**
	 * Remove markers older than twice the window: their occurrence has passed.
	 *
	 * @param int $now The current timestamp.
	 *
	 * @return void
	 */
	private function pruneStaleMarkers(int $now): void {
		try {
			$cutoff = ($now - (2 * $this->windowHours() * 3600));
			foreach ($this->appConfig->getKeys(Application::APP_ID) as $key) {
				if (str_starts_with($key, self::NOTIFIED_KEY_PREFIX) === false) {
					continue;
				}

				$marked = (int)$this->appConfig->getValueString(Application::APP_ID, $key, '0');
				if ($marked > 0 && $marked < $cutoff) {
					$this->appConfig->deleteKey(Application::APP_ID, $key);
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('FollowUpReminderJob: failed to prune reminder markers', ['exception' => $e->getMessage()]);
		}
	}//end pruneStaleMarkers()
}//end class
