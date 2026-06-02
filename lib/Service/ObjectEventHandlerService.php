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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\BackgroundJob\AutomationDispatchJob;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Service for handling object event business logic.
 *
 * @spec openspec/changes/migrate-automation-to-flow-leaf/tasks.md#task-1.1
 */
class ObjectEventHandlerService
{
    /**
     * Maps a relevant entity type to its automation trigger on create.
     *
     * @var array<string, string>
     */
    private const CREATE_TRIGGERS = [
        'lead'    => 'lead_created',
        'contact' => 'contact_created',
        'request' => 'request_created',
    ];

    /**
     * Maps a relevant entity type to its automation trigger on update.
     *
     * @var array<string, string>
     */
    private const UPDATE_TRIGGERS = [
        'lead'    => 'lead_stage_changed',
        'request' => 'request_status_changed',
    ];

    /**
     * Constructor.
     *
     * @param SchemaMapService        $schemaMapService The schema map service.
     * @param ObjectEventDispatcher   $dispatcher       The event dispatcher.
     * @param ObjectUpdateDiffService $diffService      The update diff service.
     * @param IJobList                $jobList          The background job list.
     * @param LoggerInterface         $logger           The logger.
     */
    public function __construct(
        private SchemaMapService $schemaMapService,
        private ObjectEventDispatcher $dispatcher,
        private ObjectUpdateDiffService $diffService,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Queue automation execution for a fired CRM trigger (REQ-NFR-001).
     *
     * The matching automations are NOT run synchronously: a one-shot
     * AutomationDispatchJob is enqueued so the originating entity save is never
     * delayed by automation execution. Enqueue failures are logged and
     * swallowed so a queue hiccup never breaks the save path.
     *
     * @param string               $trigger    The fired trigger type.
     * @param string               $entityId   The triggering entity UUID.
     * @param array<string, mixed> $entityData The triggering entity's data.
     *
     * @return void
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-4.1
     */
    private function dispatchAutomations(string $trigger, string $entityId, array $entityData): void
    {
        try {
            $this->jobList->add(
                AutomationDispatchJob::class,
                [
                    'trigger'    => $trigger,
                    'entityId'   => $entityId,
                    'entityData' => $entityData,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to enqueue automation dispatch',
                ['trigger' => $trigger, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end dispatchAutomations()

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

        $trigger = (self::CREATE_TRIGGERS[$entityType] ?? '');
        if ($trigger !== '') {
            $this->dispatchAutomations(trigger: $trigger, entityId: $objectId, entityData: $data);
        }
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

        $trigger = (self::UPDATE_TRIGGERS[$entityType] ?? '');
        if ($trigger !== '') {
            $this->dispatchAutomations(trigger: $trigger, entityId: $objectId, entityData: $newData);
        }
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
}//end class
