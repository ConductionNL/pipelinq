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
 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-crm-automation-triggers
 * @spec openspec/changes/migrate-automation-to-flow-leaf/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Service for handling object event business logic.
 *
 * Per migrate-automation-to-flow-leaf, the bespoke automation engine has
 * been retired. CRM events are surfaced through the OpenRegister flow leaf
 * (NC Flow / n8n) — this service only handles in-app dispatcher notifications.
 *
 * @spec openspec/changes/migrate-automation-to-flow-leaf/tasks.md#task-1.1
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
    ) {
    }//end __construct()

    /**
     * Handle a newly created object.
     *
     * @param object $objectEntity The created object entity.
     *
     * @return void
     *
     * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-crm-automation-triggers
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
            title: $this->stringifyTitle(title: ($data['title'] ?? '')),
            objectId: $objectId,
            assignee: $this->stringifyScalar(value: ($data['assignee'] ?? ''))
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
     * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-crm-automation-triggers
     */
    public function handleUpdated(object $newObject, ?object $oldObject): void
    {
        $entityType = $this->schemaMapService->resolveEntityType(schemaId: $newObject->getSchema());
        if ($this->isRelevantEntityType(entityType: $entityType) === false) {
            return;
        }

        $newData  = $newObject->getObject();
        $oldData  = $this->extractOldData(oldObject: $oldObject);
        $title    = $this->stringifyTitle(title: ($newData['title'] ?? ''));
        $objectId = (string) $newObject->getId();
        $assignee = $this->stringifyScalar(value: ($newData['assignee'] ?? ''));

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
    }//end handleUpdated()

    /**
     * Coerce a (possibly translatable) title value to a single display string.
     *
     * OpenRegister stores a `translatable: true` string property (the lead
     * schema's `title` is one) as a per-language map, e.g.
     * `['en' => 'Acme deal', 'nl' => 'Acme deal']`, so `$data['title']` arrives
     * as an array — which previously crashed the downstream
     * ObjectEventDispatcher::dispatchCreated(string $title, ...) with a
     * TypeError ("must be of type string, array given"), failing every lead
     * create with a 500. Prefer the English value, then the first scalar
     * member, then a JSON encoding; a plain scalar passes straight through.
     *
     * @param mixed $title The raw title value (string, translatable map, or null).
     *
     * @return string A display title string (never an array).
     */
    private function stringifyTitle(mixed $title): string
    {
        if (is_array($title) === true) {
            // Prefer a conventional language key, else the first scalar value.
            foreach (['en', 'en_GB', 'en_US', 'nl', 'nl_NL'] as $lang) {
                if (isset($title[$lang]) === true && is_scalar($title[$lang]) === true) {
                    return (string) $title[$lang];
                }
            }

            foreach ($title as $value) {
                if (is_scalar($value) === true) {
                    return (string) $value;
                }
            }

            // No usable scalar member: fall back to a compact JSON encoding so
            // the activity/notification still carries something meaningful
            // rather than throwing.
            $encoded = json_encode($title);
            if ($encoded !== false) {
                return $encoded;
            }

            return '';
        }//end if

        return $this->stringifyScalar(value: $title);
    }//end stringifyTitle()

    /**
     * Coerce a scalar-ish value to string, mapping arrays/objects/null to ''.
     *
     * Guards the other string arguments forwarded to the dispatcher (e.g.
     * `assignee`) against the same translatable-map / null shapes.
     *
     * @param mixed $value The raw value.
     *
     * @return string The string value, or '' when not a scalar.
     */
    private function stringifyScalar(mixed $value): string
    {
        if (is_scalar($value) === true) {
            return (string) $value;
        }

        return '';
    }//end stringifyScalar()

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
