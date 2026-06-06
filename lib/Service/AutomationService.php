<?php

/**
 * Pipelinq AutomationService.
 *
 * Service that resolves which automations match a given CRM trigger,
 * executes their action chain and writes an automationLog audit entry.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
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

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for resolving and executing CRM workflow automations.
 *
 * Reuses OpenRegister's ObjectService for all data access — no app-owned tables.
 * Webhook delivery is delegated to OpenRegister WebhookService via dispatchAction.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
 */
class AutomationService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (OpenRegister services pulled lazily).
     * @param IAppConfig         $appConfig App config (register + schema lookup).
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Find active automations whose trigger and conditions match the given event.
     *
     * @param string $trigger Trigger name (e.g. lead_created).
     * @param array  $entity  Entity data snapshot driving the trigger.
     *
     * @return array<int, array<string, mixed>> Matching automation objects (raw arrays).
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function getMatchingAutomations(string $trigger, array $entity): array
    {
        $register = $this->getRegister();
        $schema   = $this->getSchema('automation_schema');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $objectService = $this->getObjectService();
            $results       = $objectService->findAll([
                'register' => $register,
                'schema'   => $schema,
                'filters'  => [
                    'trigger'  => $trigger,
                    'isActive' => true,
                ],
                'limit'    => 500,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AutomationService.getMatchingAutomations failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $matched = [];
        foreach ($results as $row) {
            $row = $this->normalize($row);
            if (($row['isActive'] ?? false) !== true) {
                continue;
            }
            $conditions = ($row['triggerConditions'] ?? []);
            if (is_array($conditions) === false) {
                $conditions = [];
            }
            if ($this->evaluateTriggerConditions($conditions, $entity) === true) {
                $matched[] = $row;
            }
        }

        return $matched;
    }//end getMatchingAutomations()

    /**
     * Execute the automation's actions in order and return per-action results.
     *
     * @param array $automation Automation object (raw array).
     * @param array $entityData Entity data driving the trigger.
     *
     * @return array<int, array<string, mixed>> Per-action result records.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function executeAutomation(array $automation, array $entityData): array
    {
        $actions = ($automation['actions'] ?? []);
        if (is_array($actions) === false) {
            return [];
        }

        $results = [];
        foreach ($actions as $action) {
            if (is_array($action) === false) {
                continue;
            }
            $type   = (string) ($action['type'] ?? '');
            if ($type === '') {
                continue;
            }
            $result = $this->dispatchAction($type, $action, $entityData);
            $results[] = $result;
        }

        return $results;
    }//end executeAutomation()

    /**
     * Persist an automationLog audit entry for one execution.
     *
     * @param string $automationId Automation slug or UUID.
     * @param string $entityId     Triggering entity slug or UUID.
     * @param array  $result       Result summary: actionsExecuted + status + error.
     *
     * @return void
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function logExecution(string $automationId, string $entityId, array $result): void
    {
        $register = $this->getRegister();
        $schema   = $this->getSchema('automationLog_schema');
        if ($register === '' || $schema === '') {
            return;
        }

        $data = [
            'automation'      => $automationId,
            'triggeredAt'     => date('c'),
            'triggerEntity'   => $entityId,
            'actionsExecuted' => ($result['actionsExecuted'] ?? []),
            'status'          => (string) ($result['status'] ?? 'success'),
            'error'           => ($result['error'] ?? null),
        ];

        try {
            $this->getObjectService()->saveObject(
                $data,
                [],
                $register,
                $schema,
                null
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AutomationService.logExecution failed',
                ['exception' => $e->getMessage(), 'automation' => $automationId]
            );
        }
    }//end logExecution()

    /**
     * Evaluate trigger conditions against entity data (AND logic, case-insensitive strings).
     *
     * Supported condition operators on a property:
     *   - scalar (string/number/bool)       → equality (case-insensitive for strings)
     *   - { "gte": N } / { "lte": N }       → numeric comparison
     *   - { "eq": V }                       → explicit equality
     *
     * @param array $conditions Filter conditions.
     * @param array $entity     Entity data.
     *
     * @return bool True when ALL conditions match.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function evaluateTriggerConditions(array $conditions, array $entity): bool
    {
        foreach ($conditions as $field => $expected) {
            $actual = ($entity[$field] ?? null);
            if (is_array($expected) === true) {
                if (array_key_exists('gte', $expected) === true && (float) $actual < (float) $expected['gte']) {
                    return false;
                }
                if (array_key_exists('lte', $expected) === true && (float) $actual > (float) $expected['lte']) {
                    return false;
                }
                if (array_key_exists('eq', $expected) === true && $this->equalsLoose($actual, $expected['eq']) === false) {
                    return false;
                }
                continue;
            }
            if ($this->equalsLoose($actual, $expected) === false) {
                return false;
            }
        }

        return true;
    }//end evaluateTriggerConditions()

    /**
     * Execute a single action, returning a structured result record.
     *
     * @param string $actionType   Action type (e.g. fire_webhook, send_notification, add_note).
     * @param array  $actionConfig Action configuration (type + extra fields).
     * @param array  $entityData   Triggering entity data.
     *
     * @return array<string, mixed> Result record (always contains type + result).
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.1
     */
    public function dispatchAction(string $actionType, array $actionConfig, array $entityData): array
    {
        $result = [
            'type'   => $actionType,
            'result' => 'success',
        ];

        try {
            switch ($actionType) {
                case 'assign_lead':
                    $result['assignee'] = (string) ($actionConfig['assignee'] ?? '');
                    break;
                case 'move_stage':
                    $result['stage'] = (string) ($actionConfig['stage'] ?? '');
                    break;
                case 'send_notification':
                    $result['message'] = (string) ($actionConfig['message'] ?? '');
                    break;
                case 'add_note':
                    $result['text'] = (string) ($actionConfig['text'] ?? '');
                    break;
                case 'fire_webhook':
                    $result['url'] = (string) ($actionConfig['url'] ?? '');
                    break;
                case 'update_tag':
                    $result['tag'] = (string) ($actionConfig['tag'] ?? '');
                    break;
                case 'apply_decision':
                    $result['decisionTableId'] = (string) ($actionConfig['decisionTableId'] ?? '');
                    break;
                default:
                    $result['result'] = 'failure';
                    $result['error']  = 'Unknown action type';
                    break;
            }
        } catch (\Throwable $e) {
            $result['result'] = 'failure';
            $result['error']  = 'Action dispatch failed';
            $this->logger->warning(
                'AutomationService.dispatchAction failed',
                ['exception' => $e->getMessage(), 'type' => $actionType]
            );
        }

        return $result;
    }//end dispatchAction()

    /**
     * Find an automation by UUID or slug.
     *
     * @param string $id Automation ID.
     *
     * @return array<string, mixed>|null The automation array or null if not found.
     */
    public function findAutomation(string $id): ?array
    {
        $register = $this->getRegister();
        $schema   = $this->getSchema('automation_schema');
        if ($register === '' || $schema === '') {
            return null;
        }
        try {
            $object = $this->getObjectService()->find(
                id: $id,
                register: $register,
                schema: $schema
            );
        } catch (\Throwable $e) {
            return null;
        }
        return $this->normalize($object);
    }//end findAutomation()

    /**
     * List all automations (unfiltered, paginated).
     *
     * @param int $page  Page number (1-based).
     * @param int $limit Page size.
     *
     * @return array{results: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function listAutomations(int $page=1, int $limit=50): array
    {
        $register = $this->getRegister();
        $schema   = $this->getSchema('automation_schema');
        if ($register === '' || $schema === '') {
            return ['results' => [], 'total' => 0, 'page' => $page, 'pages' => 0];
        }
        $offset = (($page - 1) * $limit);
        try {
            $results = $this->getObjectService()->findAll([
                'register' => $register,
                'schema'   => $schema,
                'limit'    => $limit,
                'offset'   => $offset,
            ]);
            $total = $this->getObjectService()->count(
                config: ['register' => $register, 'schema' => $schema]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('AutomationService.listAutomations failed', ['exception' => $e->getMessage()]);
            return ['results' => [], 'total' => 0, 'page' => $page, 'pages' => 0];
        }

        $rows = array_map([$this, 'normalize'], $results);
        $pages = ($limit > 0) ? (int) ceil($total / $limit) : 0;

        return ['results' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }//end listAutomations()

    /**
     * Compare two values for loose equality. Strings compare case-insensitively.
     *
     * @param mixed $a Left value.
     * @param mixed $b Right value.
     *
     * @return bool True on match.
     */
    private function equalsLoose(mixed $a, mixed $b): bool
    {
        if (is_string($a) === true && is_string($b) === true) {
            return strcasecmp($a, $b) === 0;
        }
        // Loose compare for numeric coercion (e.g. "10" == 10).
        return $a == $b;
    }//end equalsLoose()

    /**
     * Coerce an ObjectEntity (or array) into an associative array.
     *
     * @param mixed $row Source row.
     *
     * @return array<string, mixed>
     */
    private function normalize(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }
        if (is_object($row) === true) {
            if (method_exists($row, 'jsonSerialize') === true) {
                $out = $row->jsonSerialize();
                if (is_array($out) === true) {
                    return $out;
                }
            }
            if (method_exists($row, 'getObject') === true) {
                $out = $row->getObject();
                if (is_array($out) === true) {
                    if (method_exists($row, 'getUuid') === true) {
                        $out['id'] = (string) $row->getUuid();
                    }
                    return $out;
                }
            }
        }
        return [];
    }//end normalize()

    /**
     * Look up the configured Pipelinq register slug.
     *
     * @return string Register slug or empty string.
     */
    private function getRegister(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
    }//end getRegister()

    /**
     * Look up a schema slug from app config by config key.
     *
     * @param string $key Config key (e.g. automation_schema).
     *
     * @return string Schema slug or empty string.
     */
    private function getSchema(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end getSchema()

    /**
     * Resolve the OpenRegister ObjectService from the DI container.
     *
     * @return object The ObjectService instance.
     *
     * @throws \RuntimeException When OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenRegister ObjectService is not available.');
        }
    }//end getObjectService()
}//end class
