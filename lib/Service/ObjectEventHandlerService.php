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
 */
class ObjectEventHandlerService
{
    /**
     * Constructor.
     *
     * @param SchemaMapService        $schemaMapService The schema map service.
     * @param ObjectEventDispatcher   $dispatcher       The event dispatcher.
     * @param ObjectUpdateDiffService $diffService      The update diff service.
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
            trigger: $entityType . '_created',
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
     */
    private function fireAutomations(string $trigger, array $entityData, string $objectId): void
    {
        try {
            $payload = $this->automationService->buildWebhookPayload(
                automation: ['name' => $trigger],
                trigger: $trigger,
                entityData: array_merge($entityData, ['id' => $objectId])
            );
            // Webhook firing is handled by the automation execution engine.
            // This is a placeholder for the full automation matching pipeline.
        } catch (\Exception $e) {
            // Automation failures must not break the main event flow.
        }
    }//end fireAutomations()


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
            $triggers[] = $entityType . '_assigned';
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
