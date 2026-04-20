<?php

/**
 * Pipelinq AutomationService.
 *
 * Service for managing CRM workflow automations stored as OpenRegister objects.
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

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for CRM workflow automation management.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
 */
class AutomationService
{
    /**
     * Valid trigger types for CRM automations.
     */
    private const VALID_TRIGGERS = [
        'lead_created',
        'lead_stage_changed',
        'lead_assigned',
        'lead_value_changed',
        'contact_created',
        'request_created',
        'request_status_changed',
    ];

    /**
     * Valid action types for CRM automations.
     */
    private const VALID_ACTIONS = [
        'assign_lead',
        'move_stage',
        'send_notification',
        'update_field',
        'add_note',
        'webhook',
    ];

    /**
     * The OpenRegister object service.
     *
     * @var \OCA\OpenRegister\Service\ObjectService|null
     */
    private ?\OCA\OpenRegister\Service\ObjectService $objectService = null;

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig   The app configuration.
     * @param IUserSession       $userSession The user session.
     * @param LoggerInterface    $logger      The logger.
     * @param ContainerInterface $container   The DI container.
     * @param IAppManager        $appManager  The app manager.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private LoggerInterface $logger,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
    ) {
    }//end __construct()

    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The OpenRegister service.
     *
     * @throws \RuntimeException If the service is not available.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        if ($this->objectService !== null) {
            return $this->objectService;
        }

        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return $this->objectService;
        }

        throw new RuntimeException('OpenRegister service is not available.');
    }//end getObjectService()

    /**
     * Get the list of valid trigger types.
     *
     * @return array The valid trigger types.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function getValidTriggers(): array
    {
        return self::VALID_TRIGGERS;
    }//end getValidTriggers()

    /**
     * Get the list of valid action types.
     *
     * @return array The valid action types.
     */
    public function getValidActions(): array
    {
        return self::VALID_ACTIONS;
    }//end getValidActions()

    /**
     * Check if an automation matches a given trigger event and entity data.
     *
     * @param array  $automation The automation configuration.
     * @param string $trigger    The trigger event type.
     * @param array  $entityData The entity data to check conditions against.
     *
     * @return bool Whether the automation matches.
     */
    public function matchesConditions(array $automation, string $trigger, array $entityData): bool
    {
        if (($automation['isActive'] ?? false) !== true) {
            return false;
        }

        if (($automation['trigger'] ?? '') !== $trigger) {
            return false;
        }

        $conditions = $automation['triggerConditions'] ?? [];
        if (empty($conditions) === true) {
            return true;
        }

        return $this->evaluateConditions(conditions: $conditions, entityData: $entityData);
    }//end matchesConditions()

    /**
     * Evaluate trigger conditions against entity data.
     *
     * @param array $conditions The conditions to evaluate.
     * @param array $entityData The entity data.
     *
     * @return bool Whether all conditions are met.
     */
    private function evaluateConditions(array $conditions, array $entityData): bool
    {
        foreach ($conditions as $field => $expected) {
            $actual = $entityData[$field] ?? null;
            if ($actual === null) {
                return false;
            }

            if (is_array($expected) === true) {
                if (isset($expected['operator']) === true) {
                    $result = $this->evaluateOperator(
                        operator: $expected['operator'],
                        actual: $actual,
                        value: $expected['value'] ?? null
                    );
                    if ($result === false) {
                        return false;
                    }

                    continue;
                }

                if (in_array($actual, $expected, true) === false) {
                    return false;
                }

                continue;
            }

            if ((string) $actual !== (string) $expected) {
                return false;
            }
        }//end foreach

        return true;
    }//end evaluateConditions()

    /**
     * Evaluate a comparison operator.
     *
     * @param string $operator The operator (gt, gte, lt, lte, eq, neq).
     * @param mixed  $actual   The actual value.
     * @param mixed  $value    The expected value.
     *
     * @return bool Whether the comparison passes.
     */
    private function evaluateOperator(string $operator, mixed $actual, mixed $value): bool
    {
        return match ($operator) {
            'gt'    => $actual > $value,
            'gte'   => $actual >= $value,
            'lt'    => $actual < $value,
            'lte'   => $actual <= $value,
            'eq'    => $actual == $value,
            'neq'   => $actual != $value,
            default => false,
        };
    }//end evaluateOperator()

    /**
     * Build a webhook payload for an automation trigger.
     *
     * @param array  $automation The automation configuration.
     * @param string $trigger    The trigger event type.
     * @param array  $entityData The entity data.
     *
     * @return array The webhook payload.
     */
    public function buildWebhookPayload(array $automation, string $trigger, array $entityData): array
    {
        return [
            'automationId'   => $automation['id'] ?? '',
            'automationName' => $automation['name'] ?? '',
            'trigger'        => $trigger,
            'entity'         => $entityData,
            'timestamp'      => (new \DateTime())->format('c'),
            'actions'        => $automation['actions'] ?? [],
        ];
    }//end buildWebhookPayload()

    /**
     * Execute a webhook action by sending entity data to the configured URL.
     *
     * @param string $webhookUrl The target webhook URL.
     * @param array  $payload    The payload to send.
     *
     * @return array The execution result with status and response.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function fireWebhook(string $webhookUrl, array $payload): array
    {
        if (empty($webhookUrl) === true) {
            return ['status' => 'skipped', 'reason' => 'No webhook URL configured'];
        }

        try {
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $status = 'success';
            } else {
                $status = 'failure';
            }

            return [
                'status'   => $status,
                'httpCode' => $httpCode,
                'response' => $response,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Automation webhook failed: '.$e->getMessage());
            return [
                'status' => 'failure',
                'error'  => $e->getMessage(),
            ];
        }//end try
    }//end fireWebhook()

    /**
     * List all automations from OpenRegister.
     *
     * @param array $params Optional query parameters (limit, offset, filters).
     *
     * @return array The list of automations.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function listAutomations(array $params = []): array
    {
        try {
            $result = $this->getObjectService()->findObjects(
                register: 'pipelinq',
                schema: 'automation',
                params: $params
            );
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to list automations: '.$e->getMessage());
            return [];
        }
    }//end listAutomations()

    /**
     * Get a single automation by ID.
     *
     * @param string $id The automation ID.
     *
     * @return array|null The automation or null if not found.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function getAutomation(string $id): ?array
    {
        try {
            return $this->getObjectService()->findObject(
                register: 'pipelinq',
                schema: 'automation',
                id: $id
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to get automation: '.$e->getMessage());
            return null;
        }
    }//end getAutomation()

    /**
     * Save an automation (create or update).
     *
     * @param array $data The automation data.
     *
     * @return array|null The saved automation or null if failed.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function saveAutomation(array $data): ?array
    {
        try {
            return $this->getObjectService()->saveObject(
                register: 'pipelinq',
                schema: 'automation',
                object: $data
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to save automation: '.$e->getMessage());
            return null;
        }
    }//end saveAutomation()

    /**
     * Delete an automation by ID.
     *
     * @param string $id The automation ID.
     *
     * @return bool Whether the deletion was successful.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function deleteAutomation(string $id): bool
    {
        try {
            $this->getObjectService()->deleteObject(
                register: 'pipelinq',
                schema: 'automation',
                id: $id
            );
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete automation: '.$e->getMessage());
            return false;
        }
    }//end deleteAutomation()

    /**
     * Find automations matching a trigger and entity.
     *
     * @param string $trigger The trigger type.
     * @param array  $entity  The entity data to check conditions against.
     *
     * @return array List of matching automations.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function getMatchingAutomations(string $trigger, array $entity): array
    {
        try {
            $allAutomations = $this->listAutomations(['_limit' => 1000]);
            $matching = [];
            foreach ($allAutomations as $automation) {
                if ($this->matchesConditions(automation: $automation, trigger: $trigger, entityData: $entity)) {
                    $matching[] = $automation;
                }
            }

            return $matching;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get matching automations: '.$e->getMessage());
            return [];
        }
    }//end getMatchingAutomations()

    /**
     * Execute an automation's actions.
     *
     * @param array $automation  The automation configuration.
     * @param array $entityData  The entity data being processed.
     *
     * @return array Execution result with status and action results.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function executeAutomation(array $automation, array $entityData): array
    {
        $results = [];
        $actionResults = [];

        try {
            $actions = $automation['actions'] ?? [];
            foreach ($actions as $action) {
                $actionType = $action['type'] ?? '';
                $config = $action['config'] ?? [];

                $actionResult = match ($actionType) {
                    'webhook' => $this->executeWebhookAction(config: $config, entityData: $entityData),
                    'send_notification' => $this->executeNotificationAction(config: $config, entityData: $entityData),
                    default => ['status' => 'skipped', 'reason' => 'Unknown action type: '.$actionType],
                };

                $actionResults[] = [
                    'type' => $actionType,
                    'result' => $actionResult['status'],
                    'error' => $actionResult['error'] ?? null,
                ];
            }

            // Update automation stats
            $automation['lastRun'] = (new \DateTime())->format('c');
            $automation['runCount'] = ($automation['runCount'] ?? 0) + 1;
            $this->saveAutomation(data: $automation);

            $status = 'success';
            $results = ['status' => $status, 'actionsExecuted' => $actionResults];
        } catch (\Exception $e) {
            $this->logger->error('Automation execution failed: '.$e->getMessage());
            $status = 'failure';
            $results = ['status' => $status, 'error' => $e->getMessage(), 'actionsExecuted' => $actionResults];
        }

        return $results;
    }//end executeAutomation()

    /**
     * Execute a webhook action.
     *
     * @param array $config     The action configuration.
     * @param array $entityData The entity data.
     *
     * @return array The action result.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    private function executeWebhookAction(array $config, array $entityData): array
    {
        $webhookUrl = $config['webhookUrl'] ?? '';
        if (empty($webhookUrl)) {
            return ['status' => 'failed', 'error' => 'No webhook URL configured'];
        }

        $payload = $this->buildWebhookPayload(
            automation: $config,
            trigger: $config['trigger'] ?? 'manual',
            entityData: $entityData
        );
        return $this->fireWebhook(webhookUrl: $webhookUrl, payload: $payload);
    }//end executeWebhookAction()

    /**
     * Execute a notification action.
     *
     * @param array $config     The action configuration.
     * @param array $entityData The entity data.
     *
     * @return array The action result.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    private function executeNotificationAction(array $config, array $entityData): array
    {
        // Placeholder for notification execution
        // In a complete implementation, this would send notifications to users or external systems
        return ['status' => 'success'];
    }//end executeNotificationAction()

    /**
     * Log automation execution.
     *
     * @param string $automationId The automation ID.
     * @param array  $result       The execution result.
     *
     * @return array|null The created log entry or null if failed.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function logExecution(string $automationId, array $result): ?array
    {
        try {
            $logEntry = [
                'automation' => $automationId,
                'triggeredAt' => (new \DateTime())->format('c'),
                'triggerEntity' => $result['triggerEntity'] ?? '',
                'actionsExecuted' => $result['actionsExecuted'] ?? [],
                'status' => $result['status'] ?? 'unknown',
                'error' => $result['error'] ?? null,
            ];

            return $this->getObjectService()->saveObject(
                register: 'pipelinq',
                schema: 'automationLog',
                object: $logEntry
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to log automation execution: '.$e->getMessage());
            return null;
        }
    }//end logExecution()

    /**
     * Get execution history for an automation.
     *
     * @param string $automationId The automation ID.
     * @param array  $params       Optional query parameters.
     *
     * @return array List of log entries.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function getExecutionHistory(string $automationId, array $params = []): array
    {
        try {
            $params['automation'] = $automationId;
            $params['_limit'] = $params['_limit'] ?? 100;
            return $this->getObjectService()->findObjects(
                register: 'pipelinq',
                schema: 'automationLog',
                params: $params
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to get execution history: '.$e->getMessage());
            return [];
        }
    }//end getExecutionHistory()
}//end class
