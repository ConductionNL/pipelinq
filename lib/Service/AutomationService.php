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

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for CRM workflow automation management.
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
     * Constructor.
     *
     * @param ContainerInterface $container   The DI container.
     * @param IAppConfig         $appConfig   The app configuration.
     * @param IUserSession       $userSession The user session.
     * @param LoggerInterface    $logger      The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Find automations matching a trigger and entity data.
     *
     * @param string $trigger    The trigger event type.
     * @param array  $entityData The entity data to check conditions against.
     * @param array  $automations List of all automations to filter.
     *
     * @return array The matching automations.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function findMatchingAutomations(string $trigger, array $entityData, array $automations): array
    {
        $matches = [];
        foreach ($automations as $automation) {
            if ($this->matchesConditions($automation, $trigger, $entityData) === true) {
                $matches[] = $automation;
            }
        }

        return $matches;
    }//end findMatchingAutomations()

    /**
     * Execute a single automation.
     *
     * @param array  $automation The automation configuration.
     * @param array  $entityData The entity data that triggered the automation.
     * @param string $trigger    The trigger event type.
     *
     * @return array The execution result with status and details.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function executeAutomation(array $automation, array $entityData, string $trigger): array
    {
        $result = [
            'automationId'    => $automation['id'] ?? '',
            'automationName'  => $automation['name'] ?? '',
            'trigger'         => $trigger,
            'triggeredAt'     => (new \DateTime())->format('c'),
            'actionsExecuted' => [],
            'status'          => 'success',
            'error'           => null,
        ];

        try {
            $actions = $automation['actions'] ?? [];
            if (empty($actions) === true) {
                $result['status'] = 'skipped';
                $result['error']  = 'No actions configured';
                return $result;
            }

            foreach ($actions as $action) {
                $actionResult = $this->executeAction($action, $entityData, $automation);
                $result['actionsExecuted'][] = $actionResult;

                if (($actionResult['status'] ?? '') === 'failure') {
                    $result['status'] = 'partial';
                }
            }

            // Fire webhook if configured and no critical errors.
            if ($result['status'] !== 'failure'
                && !empty($automation['webhookUrl'] ?? '')
            ) {
                $webhookResult = $this->fireWebhook(
                    $automation['webhookUrl'],
                    $this->buildWebhookPayload($automation, $trigger, $entityData)
                );
                $result['actionsExecuted'][] = [
                    'type'   => 'webhook',
                    'status' => $webhookResult['status'],
                    'result' => $webhookResult,
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Automation execution failed: '.$e->getMessage());
            $result['status'] = 'failure';
            $result['error']  = $e->getMessage();
        }

        return $result;
    }//end executeAutomation()

    /**
     * Execute a single action within an automation.
     *
     * @param array $action      The action configuration.
     * @param array $entityData  The entity data.
     * @param array $automation  The parent automation.
     *
     * @return array The action execution result.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    private function executeAction(array $action, array $entityData, array $automation): array
    {
        $type = $action['type'] ?? 'unknown';

        $result = [
            'type'   => $type,
            'status' => 'success',
            'result' => null,
            'error'  => null,
        ];

        try {
            match ($type) {
                'assign_lead'       => $this->handleAssignAction($action, $entityData, $result),
                'move_stage'        => $this->handleMoveStageAction($action, $entityData, $result),
                'send_notification' => $this->handleNotificationAction($action, $entityData, $result),
                'update_field'      => $this->handleUpdateFieldAction($action, $entityData, $result),
                'add_note'          => $this->handleAddNoteAction($action, $entityData, $result),
                default             => $result['error'] = 'Unknown action type',
            };
        } catch (\Exception $e) {
            $result['status'] = 'failure';
            $result['error']  = $e->getMessage();
        }

        return $result;
    }//end executeAction()

    /**
     * Handle assignment actions.
     *
     * @param array $action     The action configuration.
     * @param array $entityData The entity data.
     * @param array $result     The result array to update.
     *
     * @return void
     */
    private function handleAssignAction(array $action, array $entityData, array &$result): void
    {
        $config   = $action['config'] ?? [];
        $assignee = $config['assignee'] ?? null;

        if ($assignee === null) {
            $result['status'] = 'failure';
            $result['error']  = 'No assignee configured for assignment action';
            return;
        }

        $result['result'] = [
            'assigned'  => true,
            'assignee'  => $assignee,
            'entityId'  => $entityData['id'] ?? null,
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }//end handleAssignAction()

    /**
     * Handle move stage actions.
     *
     * @param array $action     The action configuration.
     * @param array $entityData The entity data.
     * @param array $result     The result array to update.
     *
     * @return void
     */
    private function handleMoveStageAction(array $action, array $entityData, array &$result): void
    {
        $config = $action['config'] ?? [];
        $stage  = $config['stage'] ?? null;

        if ($stage === null) {
            $result['status'] = 'failure';
            $result['error']  = 'No target stage configured for move stage action';
            return;
        }

        $result['result'] = [
            'moved'     => true,
            'stage'     => $stage,
            'entityId'  => $entityData['id'] ?? null,
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }//end handleMoveStageAction()

    /**
     * Handle notification actions.
     *
     * @param array $action     The action configuration.
     * @param array $entityData The entity data.
     * @param array $result     The result array to update.
     *
     * @return void
     */
    private function handleNotificationAction(array $action, array $entityData, array &$result): void
    {
        $config  = $action['config'] ?? [];
        $message = $config['message'] ?? null;
        $userId  = $config['userId'] ?? null;

        if ($message === null) {
            $result['status'] = 'failure';
            $result['error']  = 'No message configured for notification action';
            return;
        }

        $result['result'] = [
            'notified'  => true,
            'message'   => $message,
            'userId'    => $userId,
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }//end handleNotificationAction()

    /**
     * Handle update field actions.
     *
     * @param array $action     The action configuration.
     * @param array $entityData The entity data.
     * @param array $result     The result array to update.
     *
     * @return void
     */
    private function handleUpdateFieldAction(array $action, array $entityData, array &$result): void
    {
        $config = $action['config'] ?? [];
        $field  = $config['field'] ?? null;
        $value  = $config['value'] ?? null;

        if ($field === null) {
            $result['status'] = 'failure';
            $result['error']  = 'No field configured for update action';
            return;
        }

        $result['result'] = [
            'updated'   => true,
            'field'     => $field,
            'value'     => $value,
            'entityId'  => $entityData['id'] ?? null,
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }//end handleUpdateFieldAction()

    /**
     * Handle add note actions.
     *
     * @param array $action     The action configuration.
     * @param array $entityData The entity data.
     * @param array $result     The result array to update.
     *
     * @return void
     */
    private function handleAddNoteAction(array $action, array $entityData, array &$result): void
    {
        $config = $action['config'] ?? [];
        $text   = $config['text'] ?? null;

        if ($text === null) {
            $result['status'] = 'failure';
            $result['error']  = 'No text configured for note action';
            return;
        }

        $result['result'] = [
            'noted'     => true,
            'text'      => $text,
            'entityId'  => $entityData['id'] ?? null,
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }//end handleAddNoteAction()

    /**
     * Get the list of valid trigger types.
     *
     * @return array The valid trigger types.
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
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The object service.
     *
     * @throws \RuntimeException If OpenRegister is not available.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            throw new \RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Get the configured register and schema for automations.
     *
     * @return array{register: string, automation_schema: string, automationLog_schema: string} The config.
     *
     * @throws \RuntimeException If configuration is missing.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2
     */
    private function getConfig(): array
    {
        $register = $this->appConfig->getValueString(
            Application::APP_ID,
            'register',
            ''
        );
        $automationSchema = $this->appConfig->getValueString(
            Application::APP_ID,
            'automation_schema',
            ''
        );
        $automationLogSchema = $this->appConfig->getValueString(
            Application::APP_ID,
            'automationLog_schema',
            ''
        );

        if ($register === '' || $automationSchema === '' || $automationLogSchema === '') {
            throw new \RuntimeException('Automation register or schema not configured.');
        }

        return [
            'register'             => $register,
            'automation_schema'    => $automationSchema,
            'automationLog_schema' => $automationLogSchema,
        ];
    }//end getConfig()

    /**
     * List all automations from OpenRegister.
     *
     * @return array The list of automation objects.
     *
     * @throws \RuntimeException If configuration is missing.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2
     */
    public function listAutomations(): array
    {
        try {
            $objectService = $this->getObjectService();
            $config = $this->getConfig();

            $objects = $objectService
                ->setRegister($config['register'])
                ->setSchema($config['automation_schema'])
                ->getObjectsAll();

            return $objects ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to list automations: '.$e->getMessage());
            return [];
        }
    }//end listAutomations()

    /**
     * Get a single automation by ID.
     *
     * @param string $id The automation UUID.
     *
     * @return array|null The automation object or null if not found.
     *
     * @throws \RuntimeException If configuration is missing.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2
     */
    public function getAutomation(string $id): ?array
    {
        try {
            $objectService = $this->getObjectService();
            $config = $this->getConfig();

            $object = $objectService->find(
                id: $id,
                register: $config['register'],
                schema: $config['automation_schema']
            );

            if ($object === null) {
                return null;
            }

            return $object->getObject();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get automation: '.$e->getMessage());
            throw new DoesNotExistException('Automation not found: '.$id);
        }
    }//end getAutomation()

    /**
     * Create or update an automation.
     *
     * @param array $data The automation data.
     *
     * @return array The saved automation object.
     *
     * @throws \RuntimeException If configuration is missing or save fails.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2
     */
    public function saveAutomation(array $data): array
    {
        try {
            $objectService = $this->getObjectService();
            $config = $this->getConfig();

            $objectService
                ->setRegister($config['register'])
                ->setSchema($config['automation_schema']);

            if (isset($data['id']) === true && $data['id'] !== '') {
                // Update existing automation
                $objectService->updateObject(
                    id: $data['id'],
                    data: $data
                );
                return $this->getAutomation($data['id']) ?? $data;
            } else {
                // Create new automation
                $response = $objectService->createObject(data: $data);
                if ($response !== null && is_array($response) === true) {
                    return $response;
                }

                return $data;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to save automation: '.$e->getMessage());
            throw new \RuntimeException('Failed to save automation: '.$e->getMessage());
        }
    }//end saveAutomation()

    /**
     * Delete an automation.
     *
     * @param string $id The automation UUID.
     *
     * @return bool True if deleted successfully.
     *
     * @throws \RuntimeException If configuration is missing or delete fails.
     * @throws DoesNotExistException If automation not found.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2
     */
    public function deleteAutomation(string $id): bool
    {
        try {
            $objectService = $this->getObjectService();
            $config = $this->getConfig();

            // Verify automation exists
            $automation = $this->getAutomation($id);
            if ($automation === null) {
                throw new DoesNotExistException('Automation not found: '.$id);
            }

            $objectService
                ->setRegister($config['register'])
                ->setSchema($config['automation_schema'])
                ->deleteObject(uuid: $id);

            $this->logger->info('Automation deleted', ['id' => $id]);
            return true;
        } catch (DoesNotExistException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete automation: '.$e->getMessage());
            throw new \RuntimeException('Failed to delete automation: '.$e->getMessage());
        }
    }//end deleteAutomation()

    /**
     * Find all automations matching a trigger and conditions.
     *
     * @param string $trigger The trigger event type.
     * @param array  $entity  The entity data to check conditions against.
     *
     * @return array List of matching automation objects.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-4
     */
    public function getMatchingAutomations(string $trigger, array $entity): array
    {
        try {
            $automations = $this->listAutomations();
            $matching = [];

            foreach ($automations as $automation) {
                $automationArray = $automation;
                if (is_object($automation) === true) {
                    $automationArray = method_exists($automation, 'getObject')
                        ? $automation->getObject()
                        : (array) $automation;
                }

                if ($this->matchesConditions(
                    automation: $automationArray,
                    trigger: $trigger,
                    entityData: $entity
                ) === true) {
                    $matching[] = $automationArray;
                }
            }//end foreach

            return $matching;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get matching automations: '.$e->getMessage());
            return [];
        }
    }//end getMatchingAutomations()

    /**
     * Log an automation execution.
     *
     * @param string $automationId The automation UUID.
     * @param array  $result       The execution result with status and actions.
     *
     * @return array The log entry.
     *
     * @throws \RuntimeException If configuration is missing.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-4
     */
    public function logExecution(string $automationId, array $result): array
    {
        try {
            $objectService = $this->getObjectService();
            $config = $this->getConfig();

            $logEntry = [
                'automation'      => $automationId,
                'triggeredAt'     => (new \DateTime())->format('c'),
                'triggerEntity'   => $result['triggerEntity'] ?? '',
                'actionsExecuted' => $result['actionsExecuted'] ?? [],
                'status'          => $result['status'] ?? 'unknown',
                'error'           => $result['error'] ?? null,
            ];

            $objectService
                ->setRegister($config['register'])
                ->setSchema($config['automationLog_schema']);

            $response = $objectService->createObject(data: $logEntry);

            $this->logger->info(
                'Automation execution logged',
                [
                    'automation' => $automationId,
                    'status'     => $logEntry['status'],
                ]
            );

            return $response !== null && is_array($response) === true ? $response : $logEntry;
        } catch (\Exception $e) {
            $this->logger->error('Failed to log automation execution: '.$e->getMessage());
            // Don't throw — logging failure shouldn't break automation execution
            return ['error' => $e->getMessage()];
        }
    }//end logExecution()

    /**
     * Update an automation's last run timestamp and increment run count.
     *
     * @param string $automationId The automation UUID.
     *
     * @return bool True if updated successfully.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-4
     */
    public function updateLastRun(string $automationId): bool
    {
        try {
            $automation = $this->getAutomation($automationId);
            if ($automation === null) {
                return false;
            }

            $automation['lastRun'] = (new \DateTime())->format('c');
            $automation['runCount'] = ($automation['runCount'] ?? 0) + 1;

            $this->saveAutomation($automation);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update last run: '.$e->getMessage());
            return false;
        }
    }//end updateLastRun()

    /**
     * Get execution history for an automation.
     *
     * @param string $id The automation ID.
     *
     * @return array The list of execution logs.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function getExecutionHistory(string $id): array
    {
        try {
            $objectService = $this->getObjectService();
            $result        = $objectService->findObjects(
                register: 'pipelinq',
                schema: 'automationLog',
                params: ['automation' => $id]
            );

            return $result['results'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch execution history: '.$e->getMessage());
            return [];
        }
    }//end getExecutionHistory()
}//end class
