<?php

/**
 * Pipelinq ObjectEventHandlerService.
 *
 * Service for handling OpenRegister object events and triggering Pipelinq notifications.
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
 * Service for handling object event business logic.
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-4
 */
class ObjectEventHandlerService
{
    /**
     * Constructor.
     *
     * @param SchemaMapService        $schemaMapService  The schema map service.
     * @param ObjectEventDispatcher   $dispatcher        The event dispatcher.
     * @param ObjectUpdateDiffService $diffService       The update diff service.
     * @param AutomationService       $automationService The automation service.
     */
    public function __construct(
        private SchemaMapService $schemaMapService,
        private ObjectEventDispatcher $dispatcher,
        private ObjectUpdateDiffService $diffService,
        private AutomationService $automationService,
    ) {
    }//end __construct()

    /**
     * Handle a newly created object.
     *
     * @param object $objectEntity The created object entity.
     *
     * @return void
     */
    public function handleCreated(object $objectEntity): void
    {
        $entityType = $this->schemaMapService->resolveEntityType(schemaId: $objectEntity->getSchema());
        if ($this->isRelevantEntityType(entityType: $entityType) === false) {
            return;
        }

        $data     = $objectEntity->getObject();
        $objectId = (string) $objectEntity->getId();

        $this->dispatcher->dispatchCreated(
            entityType: $entityType,
            title: ($data['title'] ?? ''),
            objectId: $objectId,
            assignee: ($data['assignee'] ?? '')
        );

        // Fire matching automations for entity creation events.
        $this->fireAutomations(
            trigger: $entityType.'_created',
            entityData: $data,
            objectId: $objectId
        );
    }//end handleCreated()

    /**
     * Handle an updated object.
     *
     * @param object  $newObject The new object entity.
     * @param ?object $oldObject The old object entity or null.
     *
     * @return void
     */
    public function handleUpdated(object $newObject, ?object $oldObject): void
    {
        $entityType = $this->schemaMapService->resolveEntityType(schemaId: $newObject->getSchema());
        if ($this->isRelevantEntityType(entityType: $entityType) === false) {
            return;
        }

        $newData  = $newObject->getObject();
        $oldData  = $this->extractOldData(oldObject: $oldObject);
        $title    = $newData['title'] ?? '';
        $objectId = (string) $newObject->getId();
        $assignee = $newData['assignee'] ?? '';

        $this->diffService->dispatchAssigneeChangeIfNeeded(
            oldData: $oldData,
            entityType: $entityType,
            title: $title,
            objectId: $objectId,
            assignee: $assignee,
            dispatcher: $this->dispatcher
        );

        if ($entityType === 'lead') {
            $this->diffService->dispatchStageChangeIfNeeded(
                newData: $newData,
                oldData: $oldData,
                title: $title,
                objectId: $objectId,
                assignee: $assignee,
                dispatcher: $this->dispatcher
            );
        }

        if ($entityType === 'request') {
            $this->diffService->dispatchStatusChangeIfNeeded(
                newData: $newData,
                oldData: $oldData,
                title: $title,
                objectId: $objectId,
                assignee: $assignee,
                dispatcher: $this->dispatcher
            );
        }

        // Fire matching automations for update events.
        $this->fireUpdateAutomations(
            entityType: $entityType,
            newData: $newData,
            oldData: $oldData,
            objectId: $objectId
        );
    }//end handleUpdated()

    /**
     * Check if the entity type is relevant for event handling.
     *
     * @param ?string $entityType The entity type or null.
     *
     * @return bool Whether the entity type is relevant.
     */
    private function isRelevantEntityType(?string $entityType): bool
    {
        if ($entityType === null) {
            return false;
        }

        return in_array($entityType, ['lead', 'request', 'contact'], true);
    }//end isRelevantEntityType()

    /**
     * Extract old data from an old object entity.
     *
     * @param ?object $oldObject The old object entity or null.
     *
     * @return array The old object data.
     */
    private function extractOldData(?object $oldObject): array
    {
        if ($oldObject !== null) {
            return $oldObject->getObject();
        }

        return [];
    }//end extractOldData()

    /**
     * Fire matching automations for entity creation.
     *
     * @param string $trigger    The trigger event name.
     * @param array  $entityData The entity data.
     * @param string $objectId   The object ID.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-4
     */
    private function fireAutomations(string $trigger, array $entityData, string $objectId): void
    {
        try {
            $entityData = array_merge($entityData, ['id' => $objectId]);

            // Get all automations matching this trigger and conditions
            $matchingAutomations = $this->automationService->getMatchingAutomations(
                trigger: $trigger,
                entity: $entityData
            );

            foreach ($matchingAutomations as $automation) {
                try {
                    $this->executeAutomation(
                        automation: $automation,
                        trigger: $trigger,
                        entityData: $entityData,
                        objectId: $objectId
                    );
                } catch (\Exception $e) {
                    // Log execution failure but continue with next automation
                    // Don't break the event flow
                }
            }
        } catch (\Exception $e) {
            // Automation failures must not break the main event flow.
        }
    }//end fireAutomations()

    /**
     * Execute an automation with its configured actions.
     *
     * @param array  $automation The automation configuration.
     * @param string $trigger    The trigger event type.
     * @param array  $entityData The entity data.
     * @param string $objectId   The object ID that triggered the automation.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-4
     */
    private function executeAutomation(
        array $automation,
        string $trigger,
        array $entityData,
        string $objectId
    ): void {
        try {
            $actionsExecuted = [];
            $actions = $automation['actions'] ?? [];

            // Execute each action in sequence
            foreach ($actions as $action) {
                $actionResult = $this->executeAction(
                    action: $action,
                    entityData: $entityData,
                    objectId: $objectId
                );
                $actionsExecuted[] = $actionResult;
            }

            // Fire webhook if configured
            if (!empty($automation['webhookUrl'] ?? '')) {
                $payload = $this->automationService->buildWebhookPayload(
                    automation: $automation,
                    trigger: $trigger,
                    entityData: $entityData
                );
                $webhookResult = $this->automationService->fireWebhook(
                    webhookUrl: $automation['webhookUrl'],
                    payload: $payload
                );
                $actionsExecuted[] = [
                    'type'   => 'webhook',
                    'result' => $webhookResult['status'] ?? 'unknown',
                ];
            }

            // Log execution
            $result = [
                'triggerEntity'  => $objectId,
                'actionsExecuted' => $actionsExecuted,
                'status'         => 'success',
            ];

            $this->automationService->logExecution(
                automationId: $automation['id'] ?? '',
                result: $result
            );

            // Update last run timestamp and count
            if (!empty($automation['id'] ?? '')) {
                $this->automationService->updateLastRun($automation['id']);
            }
        } catch (\Exception $e) {
            // Log failure but don't break the flow
            if (!empty($automation['id'] ?? '')) {
                $this->automationService->logExecution(
                    automationId: $automation['id'],
                    result: [
                        'status' => 'failure',
                        'error'  => $e->getMessage(),
                    ]
                );
            }
        }
    }//end executeAutomation()

    /**
     * Execute a single action from an automation.
     *
     * @param array  $action     The action configuration.
     * @param array  $entityData The entity data.
     * @param string $objectId   The object ID.
     *
     * @return array The action execution result.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-4
     */
    private function executeAction(array $action, array $entityData, string $objectId): array
    {
        try {
            $type = $action['type'] ?? 'unknown';
            $config = $action['config'] ?? [];

            return match ($type) {
                'assign_lead'       => $this->executeAssignLead(config: $config, objectId: $objectId),
                'move_stage'        => $this->executeMoveStage(config: $config, objectId: $objectId),
                'send_notification' => $this->executeSendNotification(
                    config: $config,
                    entityData: $entityData
                ),
                'update_field'      => $this->executeUpdateField(config: $config, objectId: $objectId),
                'add_note'          => $this->executeAddNote(config: $config, objectId: $objectId),
                default             => [
                    'type'   => $type,
                    'result' => 'skipped',
                    'reason' => 'Unknown action type',
                ],
            };
        } catch (\Exception $e) {
            return [
                'type'   => $action['type'] ?? 'unknown',
                'result' => 'failure',
                'error'  => $e->getMessage(),
            ];
        }
    }//end executeAction()

    /**
     * Execute an assign_lead action.
     *
     * @param array  $config   The action configuration.
     * @param string $objectId The object ID.
     *
     * @return array The action result.
     */
    private function executeAssignLead(array $config, string $objectId): array
    {
        // Implementation depends on OpenRegister API for updating objects
        // For now, return a placeholder
        return [
            'type'   => 'assign_lead',
            'result' => 'pending',
            'reason' => 'Requires OpenRegister object update API',
        ];
    }//end executeAssignLead()

    /**
     * Execute a move_stage action.
     *
     * @param array  $config   The action configuration.
     * @param string $objectId The object ID.
     *
     * @return array The action result.
     */
    private function executeMoveStage(array $config, string $objectId): array
    {
        return [
            'type'   => 'move_stage',
            'result' => 'pending',
            'reason' => 'Requires OpenRegister object update API',
        ];
    }//end executeMoveStage()

    /**
     * Execute a send_notification action.
     *
     * @param array $config     The action configuration.
     * @param array $entityData The entity data.
     *
     * @return array The action result.
     */
    private function executeSendNotification(array $config, array $entityData): array
    {
        return [
            'type'   => 'send_notification',
            'result' => 'pending',
            'reason' => 'Requires notification service integration',
        ];
    }//end executeSendNotification()

    /**
     * Execute an update_field action.
     *
     * @param array  $config   The action configuration.
     * @param string $objectId The object ID.
     *
     * @return array The action result.
     */
    private function executeUpdateField(array $config, string $objectId): array
    {
        return [
            'type'   => 'update_field',
            'result' => 'pending',
            'reason' => 'Requires OpenRegister object update API',
        ];
    }//end executeUpdateField()

    /**
     * Execute an add_note action.
     *
     * @param array  $config   The action configuration.
     * @param string $objectId The object ID.
     *
     * @return array The action result.
     */
    private function executeAddNote(array $config, string $objectId): array
    {
        return [
            'type'   => 'add_note',
            'result' => 'pending',
            'reason' => 'Requires notes service integration',
        ];
    }//end executeAddNote()

    /**
     * Fire matching automations for entity update events.
     *
     * @param string $entityType The entity type.
     * @param array  $newData    The new entity data.
     * @param array  $oldData    The old entity data.
     * @param string $objectId   The object ID.
     *
     * @return void
     */
    private function fireUpdateAutomations(
        string $entityType,
        array $newData,
        array $oldData,
        string $objectId,
    ): void {
        $triggers = [];

        if (($newData['assignee'] ?? '') !== ($oldData['assignee'] ?? '')) {
            $triggers[] = $entityType.'_assigned';
        }

        if ($entityType === 'lead') {
            if (($newData['stage'] ?? '') !== ($oldData['stage'] ?? '')) {
                $triggers[] = 'lead_stage_changed';
            }

            if (($newData['value'] ?? 0) !== ($oldData['value'] ?? 0)) {
                $triggers[] = 'lead_value_changed';
            }
        }

        if ($entityType === 'request'
            && ($newData['status'] ?? '') !== ($oldData['status'] ?? '')
        ) {
            $triggers[] = 'request_status_changed';
        }

        foreach ($triggers as $trigger) {
            $this->fireAutomations(
                trigger: $trigger,
                entityData: $newData,
                objectId: $objectId
            );
        }
    }//end fireUpdateAutomations()
}//end class
