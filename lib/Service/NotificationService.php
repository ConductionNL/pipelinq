<?php

/**
 * Pipelinq NotificationService.
 *
 * Service for sending Pipelinq notifications to users.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/notifications-activity/spec.md
 * @spec openspec/specs/notifications-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTime;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\NotificationServiceInterface;
use OCP\IConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Service for sending Pipelinq notifications.
 *
 * Lifecycle-driven notifications (lead won/lost, task completed/expired,
 * request completed, complaint resolved) are also declared declaratively as
 * `x-openregister-notifications` transition triggers in
 * `lib/Settings/pipelinq_register.json`, keyed on the lifecycle transition
 * NAMES (`win`, `lose`, `complete`, `expire`, `resolve`) so OpenRegister's
 * AnnotationNotificationDispatcher matches them against
 * `ObjectTransitionedEvent::getAction()`. Those annotation rules stay DORMANT
 * until pipelinq routes its status changes through OpenRegister's
 * TransitionEngine (today status is written directly via `saveObject`, which
 * does not dispatch `ObjectTransitionedEvent`). Until that migration lands the
 * imperative `send()` path below remains the live delivery mechanism and is
 * retained verbatim to preserve behaviour, including the per-user opt-out
 * settings in {@see self::SUBJECT_SETTING_MAP} that the annotation runtime does
 * not yet replicate.
 *
 * @spec openspec/changes/archive/2026-05-31-pipelinq-or-lifecycle-notification/tasks.md#task-3.1
 *
 * Seam 4 (declarative-notification migration) verdict — KEEP IMPERATIVE: OpenRegister's
 * AnnotationNotificationDispatcher cannot express this service's per-recipient
 * actor-suppression (`if ($author === $assigneeUserId) return;`) nor the per-category user
 * opt-out ({@see self::SUBJECT_SETTING_MAP} → notify_assignments/notify_stage_status/
 * notify_deals). Moving the assignee/stage/status notifications to `x-openregister-notifications`
 * would notify the actor, ignore opt-outs, and double-fire against the dormant declarative
 * `transition` rules. The broadcast/lifecycle notifications that ARE OR-expressible already live
 * declaratively in pipelinq_register.json — only the OR-non-expressible directed notifications
 * stay here.
 *
 * @spec openspec/changes/archive/2026-06-21-pipelinq-notifications-to-or/tasks.md#task-3.1
 */
class NotificationService implements NotificationServiceInterface {
	/**
	 * Map notification subjects to user setting keys.
	 *
	 * @var array<string, string>
	 */
	private const SUBJECT_SETTING_MAP = [
		'lead_assigned' => 'notify_assignments',
		'request_assigned' => 'notify_assignments',
		'task_assigned' => 'notify_assignments',
		'task_reassigned' => 'notify_assignments',
		'lead_stage_changed' => 'notify_stage_status',
		'request_status_changed' => 'notify_stage_status',
		'task_completed' => 'notify_stage_status',
		'task_expired' => 'notify_stage_status',
		'note_added' => 'notify_notes',
		'lead_won' => 'notify_deals',
		'lead_lost' => 'notify_deals',
	];

	/**
	 * Assignment subject per entity type; any other type is a lead assignment.
	 *
	 * @var array<string, string>
	 */
	private const ASSIGNMENT_SUBJECTS = [
		'request' => 'request_assigned',
		'task' => 'task_assigned',
	];

	/**
	 * Constructor.
	 *
	 * @param IManager $notificationManager The notification manager.
	 * @param IConfig $config The config service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IManager $notificationManager,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Notify a user about a lead or request assignment.
	 *
	 * @param string $entityType The entity type.
	 * @param string $title The entity title.
	 * @param string $assigneeUserId The assignee user ID.
	 * @param string $objectId The object ID.
	 * @param string $author The author user ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function notifyAssignment(
		string $entityType,
		string $title,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		$notice = new UserNotice(
			subject: (self::ASSIGNMENT_SUBJECTS[$entityType] ?? 'lead_assigned'),
			parameters: [
				'title' => $title,
				'entityType' => $entityType,
				'author' => $author,
			]
		);
		$about = new NoticedObject(objectType: $entityType, objectId: $objectId);
		$this->notifyUnlessAuthor(notice: $notice, recipient: new NoticeRecipient(userId: $assigneeUserId, object: $about));
	}//end notifyAssignment()

	/**
	 * Notify a user about a task completion.
	 *
	 * @param string $title The task subject.
	 * @param string $resultText The completion result text.
	 * @param string $userId The user to notify (typically the creator).
	 * @param string $objectId The task object ID.
	 * @param string $author The user who completed the task.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function notifyTaskCompleted(
		string $title,
		string $resultText,
		string $userId,
		string $objectId,
		string $author,
	): void {
		$notice = new UserNotice(
			subject: 'task_completed',
			parameters: [
				'title' => $title,
				'resultText' => $resultText,
				'author' => $author,
			]
		);
		$about = new NoticedObject(objectType: 'task', objectId: $objectId);
		$this->notifyUnlessAuthor(notice: $notice, recipient: new NoticeRecipient(userId: $userId, object: $about));
	}//end notifyTaskCompleted()

	/**
	 * Notify a user about a task reassignment.
	 *
	 * @param string $title The task subject.
	 * @param string $assigneeUserId The new assignee user ID.
	 * @param string $objectId The task object ID.
	 * @param string $author The user who reassigned.
	 * @param string $deadline The task deadline.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function notifyTaskReassigned(
		string $title,
		string $assigneeUserId,
		string $objectId,
		string $author,
		string $deadline = '',
	): void {
		$notice = new UserNotice(
			subject: 'task_reassigned',
			parameters: [
				'title' => $title,
				'author' => $author,
				'deadline' => $deadline,
			]
		);
		$about = new NoticedObject(objectType: 'task', objectId: $objectId);
		$this->notifyUnlessAuthor(notice: $notice, recipient: new NoticeRecipient(userId: $assigneeUserId, object: $about));
	}//end notifyTaskReassigned()

	/**
	 * Notify users about a task expiry or approaching deadline.
	 *
	 * @param string $title The task subject.
	 * @param string $userId The user to notify.
	 * @param string $objectId The task object ID.
	 * @param string $deadline The task deadline.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function notifyTaskExpired(
		string $title,
		string $userId,
		string $objectId,
		string $deadline = '',
	): void {
		$notice = new UserNotice(
			subject: 'task_expired',
			parameters: [
				'title' => $title,
				'deadline' => $deadline,
			]
		);
		$about = new NoticedObject(objectType: 'task', objectId: $objectId);
		$this->send(notice: $notice, recipient: new NoticeRecipient(userId: $userId, object: $about));
	}//end notifyTaskExpired()

	/**
	 * Notify a user about a lead stage change.
	 *
	 * @param string $title The entity title.
	 * @param string $newStage The new stage name.
	 * @param string $assigneeUserId The assignee user ID.
	 * @param string $objectId The object ID.
	 * @param string $author The author user ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function notifyStageChange(
		string $title,
		string $newStage,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		$notice = new UserNotice(
			subject: 'lead_stage_changed',
			parameters: [
				'title' => $title,
				'stage' => $newStage,
				'author' => $author,
			]
		);
		$about = new NoticedObject(objectType: 'lead', objectId: $objectId);
		$this->notifyUnlessAuthor(notice: $notice, recipient: new NoticeRecipient(userId: $assigneeUserId, object: $about));
	}//end notifyStageChange()

	/**
	 * Notify a user about a request status change.
	 *
	 * @param string $title The entity title.
	 * @param string $newStatus The new status name.
	 * @param string $assigneeUserId The assignee user ID.
	 * @param string $objectId The object ID.
	 * @param string $author The author user ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function notifyStatusChange(
		string $title,
		string $newStatus,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		$notice = new UserNotice(
			subject: 'request_status_changed',
			parameters: [
				'title' => $title,
				'status' => $newStatus,
				'author' => $author,
			]
		);
		$about = new NoticedObject(objectType: 'request', objectId: $objectId);
		$this->notifyUnlessAuthor(notice: $notice, recipient: new NoticeRecipient(userId: $assigneeUserId, object: $about));
	}//end notifyStatusChange()

	/**
	 * Notify a user about a new note on an entity.
	 *
	 * @param string $entityType The entity type.
	 * @param string $entityTitle The entity title.
	 * @param string $assigneeUserId The assignee user ID.
	 * @param string $objectId The object ID.
	 * @param string $author The author user ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function notifyNoteAdded(
		string $entityType,
		string $entityTitle,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		$notice = new UserNotice(
			subject: 'note_added',
			parameters: [
				'title' => $entityTitle,
				'entityType' => $entityType,
				'author' => $author,
			]
		);
		$about = new NoticedObject(objectType: $entityType, objectId: $objectId);
		$this->notifyUnlessAuthor(notice: $notice, recipient: new NoticeRecipient(userId: $assigneeUserId, object: $about));
	}//end notifyNoteAdded()

	/**
	 * Notify a user about a deal being won.
	 *
	 * @param string $title The lead title.
	 * @param string $value The deal value.
	 * @param string $assigneeUserId The assignee user ID.
	 * @param string $objectId The object ID.
	 * @param string $author The author user ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function notifyDealWon(
		string $title,
		string $value,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		$notice = new UserNotice(
			subject: 'lead_won',
			parameters: [
				'title' => $title,
				'value' => $value,
				'author' => $author,
			]
		);
		$about = new NoticedObject(objectType: 'lead', objectId: $objectId);
		$this->send(notice: $notice, recipient: new NoticeRecipient(userId: $assigneeUserId, object: $about));
	}//end notifyDealWon()

	/**
	 * Notify a user about a deal being lost.
	 *
	 * @param string $title The lead title.
	 * @param string $assigneeUserId The assignee user ID.
	 * @param string $objectId The object ID.
	 * @param string $author The author user ID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-activity-notify/tasks.md#task-2
	 */
	public function notifyDealLost(
		string $title,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		$notice = new UserNotice(
			subject: 'lead_lost',
			parameters: [
				'title' => $title,
				'author' => $author,
			]
		);
		$about = new NoticedObject(objectType: 'lead', objectId: $objectId);
		$this->send(notice: $notice, recipient: new NoticeRecipient(userId: $assigneeUserId, object: $about));
	}//end notifyDealLost()

	/**
	 * Send a generic notification to a user.
	 *
	 * Wrapper around the internal send() method for custom notification
	 * subjects that do not have a dedicated notifyXxx() helper.
	 *
	 * @param string $userId The target user ID.
	 * @param string $subject The notification subject.
	 * @param array $parameters The notification parameters.
	 * @param string $objectType The object type (optional).
	 * @param string $objectId The object ID (optional).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-activity-notify/tasks.md#task-3
	 */
	public function sendNotification(
		string $userId,
		string $subject,
		array $parameters,
		string $objectType = '',
		string $objectId = '',
	): void {
		$this->send(
			notice: new UserNotice(subject: $subject, parameters: $parameters),
			recipient: new NoticeRecipient(userId: $userId, object: new NoticedObject(objectType: $objectType, objectId: $objectId))
		);
	}//end sendNotification()

	/**
	 * Send a notice unless its author is the recipient (no self-notifications).
	 *
	 * @param UserNotice      $notice    The notice; its `author` parameter names the actor.
	 * @param NoticeRecipient $recipient The user to notify and the object it is about.
	 *
	 * @return void
	 */
	private function notifyUnlessAuthor(UserNotice $notice, NoticeRecipient $recipient): void {
		if ($notice->isBy(userId: $recipient->userId) === true) {
			return;
		}

		$this->send(notice: $notice, recipient: $recipient);
	}//end notifyUnlessAuthor()

	/**
	 * Send a notification to a user, unless they switched its category off.
	 *
	 * @param UserNotice      $notice    The subject and parameters.
	 * @param NoticeRecipient $recipient The user to notify and the object it is about.
	 *
	 * @return void
	 */
	private function send(UserNotice $notice, NoticeRecipient $recipient): void {
		// Check user setting for this notification type.
		if ($this->switchedOff(notice: $notice, userId: $recipient->userId) === true) {
			return;
		}

		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(AppIdentity::APP_ID)
				->setUser($recipient->userId)
				->setDateTime(new DateTime())
				->setObject(type: $recipient->object->objectType, id: $recipient->object->objectId)
				->setSubject(subject: $notice->subject, parameters: $notice->parameters);

			$this->notificationManager->notify($notification);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to send Pipelinq notification',
				[
					'subject' => $notice->subject,
					'userId' => $recipient->userId,
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end send()

	/**
	 * Whether the user switched off the setting category of the notice's subject.
	 *
	 * @param UserNotice $notice The notice.
	 * @param string     $userId The user.
	 *
	 * @return bool True when the user opted out; a subject without a category is never off.
	 */
	private function switchedOff(UserNotice $notice, string $userId): bool {
		$settingKey = self::SUBJECT_SETTING_MAP[$notice->subject] ?? null;
		if ($settingKey === null) {
			return false;
		}

		$enabled = $this->config->getUserValue(
			userId: $userId,
			appName: AppIdentity::APP_ID,
			key: $settingKey,
			default: 'true'
		);
		return $enabled !== 'true';
	}//end switchedOff()
}//end class
