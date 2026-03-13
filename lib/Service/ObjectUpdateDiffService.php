<?php

/**
 * Pipelinq ObjectUpdateDiffService.
 *
 * Service for detecting field changes between old and new object data.
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

/**
 * Service for detecting field changes between old and new object data.
 */
class ObjectUpdateDiffService
{
    /**
     * Check if the assignee has changed and dispatch if so.
     *
     * @param array                 $oldData    The old object data.
     * @param string                $entityType The entity type.
     * @param string                $title      The entity title.
     * @param string                $objectId   The object ID.
     * @param string                $assignee   The current assignee.
     * @param ObjectEventDispatcher $dispatcher The event dispatcher.
     *
     * @return void
     */
    public function dispatchAssigneeChangeIfNeeded(
        array $oldData,
        string $entityType,
        string $title,
        string $objectId,
        string $assignee,
        ObjectEventDispatcher $dispatcher,
    ): void {
        $oldAssignee = $oldData['assignee'] ?? '';
        if ($assignee === '' || $assignee === $oldAssignee) {
            return;
        }

        $dispatcher->dispatchAssigneeChange(
            entityType: $entityType,
            title: $title,
            objectId: $objectId,
            assignee: $assignee
        );
    }//end dispatchAssigneeChangeIfNeeded()

    /**
     * Check if the stage has changed and dispatch if so.
     *
     * @param array                 $newData    The new object data.
     * @param array                 $oldData    The old object data.
     * @param string                $title      The entity title.
     * @param string                $objectId   The object ID.
     * @param string                $assignee   The current assignee.
     * @param ObjectEventDispatcher $dispatcher The event dispatcher.
     *
     * @return void
     */
    public function dispatchStageChangeIfNeeded(
        array $newData,
        array $oldData,
        string $title,
        string $objectId,
        string $assignee,
        ObjectEventDispatcher $dispatcher,
    ): void {
        $newStage = $newData['stage'] ?? '';
        $oldStage = $oldData['stage'] ?? '';
        if ($newStage === '' || $newStage === $oldStage) {
            return;
        }

        $dispatcher->dispatchStageChange(
            title: $title,
            objectId: $objectId,
            newStage: $newStage,
            assignee: $assignee
        );
    }//end dispatchStageChangeIfNeeded()

    /**
     * Check if the status has changed and dispatch if so.
     *
     * @param array                 $newData    The new object data.
     * @param array                 $oldData    The old object data.
     * @param string                $title      The entity title.
     * @param string                $objectId   The object ID.
     * @param string                $assignee   The current assignee.
     * @param ObjectEventDispatcher $dispatcher The event dispatcher.
     *
     * @return void
     */
    public function dispatchStatusChangeIfNeeded(
        array $newData,
        array $oldData,
        string $title,
        string $objectId,
        string $assignee,
        ObjectEventDispatcher $dispatcher,
    ): void {
        $newStatus = $newData['status'] ?? '';
        $oldStatus = $oldData['status'] ?? '';
        if ($newStatus === '' || $newStatus === $oldStatus) {
            return;
        }

        $dispatcher->dispatchStatusChange(
            title: $title,
            objectId: $objectId,
            newStatus: $newStatus,
            assignee: $assignee
        );
    }//end dispatchStatusChangeIfNeeded()
}//end class
