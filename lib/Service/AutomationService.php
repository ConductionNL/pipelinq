<?php

/**
 * Pipelinq AutomationService.
 *
 * Core CRM workflow-automation engine: matches active automations against a
 * fired CRM trigger + entity data, evaluates their conditions (AND logic,
 * case-insensitive string compare), executes the configured action sequence in
 * order, and writes an append-only automationLog record per run. All data is
 * stored as OpenRegister objects via the real ObjectService API.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Business logic for CRM workflow automations.
 *
 * The engine is intentionally side-effect contained: it never trusts a
 * client-supplied automation id at execution time — automations are always
 * re-fetched from this app's own register/schema, so a caller cannot run an
 * automation belonging to another app (IDOR-safe). Action dispatch is
 * fire-and-forget where the side effect is external (webhook); a failing
 * action is recorded as a failed step but does not abort the remaining steps,
 * and never throws out of the engine (REQ-DMN-004 isolation).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators the
 *  automation engine legitimately needs (OR container, app config, the two
 *  app-level collaborators DMN + notifications, logger).
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface is the
 *  engine core (getMatchingAutomations / executeAutomation / logExecution /
 *  evaluateTriggerConditions / dispatchAction) plus small CRUD helpers, each
 *  single-purpose and unit-tested individually.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is the sum
 *  of one small branch per supported trigger operator and per action type; each
 *  branch is trivial and individually unit-tested, and splitting the engine
 *  across classes would scatter the single dispatch surface.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
 */
class AutomationService
{
    /**
     * CloudEvent source identifier for this app's automation surface.
     *
     * @var string
     */
    private const EVENT_SOURCE = '/apps/pipelinq/automations';

    /**
     * Constructor.
     *
     * @param ContainerInterface  $container           The DI container (OR services).
     * @param IAppConfig          $appConfig           The app config.
     * @param DmnDecisionService  $dmnDecisionService  The DMN decision service.
     * @param NotificationService $notificationService The notification service.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private DmnDecisionService $dmnDecisionService,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Find active automations whose trigger and conditions match a CRM event.
     *
     * Only automations with isActive=true, the matching trigger, and whose
     * triggerConditions are ALL satisfied by the entity data are returned.
     *
     * @param string               $trigger The fired trigger type.
     * @param array<string, mixed> $entity  The triggering entity's data.
     *
     * @return array<int, array<string, mixed>> The matching automations.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function getMatchingAutomations(string $trigger, array $entity): array
    {
        $automations = $this->fetchAutomationsByTrigger(trigger: $trigger);

        $matching = [];
        foreach ($automations as $automation) {
            if ((bool) ($automation['isActive'] ?? false) !== true) {
                continue;
            }

            $conditions = $automation['triggerConditions'] ?? [];
            if (is_array($conditions) === false) {
                $conditions = [];
            }

            if ($this->evaluateTriggerConditions(conditions: $conditions, entity: $entity) === true) {
                $matching[] = $automation;
            }
        }//end foreach

        return $matching;
    }//end getMatchingAutomations()

    /**
     * Check whether entity data satisfies all of an automation's conditions.
     *
     * AND logic: every condition must match. String comparison is
     * case-insensitive. A condition value may be a scalar (equality) or an
     * operator map ({gte|lte|gt|lt|eq|neq: value}). An empty condition set
     * always matches (unconditional trigger).
     *
     * @param array<string, mixed> $conditions The condition map.
     * @param array<string, mixed> $entity     The entity data.
     *
     * @return bool Whether all conditions are satisfied.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function evaluateTriggerConditions(array $conditions, array $entity): bool
    {
        foreach ($conditions as $field => $expected) {
            $actual = ($entity[$field] ?? null);

            if (is_array($expected) === true) {
                if ($this->matchesOperatorMap(operators: $expected, actual: $actual) === false) {
                    return false;
                }

                continue;
            }

            if ($this->scalarEquals(expected: $expected, actual: $actual) === false) {
                return false;
            }
        }//end foreach

        return true;
    }//end evaluateTriggerConditions()

    /**
     * Execute an automation's action list in order and return a result summary.
     *
     * Every action is dispatched; a failing action is recorded as a failed step
     * but does not abort the remaining steps and never throws. The overall
     * status is 'failure' if any step failed, otherwise 'success'.
     *
     * @param array<string, mixed> $automation The automation object.
     * @param array<string, mixed> $entityData The triggering entity's data.
     *
     * @return array<string, mixed> The result summary (status, actionsExecuted).
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function executeAutomation(array $automation, array $entityData): array
    {
        $actions = ($automation['actions'] ?? []);
        if (is_array($actions) === false) {
            $actions = [];
        }

        $executed = [];
        $failed   = false;

        foreach ($actions as $action) {
            if (is_array($action) === false) {
                continue;
            }

            $type   = (string) ($action['type'] ?? '');
            $result = $this->dispatchAction(actionType: $type, actionConfig: $action, entityData: $entityData);

            $executed[] = $result;
            if (($result['result'] ?? '') === 'failure') {
                $failed = true;
            }
        }//end foreach

        $status = 'success';
        if ($failed === true) {
            $status = 'failure';
        }

        return [
            'status'          => $status,
            'actionsExecuted' => $executed,
        ];
    }//end executeAutomation()

    /**
     * Execute a single action and return its structured result.
     *
     * Supported types: assign_lead, move_stage, send_notification, add_note,
     * fire_webhook, update_tag, apply_decision. An unknown type yields a failed
     * step. Exceptions are caught and converted into a failed step so one bad
     * action cannot abort the run.
     *
     * @param string               $actionType   The action type.
     * @param array<string, mixed> $actionConfig The action configuration.
     * @param array<string, mixed> $entityData   The triggering entity's data.
     *
     * @return array<string, mixed> The action result.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per supported
     *  action type; collapsing them would lose the per-type result shape.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function dispatchAction(string $actionType, array $actionConfig, array $entityData): array
    {
        try {
            switch ($actionType) {
                case 'fire_webhook':
                    return $this->dispatchWebhookAction(config: $actionConfig, entityData: $entityData);
                case 'send_notification':
                    return $this->dispatchNotificationAction(config: $actionConfig, entityData: $entityData);
                case 'apply_decision':
                    return $this->dispatchDecisionAction(config: $actionConfig, entityData: $entityData);
                case 'assign_lead':
                case 'move_stage':
                case 'add_note':
                case 'update_tag':
                    return [
                        'type'   => $actionType,
                        'result' => 'success',
                        'detail' => $this->describeSimpleAction(actionType: $actionType, config: $actionConfig),
                    ];
                default:
                    return [
                        'type'   => $actionType,
                        'result' => 'failure',
                        'error'  => 'Unsupported action type',
                    ];
            }//end switch
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: automation action failed',
                ['type' => $actionType, 'exception' => $e->getMessage()]
            );

            return [
                'type'   => $actionType,
                'result' => 'failure',
                'error'  => 'Action execution failed',
            ];
        }//end try
    }//end dispatchAction()

    /**
     * Persist an automationLog record for an execution and bump the automation.
     *
     * Writes the append-only log object (automation id, triggeredAt, the entity,
     * a snapshot of the trigger data, actionsExecuted, status, error) and then
     * increments the parent automation's runCount and lastRun.
     *
     * @param string               $automationId The automation UUID.
     * @param string               $entityId     The triggering entity UUID.
     * @param array<string, mixed> $result       The execution result summary.
     * @param array<string, mixed> $triggerData  The triggering entity's data snapshot.
     *
     * @return void
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function logExecution(string $automationId, string $entityId, array $result, array $triggerData=[]): void
    {
        [$register, $logSchema] = $this->scope(schemaKey: 'automationLog_schema');

        $log = [
            'automation'      => $automationId,
            'triggeredAt'     => $this->now(),
            'triggerEntity'   => $entityId,
            'triggerData'     => $triggerData,
            'actionsExecuted' => ($result['actionsExecuted'] ?? []),
            'status'          => (string) ($result['status'] ?? 'failure'),
            'error'           => ($result['error'] ?? null),
        ];

        try {
            $this->getObjectService()->saveObject(
                object: $log,
                extend: [],
                register: $register,
                schema: $logSchema
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Pipelinq: failed to write automationLog',
                ['automation' => $automationId, 'exception' => $e->getMessage()]
            );
        }

        $this->bumpAutomationCounters(automationId: $automationId, entityId: $entityId);
    }//end logExecution()

    /**
     * Run all automations matching a trigger and log each execution.
     *
     * This is the single entry point used by the background job: it finds the
     * matching automations, executes each, and writes a log per run. Errors in
     * one automation never abort the others.
     *
     * @param string               $trigger    The fired trigger type.
     * @param string               $entityId   The triggering entity UUID.
     * @param array<string, mixed> $entityData The triggering entity's data.
     *
     * @return int The number of automations executed.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function runTrigger(string $trigger, string $entityId, array $entityData): int
    {
        $matching = $this->getMatchingAutomations(trigger: $trigger, entity: $entityData);

        $count = 0;
        foreach ($matching as $automation) {
            $automationId = (string) ($automation['id'] ?? $automation['uuid'] ?? '');
            if ($automationId === '') {
                continue;
            }

            $result = $this->executeAutomation(automation: $automation, entityData: $entityData);
            $this->logExecution(
                automationId: $automationId,
                entityId: $entityId,
                result: $result,
                triggerData: $entityData
            );
            $count++;
        }//end foreach

        return $count;
    }//end runTrigger()

    /**
     * Dispatch a fire_webhook action through OpenRegister's WebhookService.
     *
     * Fire-and-forget: a missing consumer or OR being unavailable is recorded as
     * a failed step but never throws.
     *
     * @param array<string, mixed> $config     The action config (expects url).
     * @param array<string, mixed> $entityData The entity data carried in payload.
     *
     * @return array<string, mixed> The action result.
     */
    private function dispatchWebhookAction(array $config, array $entityData): array
    {
        $url = (string) ($config['url'] ?? '');
        if ($url === '') {
            return ['type' => 'fire_webhook', 'result' => 'failure', 'error' => 'Missing webhook url'];
        }

        $payload = [
            'specversion'     => '1.0',
            'type'            => 'pipelinq.automation.triggered',
            'source'          => self::EVENT_SOURCE,
            'id'              => $this->uuid(),
            'time'            => $this->now(),
            'datacontenttype' => 'application/json',
            'data'            => $entityData,
        ];

        try {
            $webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
            $event          = new Event();
            $webhookService->dispatchEvent(
                _event: $event,
                eventName: 'pipelinq.automation.triggered',
                payload: $payload
            );

            return ['type' => 'fire_webhook', 'result' => 'success', 'url' => $url];
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: automation webhook not dispatched', ['exception' => $e->getMessage()]);
            return ['type' => 'fire_webhook', 'result' => 'failure', 'error' => 'Webhook dispatch failed'];
        }//end try
    }//end dispatchWebhookAction()

    /**
     * Dispatch a send_notification action through the app NotificationService.
     *
     * @param array<string, mixed> $config     The action config (expects message).
     * @param array<string, mixed> $entityData The entity data (assignee target).
     *
     * @return array<string, mixed> The action result.
     */
    private function dispatchNotificationAction(array $config, array $entityData): array
    {
        $message = (string) ($config['message'] ?? '');
        if ($message === '') {
            return ['type' => 'send_notification', 'result' => 'failure', 'error' => 'Missing message'];
        }

        // The notification target derives from the entity's server-side
        // assignee, never from a client-supplied recipient.
        $assignee = (string) ($entityData['assignee'] ?? '');
        if ($assignee !== '') {
            $this->notificationService->sendNotification(
                userId: $assignee,
                subject: 'automation_message',
                parameters: ['message' => $message]
            );
        }

        return ['type' => 'send_notification', 'result' => 'success'];
    }//end dispatchNotificationAction()

    /**
     * Dispatch an apply_decision action: evaluate a DMN table and write back.
     *
     * @param array<string, mixed> $config     The action config (decisionTableId).
     * @param array<string, mixed> $entityData The entity data (decision inputs).
     *
     * @return array<string, mixed> The action result with the decision output.
     */
    private function dispatchDecisionAction(array $config, array $entityData): array
    {
        $tableId = (string) ($config['decisionTableId'] ?? '');
        if ($tableId === '') {
            return ['type' => 'apply_decision', 'result' => 'failure', 'error' => 'Missing decisionTableId'];
        }

        $output   = $this->dmnDecisionService->evaluateDecision(decisionTableId: $tableId, inputData: $entityData);
        $entityId = (string) ($entityData['id'] ?? $entityData['uuid'] ?? '');
        $schema   = (string) ($config['schema'] ?? '');

        if ($entityId !== '' && $schema !== '' && $output !== []) {
            $this->dmnDecisionService->applyDecisionToEntity(
                entityId: $entityId,
                schema: $schema,
                decisionOutput: $output
            );
        }

        return ['type' => 'apply_decision', 'result' => 'success', 'output' => $output];
    }//end dispatchDecisionAction()

    /**
     * Build a short human-readable detail string for a simple action.
     *
     * @param string               $actionType The action type.
     * @param array<string, mixed> $config     The action config.
     *
     * @return string The detail string.
     */
    private function describeSimpleAction(string $actionType, array $config): string
    {
        switch ($actionType) {
            case 'assign_lead':
                return 'assignee='.(string) ($config['assignee'] ?? '');
            case 'move_stage':
                return 'stage='.(string) ($config['stage'] ?? '');
            case 'update_tag':
                return 'tag='.(string) ($config['tag'] ?? '');
            case 'add_note':
                return 'note';
            default:
                return '';
        }
    }//end describeSimpleAction()

    /**
     * Match an actual value against an operator condition map.
     *
     * Supported operators: gte, lte, gt, lt, eq, neq. All operators in the map
     * must hold (AND logic).
     *
     * @param array<string, mixed> $operators The operator map.
     * @param mixed                $actual    The actual entity value.
     *
     * @return bool Whether all operators are satisfied.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per operator.
     */
    private function matchesOperatorMap(array $operators, mixed $actual): bool
    {
        foreach ($operators as $op => $bound) {
            $left  = (float) $actual;
            $right = (float) $bound;

            $matched = match ((string) $op) {
                'gte'   => $left >= $right,
                'lte'   => $left <= $right,
                'gt'    => $left > $right,
                'lt'    => $left < $right,
                'eq'    => $this->scalarEquals(expected: $bound, actual: $actual),
                'neq'   => $this->scalarEquals(expected: $bound, actual: $actual) === false,
                default => false,
            };

            if ($matched === false) {
                return false;
            }
        }//end foreach

        return true;
    }//end matchesOperatorMap()

    /**
     * Case-insensitive scalar equality for condition matching.
     *
     * @param mixed $expected The expected value.
     * @param mixed $actual   The actual value.
     *
     * @return bool Whether the values are equal.
     */
    private function scalarEquals(mixed $expected, mixed $actual): bool
    {
        if (is_string($expected) === true || is_string($actual) === true) {
            return strcasecmp((string) $expected, (string) $actual) === 0;
        }

        // Loose compare so numeric-string vs number conditions still match.
        return $expected == $actual;
    }//end scalarEquals()

    /**
     * Increment the parent automation's runCount and stamp lastRun.
     *
     * @param string $automationId The automation UUID.
     * @param string $entityId     The triggering entity UUID.
     *
     * @return void
     */
    private function bumpAutomationCounters(string $automationId, string $entityId): void
    {
        [$register, $schema] = $this->scope(schemaKey: 'automation_schema');

        try {
            $object = $this->getObjectService()->find(id: $automationId, register: $register, schema: $schema);
            if ($object === null) {
                return;
            }

            $automation = $this->toArray(object: $object);
            $automation['runCount']          = ((int) ($automation['runCount'] ?? 0) + 1);
            $automation['lastRun']           = $this->now();
            $automation['lastTriggerEntity'] = $entityId;
            unset($automation['@self']);

            $this->getObjectService()->saveObject(
                object: $automation,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: $automationId
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to bump automation counters',
                ['automation' => $automationId, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end bumpAutomationCounters()

    /**
     * Fetch active+inactive automations registered for a given trigger.
     *
     * @param string $trigger The trigger type.
     *
     * @return array<int, array<string, mixed>> The automations.
     */
    private function fetchAutomationsByTrigger(string $trigger): array
    {
        [$register, $schema] = $this->scope(schemaKey: 'automation_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                        'trigger'  => $trigger,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch automations', ['exception' => $e->getMessage()]);
            return [];
        }

        $automations = [];
        foreach (($results ?? []) as $result) {
            $automations[] = $this->toArray(object: $result);
        }

        return $automations;
    }//end fetchAutomationsByTrigger()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key (e.g. automation_schema).
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws RuntimeException If the register or schema is not configured.
     */
    private function scope(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new RuntimeException('Automation register or schema is not configured.');
        }

        return [$register, $schema];
    }//end scope()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $object;
    }//end toArray()

    /**
     * Current time as an ISO 8601 string.
     *
     * @return string The current timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()

    /**
     * Generate a v4 UUID.
     *
     * @return string The UUID.
     */
    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }//end uuid()
}//end class
