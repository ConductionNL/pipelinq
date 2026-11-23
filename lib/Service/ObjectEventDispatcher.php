<?php

/**
 * Pipelinq ObjectEventDispatcher.
 *
 * Service for dispatching notifications and activity events for object changes.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-crm-automation-triggers
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IUserSession;

/**
 * Service for dispatching object event notifications and activities.
 *
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-3
 */
class ObjectEventDispatcher {
	/**
	 * Constructor.
	 *
	 * @param NotificationService $notifyService The notification service.
	 * @param ActivityService $activityService The activity service.
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		private NotificationService $notifyService,
		private ActivityService $activityService,
		private IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Dispatch creation events for a new entity.
	 *
	 * @param string $entityType The entity type.
	 * @param string $title The entity title.
	 * @param string $objectId The object ID.
	 * @param string $assignee The assignee user ID.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-4
	 */
	public function dispatchCreated(string $entityType, string $title, string $objectId, string $assignee): void {
		$this->publishCreated(target: new ObjectEventTarget(title: $title, objectId: $objectId, assignee: $assignee, entityType: $entityType));
	}//end dispatchCreated()

	/**
	 * Publish the creation activity and notify the assignee, if any.
	 *
	 * @param ObjectEventTarget $target The new object.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-4
	 */
	private function publishCreated(ObjectEventTarget $target): void {
		$this->activityService->publishCreated(
			entityType: $target->entityType,
			title: $target->title,
			objectId: $target->objectId,
			affectedUser: $target->affectedUser()
		);

		if ($target->assignee !== '') {
			$author = $this->getCurrentUser();
			$this->notifyService->notifyAssignment(
				entityType: $target->entityType,
				title: $target->title,
				assigneeUserId: $target->assignee,
				objectId: $target->objectId,
				author: $author
			);
		}
	}//end publishCreated()

	/**
	 * Dispatch assignee change events.
	 *
	 * @param string $entityType The entity type.
	 * @param string $title The entity title.
	 * @param string $objectId The object ID.
	 * @param string $assignee The new assignee user ID.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-3
	 */
	public function dispatchAssigneeChange(
		string $entityType,
		string $title,
		string $objectId,
		string $assignee,
	): void {
		$this->publishAssigned(target: new ObjectEventTarget(title: $title, objectId: $objectId, assignee: $assignee, entityType: $entityType));
	}//end dispatchAssigneeChange()

	/**
	 * Publish the assignment activity and notify the new assignee.
	 *
	 * @param ObjectEventTarget $target The object and its new assignee.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-3
	 */
	private function publishAssigned(ObjectEventTarget $target): void {
		$this->activityService->publishAssigned(
			entityType: $target->entityType,
			title: $target->title,
			newAssignee: $target->assignee,
			objectId: $target->objectId
		);
		$this->notifyService->notifyAssignment(
			entityType: $target->entityType,
			title: $target->title,
			assigneeUserId: $target->assignee,
			objectId: $target->objectId,
			author: $this->getCurrentUser()
		);
	}//end publishAssigned()

	/**
	 * Dispatch stage change events for a lead.
	 *
	 * @param string $title The entity title.
	 * @param string $objectId The object ID.
	 * @param string $newStage The new stage name.
	 * @param string $assignee The current assignee.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-7
	 */
	public function dispatchStageChange(string $title, string $objectId, string $newStage, string $assignee): void {
		$this->publishStageChange(target: new ObjectEventTarget(title: $title, objectId: $objectId, assignee: $assignee), newStage: $newStage);
	}//end dispatchStageChange()

	/**
	 * Publish the stage change activity and notify the assignee, if any.
	 *
	 * @param ObjectEventTarget $target   The object and its assignee.
	 * @param string            $newStage The new stage name.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-7
	 */
	private function publishStageChange(ObjectEventTarget $target, string $newStage): void {
		$this->activityService->publishStageChanged(
			title: $target->title,
			newStage: $newStage,
			objectId: $target->objectId,
			affectedUser: $target->affectedUser()
		);

		if ($target->assignee !== '') {
			$this->notifyService->notifyStageChange(
				title: $target->title,
				newStage: $newStage,
				assigneeUserId: $target->assignee,
				objectId: $target->objectId,
				author: $this->getCurrentUser()
			);
		}
	}//end publishStageChange()

	/**
	 * Dispatch status change events for a request.
	 *
	 * @param string $title The entity title.
	 * @param string $objectId The object ID.
	 * @param string $newStatus The new status name.
	 * @param string $assignee The current assignee.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-8
	 */
	public function dispatchStatusChange(string $title, string $objectId, string $newStatus, string $assignee): void {
		$this->publishStatusChange(target: new ObjectEventTarget(title: $title, objectId: $objectId, assignee: $assignee), newStatus: $newStatus);
	}//end dispatchStatusChange()

	/**
	 * Publish the status change activity and notify the assignee, if any.
	 *
	 * @param ObjectEventTarget $target    The object and its assignee.
	 * @param string            $newStatus The new status name.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-8
	 */
	private function publishStatusChange(ObjectEventTarget $target, string $newStatus): void {
		$this->activityService->publishStatusChanged(
			title: $target->title,
			newStatus: $newStatus,
			objectId: $target->objectId,
			affectedUser: $target->affectedUser()
		);

		if ($target->assignee !== '') {
			$this->notifyService->notifyStatusChange(
				title: $target->title,
				newStatus: $newStatus,
				assigneeUserId: $target->assignee,
				objectId: $target->objectId,
				author: $this->getCurrentUser()
			);
		}
	}//end publishStatusChange()

	/**
	 * Dispatch deal won events for a lead.
	 *
	 * @param string $title The lead title.
	 * @param string $value The deal value.
	 * @param string $objectId The object ID.
	 * @param string $assignee The current assignee.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-6
	 */
	public function dispatchDealWon(string $title, string $value, string $objectId, string $assignee): void {
		$this->publishDealWon(target: new ObjectEventTarget(title: $title, objectId: $objectId, assignee: $assignee), value: $value);
	}//end dispatchDealWon()

	/**
	 * Publish the deal won activity and notify the assignee, if any.
	 *
	 * @param ObjectEventTarget $target The object and its assignee.
	 * @param string            $value The deal value.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-6
	 */
	private function publishDealWon(ObjectEventTarget $target, string $value): void {
		$this->activityService->publishDealWon(
			title: $target->title,
			value: $value,
			objectId: $target->objectId,
			affectedUser: $target->affectedUser()
		);

		if ($target->assignee !== '') {
			$this->notifyService->notifyDealWon(
				title: $target->title,
				value: $value,
				assigneeUserId: $target->assignee,
				objectId: $target->objectId,
				author: $this->getCurrentUser()
			);
		}
	}//end publishDealWon()

	/**
	 * Dispatch deal lost events for a lead.
	 *
	 * @param string $title The lead title.
	 * @param string $objectId The object ID.
	 * @param string $assignee The current assignee.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-5
	 */
	public function dispatchDealLost(string $title, string $objectId, string $assignee): void {
		$this->publishDealLost(target: new ObjectEventTarget(title: $title, objectId: $objectId, assignee: $assignee));
	}//end dispatchDealLost()

	/**
	 * Publish the deal lost activity and notify the assignee, if any.
	 *
	 * @param ObjectEventTarget $target The lead and its assignee.
	 *
	 * @return void
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-5
	 */
	private function publishDealLost(ObjectEventTarget $target): void {
		$this->activityService->publishDealLost(
			title: $target->title,
			objectId: $target->objectId,
			affectedUser: $target->affectedUser()
		);

		if ($target->assignee !== '') {
			$this->notifyService->notifyDealLost(
				title: $target->title,
				assigneeUserId: $target->assignee,
				objectId: $target->objectId,
				author: $this->getCurrentUser()
			);
		}
	}//end publishDealLost()

	/**
	 * Get the current user ID.
	 *
	 * @return string The current user ID or empty string.
	 */
	private function getCurrentUser(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user->getUID();
		}

		return '';
	}//end getCurrentUser()
}//end class
