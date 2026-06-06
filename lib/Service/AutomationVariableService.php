<?php

/**
 * Pipelinq AutomationVariableService.
 *
 * Service that exposes runtime state for active automations — last run,
 * most recent automationLog entry, and the executed variable bindings —
 * so external dashboards (n8n, monitoring) can inspect automation state
 * without direct database access.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that queries runtime state for automations using OpenRegister.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
 */
class AutomationVariableService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container (lazy OpenRegister lookup).
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List automations that have executed at least once.
     *
     * @return array<int, array<string, mixed>> Active automations as raw arrays.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
     */
    public function getActiveAutomations(): array
    {
        $register = $this->getRegister();
        $schema   = $this->getSchema('automation_schema');
        if ($register === '' || $schema === '') {
            return [];
        }
        try {
            $rows = $this->getObjectService()->findAll([
                'register' => $register,
                'schema'   => $schema,
                'limit'    => 500,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AutomationVariableService.getActiveAutomations failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $active = [];
        foreach ($rows as $row) {
            $row = $this->normalize($row);
            $count = (int) ($row['runCount'] ?? 0);
            if ($count > 0) {
                $active[] = $row;
            }
        }

        return $active;
    }//end getActiveAutomations()

    /**
     * Return the most recent automationLog entry for a given automation.
     *
     * @param string $automationId Automation slug or UUID.
     *
     * @return array<string, mixed> The most recent log array, or empty when none exist.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
     */
    public function getRuntimeState(string $automationId): array
    {
        $register = $this->getRegister();
        $schema   = $this->getSchema('automationLog_schema');
        if ($register === '' || $schema === '' || $automationId === '') {
            return [];
        }
        try {
            $rows = $this->getObjectService()->findAll([
                'register' => $register,
                'schema'   => $schema,
                'filters'  => ['automation' => $automationId],
                'limit'    => 50,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AutomationVariableService.getRuntimeState failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $logs = array_map([$this, 'normalize'], $rows);
        usort($logs, static function (array $a, array $b): int {
            return strcmp((string) ($b['triggeredAt'] ?? ''), (string) ($a['triggeredAt'] ?? ''));
        });

        return ($logs[0] ?? []);
    }//end getRuntimeState()

    /**
     * Return the variable bindings (actionsExecuted) from the most recent log.
     *
     * @param string $automationId Automation slug or UUID.
     *
     * @return array<int, array<string, mixed>> The per-action result records.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.3
     */
    public function getVariableBindings(string $automationId): array
    {
        $state = $this->getRuntimeState($automationId);
        $vars  = ($state['actionsExecuted'] ?? []);
        if (is_array($vars) === false) {
            return [];
        }
        return $vars;
    }//end getVariableBindings()

    /**
     * Coerce a row (entity/array) into an associative array.
     *
     * @param mixed $row Source value.
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
     * Get configured register slug.
     *
     * @return string Register slug or empty string.
     */
    private function getRegister(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
    }//end getRegister()

    /**
     * Get configured schema slug for a config key.
     *
     * @param string $key Config key.
     *
     * @return string Schema slug or empty string.
     */
    private function getSchema(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end getSchema()

    /**
     * Resolve the OpenRegister ObjectService.
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
