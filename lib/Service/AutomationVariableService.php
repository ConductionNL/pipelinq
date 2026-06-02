<?php

/**
 * Pipelinq AutomationVariableService.
 *
 * Read-only query surface over the runtime state of automations: which
 * automations have executed at least once, their last-trigger context, and the
 * variable bindings (actionsExecuted + output variables) from the most recent
 * automationLog. Backs the runtime variable query REST API so external tools
 * (n8n, dashboards) can inspect automation state without direct DB access.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Queries automation runtime state from automation + automationLog objects.
 *
 * All reads are scoped to this app's register/schema; a caller can never read
 * another app's automation state. Filters (trigger, status, from, to) are
 * applied server-side over the app's own logs.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
 */
class AutomationVariableService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (OR ObjectService).
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List automations that have executed at least once, with runtime state.
     *
     * Returns only automations with runCount > 0; each entry carries
     * automationId, name, lastRun, runCount, lastTriggerEntity and lastStatus
     * (derived from the most recent log).
     *
     * @return array<int, array<string, mixed>> The active automations with state.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
     */
    public function getActiveAutomations(): array
    {
        $automations = $this->fetchAll(schemaKey: 'automation_schema');

        $active = [];
        foreach ($automations as $automation) {
            if ((int) ($automation['runCount'] ?? 0) <= 0) {
                continue;
            }

            $automationId = (string) ($automation['id'] ?? $automation['uuid'] ?? '');
            $lastLog      = $this->getLatestLog(automationId: $automationId);

            $active[] = [
                'automationId'      => $automationId,
                'name'              => (string) ($automation['name'] ?? ''),
                'lastRun'           => ($automation['lastRun'] ?? null),
                'runCount'          => (int) ($automation['runCount'] ?? 0),
                'lastTriggerEntity' => ($lastLog['triggerEntity'] ?? ($automation['lastTriggerEntity'] ?? null)),
                'lastStatus'        => ($lastLog['status'] ?? null),
            ];
        }//end foreach

        return $active;
    }//end getActiveAutomations()

    /**
     * Return the most recent execution context for an automation.
     *
     * @param string $automationId The automation UUID.
     *
     * @return array<string, mixed> The most recent automationLog, or an empty array.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
     */
    public function getRuntimeState(string $automationId): array
    {
        return $this->getLatestLog(automationId: $automationId);
    }//end getRuntimeState()

    /**
     * Return the variable bindings from an automation's most recent execution.
     *
     * The bindings include the trigger data snapshot, the actionsExecuted
     * results, and any output variables produced by apply_decision actions. If
     * the automation has never executed, an empty array is returned (the
     * controller surfaces this as `variables: []` with HTTP 200).
     *
     * @param string $automationId The automation UUID.
     *
     * @return array<string, mixed> The variable bindings.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
     */
    public function getVariableBindings(string $automationId): array
    {
        $log = $this->getLatestLog(automationId: $automationId);
        if ($log === []) {
            return [];
        }

        $actionsExecuted = ($log['actionsExecuted'] ?? []);
        if (is_array($actionsExecuted) === false) {
            $actionsExecuted = [];
        }

        $outputs = [];
        foreach ($actionsExecuted as $action) {
            if (is_array($action) === true && isset($action['output']) === true && is_array($action['output']) === true) {
                $outputs = array_merge($outputs, $action['output']);
            }
        }

        return [
            'triggerData'     => ($log['triggerData'] ?? []),
            'actionsExecuted' => $actionsExecuted,
            'outputVariables' => $outputs,
            'status'          => ($log['status'] ?? null),
            'triggeredAt'     => ($log['triggeredAt'] ?? null),
        ];
    }//end getVariableBindings()

    /**
     * List automationLog entries, optionally filtered.
     *
     * Supports filtering by trigger (via the parent automation), status, and a
     * triggeredAt window (from/to, ISO 8601). Used by the runtime history API.
     *
     * @param array<string, mixed> $filters The filter map (trigger, status, from, to).
     *
     * @return array<int, array<string, mixed>> The matching log entries.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One guard per optional
     *  filter (status, from, to) over the scoped log set; each branch is a
     *  trivial bounds check.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same: the path count is the
     *  product of the independent, optional filter guards, not nested logic.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
     */
    public function queryLogs(array $filters): array
    {
        $logs   = $this->fetchAll(schemaKey: 'automationLog_schema');
        $status = '';
        $from   = '';
        $to     = '';
        if (isset($filters['status']) === true) {
            $status = (string) $filters['status'];
        }

        if (isset($filters['from']) === true) {
            $from = (string) $filters['from'];
        }

        if (isset($filters['to']) === true) {
            $to = (string) $filters['to'];
        }

        $result = [];
        foreach ($logs as $log) {
            if ($status !== '' && (string) ($log['status'] ?? '') !== $status) {
                continue;
            }

            $triggeredAt = (string) ($log['triggeredAt'] ?? '');
            if ($from !== '' && $triggeredAt !== '' && strcmp($triggeredAt, $from) < 0) {
                continue;
            }

            if ($to !== '' && $triggeredAt !== '' && strcmp($triggeredAt, $to) > 0) {
                continue;
            }

            $result[] = $log;
        }//end foreach

        return $result;
    }//end queryLogs()

    /**
     * Get the most recent automationLog entry for an automation.
     *
     * @param string $automationId The automation UUID.
     *
     * @return array<string, mixed> The most recent log, or an empty array.
     */
    private function getLatestLog(string $automationId): array
    {
        if ($automationId === '') {
            return [];
        }

        [$register, $schema] = $this->scope(schemaKey: 'automationLog_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register'   => $register,
                        'schema'     => $schema,
                        'automation' => $automationId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch automation logs', ['exception' => $e->getMessage()]);
            return [];
        }

        $latest    = [];
        $latestKey = '';
        foreach (($results ?? []) as $result) {
            $log = $this->toArray(object: $result);
            $key = (string) ($log['triggeredAt'] ?? '');
            if ($latest === [] || strcmp($key, $latestKey) > 0) {
                $latest    = $log;
                $latestKey = $key;
            }
        }//end foreach

        return $latest;
    }//end getLatestLog()

    /**
     * Fetch all objects for a schema config key, scoped to this app.
     *
     * @param string $schemaKey The app-config schema key.
     *
     * @return array<int, array<string, mixed>> The objects.
     */
    private function fetchAll(string $schemaKey): array
    {
        [$register, $schema] = $this->scope(schemaKey: $schemaKey);

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch automation objects', ['exception' => $e->getMessage()]);
            return [];
        }

        $objects = [];
        foreach (($results ?? []) as $result) {
            $objects[] = $this->toArray(object: $result);
        }

        return $objects;
    }//end fetchAll()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key.
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
}//end class
