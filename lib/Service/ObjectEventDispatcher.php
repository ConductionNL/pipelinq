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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IUserSession;

/**
 * Service for dispatching object event notifications and activities.
 */
class ObjectEventDispatcher
{
    /**
     * Constructor.
     *
     * @param NotificationService $notifyService   The notification service.
     * @param ActivityService     $activityService The activity service.
     * @param IUserSession        $userSession     The user session.
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
     * @param string $title      The entity title.
     * @param string $objectId   The object ID.
     * @param string $assignee   The assignee user ID.
     *
     * @return void
     */
    public function dispatchCreated(string $entityType, string $title, string $objectId, string $assignee): void
    {
        $this->activityService->publishCreated(
            entityType: $entityType,
            title: $title,
            objectId: $objectId,
            affectedUser: $this->nullIfEmpty(value: $assignee)
        );

        if ($assignee !== '') {
            $author = $this->getCurrentUser();
            $this->notifyService->notifyAssignment(
                entityType: $entityType,
                title: $title,
                assigneeUserId: $assignee,
                objectId: $objectId,
                author: $author
            );
        }
    }//end dispatchCreated()

    /**
     * Dispatch assignee change events.
     *
     * @param string $entityType The entity type.
     * @param string $title      The entity title.
     * @param string $objectId   The object ID.
     * @param string $assignee   The new assignee user ID.
     *
     * @return void
     */
    public function dispatchAssigneeChange(
        string $entityType,
        string $title,
        string $objectId,
        string $assignee,
    ): void {
        $this->activityService->publishAssigned(
            entityType: $entityType,
            title: $title,
            newAssignee: $assignee,
            objectId: $objectId
        );
        $this->notifyService->notifyAssignment(
            entityType: $entityType,
            title: $title,
            assigneeUserId: $assignee,
            objectId: $objectId,
            author: $this->getCurrentUser()
        );
    }//end dispatchAssigneeChange()

    /**
     * Dispatch stage change events for a lead.
     *
     * @param string $title    The entity title.
     * @param string $objectId The object ID.
     * @param string $newStage The new stage name.
     * @param string $assignee The current assignee.
     *
     * @return void
     */
    public function dispatchStageChange(string $title, string $objectId, string $newStage, string $assignee): void
    {
        $this->activityService->publishStageChanged(
            title: $title,
            newStage: $newStage,
            objectId: $objectId,
            affectedUser: $this->nullIfEmpty(value: $assignee)
        );

        if ($assignee !== '') {
            $this->notifyService->notifyStageChange(
                title: $title,
                newStage: $newStage,
                assigneeUserId: $assignee,
                objectId: $objectId,
                author: $this->getCurrentUser()
            );
        }
    }//end dispatchStageChange()

    /**
     * Dispatch status change events for a request.
     *
     * @param string $title     The entity title.
     * @param string $objectId  The object ID.
     * @param string $newStatus The new status name.
     * @param string $assignee  The current assignee.
     *
     * @return void
     */
    public function dispatchStatusChange(string $title, string $objectId, string $newStatus, string $assignee): void
    {
        $this->activityService->publishStatusChanged(
            title: $title,
            newStatus: $newStatus,
            objectId: $objectId,
            affectedUser: $this->nullIfEmpty(value: $assignee)
        );

        if ($assignee !== '') {
            $this->notifyService->notifyStatusChange(
                title: $title,
                newStatus: $newStatus,
                assigneeUserId: $assignee,
                objectId: $objectId,
                author: $this->getCurrentUser()
            );
        }
    }//end dispatchStatusChange()

    /**
     * Return null if the value is empty, otherwise return the value.
     *
     * @param string $value The value to check.
     *
     * @return ?string The value or null.
     */
    private function nullIfEmpty(string $value): ?string
    {
        if ($value !== '') {
            return $value;
        }

        return null;
    }//end nullIfEmpty()

    /**
     * Get the current user ID.
     *
     * @return string The current user ID or empty string.
     */
    private function getCurrentUser(): string
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            return $user->getUID();
        }

        return '';
    }//end getCurrentUser()
}//end class
