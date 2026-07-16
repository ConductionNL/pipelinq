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
 *
 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Service for detecting field changes between old and new object data.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
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
     *
     * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
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
     *
     * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
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

        // Detect deal won or lost based on stage name.
        $wonNames   = ['won', 'gewonnen', 'closed won'];
        $lostNames  = ['lost', 'verloren', 'closed lost'];
        $stageLower = strtolower($newStage);

        if (in_array($stageLower, $wonNames, true) === true) {
            $dispatcher->dispatchDealWon(
                title: $title,
                value: (string) ($newData['value'] ?? '0'),
                objectId: $objectId,
                assignee: $assignee
            );
            return;
        }

        if (in_array($stageLower, $lostNames, true) === true) {
            $dispatcher->dispatchDealLost(
                title: $title,
                objectId: $objectId,
                assignee: $assignee
            );
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
     * Check if the lead value has genuinely changed.
     *
     * Both sides are cast to float before comparison to prevent a spurious
     * `lead_value_changed` event when JSON deserialisation returns `100` (int)
     * for a value that was stored as `100.0` (float).
     *
     * @param array $newData The new object data.
     * @param array $oldData The old object data.
     *
     * @return bool True when the value changed by more than epsilon.
     *
     * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
     */
    public function hasValueChanged(array $newData, array $oldData): bool
    {
        $newValue = (float) ($newData['value'] ?? 0);
        $oldValue = (float) ($oldData['value'] ?? 0);

        // Use a small epsilon to avoid float rounding noise; CRM values are
        // currency amounts so 0.0001 is well below any meaningful delta.
        return abs($newValue - $oldValue) >= 0.0001;
    }//end hasValueChanged()

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
     *
     * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
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
