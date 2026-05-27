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

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling object event business logic.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @spec                                           openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-47
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
     * @param IAppConfig              $appConfig         The app config.
     * @param ContainerInterface      $container         The DI container.
     * @param LoggerInterface         $logger            The logger.
     */
    public function __construct(
        private SchemaMapService $schemaMapService,
        private ObjectEventDispatcher $dispatcher,
        private ObjectUpdateDiffService $diffService,
        private AutomationService $automationService,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
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
     * Fire matching automations for entity creation or update events.
     *
     * Loads automation rules from OpenRegister, evaluates each rule's conditions
     * against the trigger + entity data, and dispatches webhook actions for
     * every matching, active automation.
     *
     * Failures are caught and logged; automation errors must never break the
     * main object-event flow.
     *
     * @param string $trigger    The trigger event name.
     * @param array  $entityData The entity data.
     * @param string $objectId   The object ID.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-47
     */
    private function fireAutomations(string $trigger, array $entityData, string $objectId): void
    {
        try {
            $automations = $this->loadAutomationRules();
            if ($automations === []) {
                return;
            }

            $fullEntityData = array_merge($entityData, ['id' => $objectId]);

            foreach ($automations as $automation) {
                if ($this->automationService->matchesConditions($automation, $trigger, $fullEntityData) === false) {
                    continue;
                }

                $actions = $automation['actions'] ?? [];
                foreach ($actions as $action) {
                    if (($action['type'] ?? '') !== 'webhook') {
                        continue;
                    }

                    $webhookUrl = (string) ($action['webhookUrl'] ?? '');
                    if ($webhookUrl === '') {
                        continue;
                    }

                    $payload = $this->automationService->buildWebhookPayload(
                        automation: $automation,
                        trigger: $trigger,
                        entityData: $fullEntityData
                    );

                    $result = $this->automationService->fireWebhook($webhookUrl, $payload);
                    $this->logger->info(
                        'ObjectEventHandlerService: automation webhook fired',
                        [
                            'trigger'        => $trigger,
                            'automationName' => $automation['name'] ?? '',
                            'status'         => $result['status'] ?? 'unknown',
                        ]
                    );
                }//end foreach
            }//end foreach
        } catch (\Exception $e) {
            // Automation failures must not break the main event flow.
            $this->logger->error(
                'ObjectEventHandlerService: fireAutomations failed',
                ['trigger' => $trigger, 'error' => $e->getMessage()]
            );
        }//end try
    }//end fireAutomations()

    /**
     * Load automation rules from OpenRegister.
     *
     * Returns an empty array when OR is unavailable or not configured.
     *
     * @return array<int, array<string, mixed>> The automation rule objects.
     */
    private function loadAutomationRules(): array
    {
        $registerId       = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $automationSchema = $this->appConfig->getValueString(Application::APP_ID, 'automation_schema', '');

        if ($registerId === '' || $automationSchema === '') {
            return [];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $results       = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $automationSchema,
                        'isActive' => true,
                    ],
                    'limit'   => 200,
                ]
            );

            if (is_array($results) === false) {
                return [];
            }

            // Normalise ObjectEntity-or-array items to plain arrays.
            $rules = [];
            foreach ($results as $item) {
                if (is_array($item) === true) {
                    $rules[] = $item;
                } else if (is_object($item) === true && method_exists($item, 'getObject') === true) {
                    $data = $item->getObject();
                    if (is_array($data) === true) {
                        $rules[] = $data;
                    }
                }
            }

            return $rules;
        } catch (\Exception $e) {
            $this->logger->warning(
                'ObjectEventHandlerService: could not load automation rules',
                ['error' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end loadAutomationRules()

    /**
     * Fire matching automations for entity update events.
     *
     * @param string $entityType The entity type.
     * @param array  $newData    The new entity data.
     * @param array  $oldData    The old entity data.
     * @param string $objectId   The object ID.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-47
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
