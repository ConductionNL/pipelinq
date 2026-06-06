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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-47
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for handling object event business logic.
 *
 * @spec openspec/changes/migrate-automation-to-flow-leaf/tasks.md#task-1.1
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-4.1
 */
class ObjectEventHandlerService
{
    /**
     * Constructor.
     *
     * @param SchemaMapService        $schemaMapService   The schema map service.
     * @param ObjectEventDispatcher   $dispatcher         The event dispatcher.
     * @param ObjectUpdateDiffService $diffService        The update diff service.
     * @param ?AutomationService      $automationService  Optional automation engine (crm-workflow-automation).
     * @param ?LoggerInterface        $logger             Optional logger for automation dispatch warnings.
     */
    public function __construct(
        private SchemaMapService $schemaMapService,
        private ObjectEventDispatcher $dispatcher,
        private ObjectUpdateDiffService $diffService,
        private ?AutomationService $automationService=null,
        private ?LoggerInterface $logger=null,
    ) {
    }//end __construct()

    /**
     * Handle a newly created object.
     *
     * @param object $objectEntity The created object entity.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-47
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

        $this->dispatchAutomations(
            trigger: ($entityType.'_created'),
            entityId: $objectId,
            entityData: $data
        );
    }//end handleCreated()

    /**
     * Handle an updated object.
     *
     * @param object  $newObject The new object entity.
     * @param ?object $oldObject The old object entity or null.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-47
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

        // Lead stage transitions get their own automation trigger.
        if ($entityType === 'lead' && (($newData['stage'] ?? null) !== ($oldData['stage'] ?? null))) {
            $this->dispatchAutomations(
                trigger: 'lead_stage_changed',
                entityId: $objectId,
                entityData: $newData
            );
        }

        // Request status transitions.
        if ($entityType === 'request' && (($newData['status'] ?? null) !== ($oldData['status'] ?? null))) {
            $this->dispatchAutomations(
                trigger: 'request_status_changed',
                entityId: $objectId,
                entityData: $newData
            );
        }

        // Always evaluate marketing-segment trigger on contact/lead update.
        if ($entityType === 'contact' || $entityType === 'lead') {
            $this->dispatchAutomations(
                trigger: 'marketing_segment_match',
                entityId: $objectId,
                entityData: $newData
            );
        }
    }//end handleUpdated()

    /**
     * Queue matching automations for background execution.
     *
     * Resolves matching automation rules and logs them as queued so that
     * the entity save response is never blocked by action execution. The
     * background runner (BackgroundJob, scheduled separately) is expected
     * to pick up automationLog entries with status="queued".
     *
     * REQ-NFR-001 explicitly forbids inline execution on the request path.
     *
     * @param string $trigger    Trigger name (e.g. lead_created).
     * @param string $entityId   Entity UUID/slug.
     * @param array  $entityData Entity payload snapshot.
     *
     * @return void
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-4.1
     */
    private function dispatchAutomations(string $trigger, string $entityId, array $entityData): void
    {
        if ($this->automationService === null) {
            return;
        }
        try {
            $matched = $this->automationService->getMatchingAutomations($trigger, $entityData);
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning(
                    'Automation dispatch lookup failed',
                    ['exception' => $e->getMessage(), 'trigger' => $trigger]
                );
            }
            return;
        }

        foreach ($matched as $automation) {
            $automationId = (string) ($automation['id'] ?? $automation['slug'] ?? $automation['uuid'] ?? '');
            if ($automationId === '') {
                continue;
            }
            // Queue marker — execution is deferred to a background runner.
            try {
                $this->automationService->logExecution(
                    $automationId,
                    $entityId,
                    [
                        'actionsExecuted' => [],
                        'status'          => 'queued',
                    ]
                );
            } catch (\Throwable $e) {
                if ($this->logger !== null) {
                    $this->logger->warning(
                        'Automation queue marker write failed',
                        ['exception' => $e->getMessage(), 'automation' => $automationId]
                    );
                }
            }
        }
    }//end dispatchAutomations()

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
}//end class
