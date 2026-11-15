<?php

/**
 * Pipelinq ActivityService.
 *
 * Service for publishing Pipelinq activity events to the Nextcloud activity stream.
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
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-capture-all-interaction-types
 * @spec openspec/specs/notifications-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\Activity\IManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing Pipelinq activity events.
 *
 * The `setSubject()` call in {@see self::publish()} writes to the Nextcloud
 * Activity stream (`OCP\Activity\IManager`/`IEvent`), which is a distinct
 * surface from notifications. It is intentionally NOT migrated to
 * `x-openregister-notifications`: the annotation runtime emits
 * `nc-notification` channel notifications and does not feed the Activity
 * stream, so replacing this call would silently drop the activity feed.
 * Notification-channel delivery for lifecycle transitions is covered
 * declaratively by the schema annotations (see {@see NotificationService}).
 *
 * @spec openspec/changes/archive/2026-05-31-pipelinq-or-lifecycle-notification/tasks.md#task-3.2
 */
class ActivityService {
	/**
	 * The "created" activity subject per entity type. A type not listed here
	 * has no pipelinq created activity.
	 *
	 * @var array<string, string>
	 */
	private const CREATED_SUBJECTS = [
		'lead' => 'lead_created',
		'request' => 'request_created',
	];

	/**
	 * Constructor.
	 *
	 * @param IManager $activityManager The activity manager.
	 * @param IUserSession $userSession The user session.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IManager $activityManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Publish a created event for a lead or request.
	 *
	 * Only the entity types in {@see self::CREATED_SUBJECTS} have a pipelinq
	 * "created" activity. Any other type publishes nothing: a contact used to
	 * fall through to `lead_created` and read "Lead created: " with an empty
	 * name, while OpenRegister already writes "Contact <name> created" for it.
	 *
	 * @param string $entityType The entity type.
	 * @param string $title The entity title.
	 * @param string $objectId The object ID.
	 * @param ?string $affectedUser The affected user or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-capture-all-interaction-types
	 * @spec openspec/specs/notifications-activity/spec.md#requirement-each-object-type-publishes-its-own-created-activity
	 */
	public function publishCreated(
		string $entityType,
		string $title,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$type = self::CREATED_SUBJECTS[$entityType] ?? null;
		if ($type === null) {
			return;
		}

		$subject = new ActivitySubject(subject: $type, type: 'pipelinq_assignment', parameters: ['title' => $title, 'entityType' => $entityType]);
		$this->publish(subject: $subject, object: new ActivityObject(objectType: $entityType, objectId: $objectId, affectedUser: $affectedUser));
	}//end publishCreated()

	/**
	 * Publish an assignment event for a lead or request.
	 *
	 * @param string $entityType The entity type.
	 * @param string $title The entity title.
	 * @param string $newAssignee The newly assigned user.
	 * @param string $objectId The object ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-capture-all-interaction-types
	 */
	public function publishAssigned(
		string $entityType,
		string $title,
		string $newAssignee,
		string $objectId,
	): void {
		$type = 'lead_assigned';
		if ($entityType === 'request') {
			$type = 'request_assigned';
		}

		$parameters = ['title' => $title, 'entityType' => $entityType, 'assignee' => $newAssignee];
		$subject = new ActivitySubject(subject: $type, type: 'pipelinq_assignment', parameters: $parameters);
		$this->publish(subject: $subject, object: new ActivityObject(objectType: $entityType, objectId: $objectId, affectedUser: $newAssignee));
	}//end publishAssigned()

	/**
	 * Publish a stage change event for a lead.
	 *
	 * @param string $title The entity title.
	 * @param string $newStage The new stage name.
	 * @param string $objectId The object ID.
	 * @param ?string $affectedUser The affected user or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-capture-all-interaction-types
	 */
	public function publishStageChanged(
		string $title,
		string $newStage,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$parameters = ['title' => $title, 'stage' => $newStage];
		$subject = new ActivitySubject(subject: 'lead_stage_changed', type: 'pipelinq_stage_status', parameters: $parameters);
		$this->publish(subject: $subject, object: new ActivityObject(objectType: 'lead', objectId: $objectId, affectedUser: $affectedUser));
	}//end publishStageChanged()

	/**
	 * Publish a status change event for a request.
	 *
	 * @param string $title The entity title.
	 * @param string $newStatus The new status name.
	 * @param string $objectId The object ID.
	 * @param ?string $affectedUser The affected user or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-capture-all-interaction-types
	 */
	public function publishStatusChanged(
		string $title,
		string $newStatus,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$parameters = ['title' => $title, 'status' => $newStatus];
		$subject = new ActivitySubject(subject: 'request_status_changed', type: 'pipelinq_stage_status', parameters: $parameters);
		$this->publish(subject: $subject, object: new ActivityObject(objectType: 'request', objectId: $objectId, affectedUser: $affectedUser));
	}//end publishStatusChanged()

	/**
	 * Publish a note added event.
	 *
	 * @param string $entityType The entity type.
	 * @param string $entityTitle The entity title.
	 * @param string $objectId The object ID.
	 * @param ?string $affectedUser The affected user or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-capture-all-interaction-types
	 */
	public function publishNoteAdded(
		string $entityType,
		string $entityTitle,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$parameters = ['title' => $entityTitle, 'entityType' => $entityType];
		$subject = new ActivitySubject(subject: 'note_added', type: 'pipelinq_notes', parameters: $parameters);
		$this->publish(subject: $subject, object: new ActivityObject(objectType: $entityType, objectId: $objectId, affectedUser: $affectedUser));
	}//end publishNoteAdded()

	/**
	 * Publish a deal won event.
	 *
	 * @param string $title The lead title.
	 * @param string $value The deal value.
	 * @param string $objectId The object ID.
	 * @param ?string $affectedUser The affected user or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function publishDealWon(
		string $title,
		string $value,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$this->publish(
			subject: new ActivitySubject(subject: 'lead_won', type: 'pipelinq_deals', parameters: ['title' => $title, 'value' => $value]),
			object: new ActivityObject(objectType: 'lead', objectId: $objectId, affectedUser: $affectedUser)
		);
	}//end publishDealWon()

	/**
	 * Publish a deal lost event.
	 *
	 * @param string $title The lead title.
	 * @param string $objectId The object ID.
	 * @param ?string $affectedUser The affected user or null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-activity-notify/tasks.md#task-1
	 */
	public function publishDealLost(
		string $title,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$this->publish(
			subject: new ActivitySubject(subject: 'lead_lost', type: 'pipelinq_deals', parameters: ['title' => $title]),
			object: new ActivityObject(objectType: 'lead', objectId: $objectId, affectedUser: $affectedUser)
		);
	}//end publishDealLost()

	/**
	 * Publish an activity event.
	 *
	 * @param ActivitySubject $subject What the activity says.
	 * @param ActivityObject  $object  What it is about and who it is addressed to.
	 *
	 * @return void
	 */
	private function publish(ActivitySubject $subject, ActivityObject $object): void {
		try {
			$author = $this->currentAuthor();

			$event = $this->activityManager->generateEvent();
			$event->setApp(AppIdentity::APP_ID)
				->setType($subject->type)
				->setAuthor($author)
				->setTimestamp(time())
				->setSubject(subject: $subject->subject, parameters: $subject->parameters)
				->setObject(objectType: $object->objectType, objectId: (int)$object->objectId, objectName: $subject->parameters['title'] ?? '');

			$recipient = $object->recipient(author: $author);
			if ($recipient !== '') {
				$event->setAffectedUser($recipient);
			}

			$this->activityManager->publish($event);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to publish Pipelinq activity',
				['subject' => $subject->subject, 'type' => $subject->type, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end publish()

	/**
	 * The signed-in user's UID, or an empty string when nobody is signed in.
	 *
	 * @return string
	 */
	private function currentAuthor(): string {
		$currentUser = $this->userSession->getUser();
		$author = '';
		if ($currentUser !== null) {
			$author = $currentUser->getUID();
		}

		return $author;
	}//end currentAuthor()
}//end class
