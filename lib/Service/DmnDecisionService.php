<?php

/**
 * Pipelinq DmnDecisionService.
 *
 * Service that delegates DMN decision evaluation to OpenRegister's
 * WorkflowEngineRegistry and applies decision output back to CRM entities
 * via ObjectService.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for DMN decision evaluation via the OpenRegister workflow engine.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.2
 */
class DmnDecisionService
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
     * Evaluate a DMN decision table against the provided input data.
     *
     * @param string $decisionTableId The decision-table identifier (numeric engine id or slug).
     * @param array  $inputData       The input variable bindings.
     *
     * @return array{decisionTableId: string, evaluatedAt: string, output: array<string, mixed>}
     *
     * @throws \InvalidArgumentException When decisionTableId is empty/invalid.
     * @throws \RuntimeException        When the evaluation fails.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.2
     */
    public function evaluateDecision(string $decisionTableId, array $inputData): array
    {
        $decisionTableId = trim($decisionTableId);
        if ($decisionTableId === '') {
            throw new \InvalidArgumentException('decisionTableId is required.');
        }

        $registry = $this->getRegistry();

        if (is_numeric($decisionTableId) === false) {
            throw new \InvalidArgumentException('decisionTableId must reference a numeric engine id.');
        }

        try {
            $adapter = $registry->resolveAdapterById((int) $decisionTableId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to resolve DMN adapter for decisionTableId.');
        }

        try {
            $output = $this->invokeAdapter($adapter, $inputData);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DmnDecisionService.evaluateDecision failed',
                ['exception' => $e->getMessage(), 'decisionTableId' => $decisionTableId]
            );
            throw new \RuntimeException('DMN evaluation failed.');
        }

        return [
            'decisionTableId' => $decisionTableId,
            'evaluatedAt'     => date('c'),
            'output'          => $output,
        ];
    }//end evaluateDecision()

    /**
     * List available DMN engines from the WorkflowEngineRegistry.
     *
     * @return array<int, array<string, mixed>> Decision tables (id, name, description).
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.2
     */
    public function listDecisionTables(): array
    {
        try {
            $registry = $this->getRegistry();
            $engines  = $registry->getEnginesByType('dmn');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DmnDecisionService.listDecisionTables failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach ($engines as $engine) {
            $entry = ['id' => null, 'name' => '', 'description' => ''];
            if (is_array($engine) === true) {
                $entry['id']          = ($engine['id'] ?? null);
                $entry['name']        = (string) ($engine['name'] ?? '');
                $entry['description'] = (string) ($engine['description'] ?? '');
            } elseif (is_object($engine) === true) {
                if (method_exists($engine, 'getId') === true) {
                    $entry['id'] = $engine->getId();
                }
                if (method_exists($engine, 'getName') === true) {
                    $entry['name'] = (string) $engine->getName();
                }
                if (method_exists($engine, 'getDescription') === true) {
                    $entry['description'] = (string) $engine->getDescription();
                }
            }
            $out[] = $entry;
        }

        return $out;
    }//end listDecisionTables()

    /**
     * Apply a DMN decision output back onto the triggering entity.
     *
     * @param string $entityId       Entity UUID or slug.
     * @param string $schema         Schema slug.
     * @param array  $decisionOutput Output values keyed by property name.
     *
     * @return void
     *
     * @throws \RuntimeException When the write fails.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.2
     */
    public function applyDecisionToEntity(string $entityId, string $schema, array $decisionOutput): void
    {
        $register = $this->getRegister();
        if ($register === '' || $schema === '' || $entityId === '') {
            throw new \RuntimeException('Cannot apply decision: missing register, schema, or entity id.');
        }

        $objectService = $this->getObjectService();
        $current       = $objectService->find(
            id: $entityId,
            register: $register,
            schema: $schema
        );
        if ($current === null) {
            throw new \RuntimeException('Entity not found for decision application.');
        }

        $base = [];
        if (is_array($current) === true) {
            $base = $current;
        } elseif (is_object($current) === true && method_exists($current, 'getObject') === true) {
            $candidate = $current->getObject();
            if (is_array($candidate) === true) {
                $base = $candidate;
            }
        }
        $merged = array_merge($base, $decisionOutput);

        $objectService->saveObject(
            $merged,
            [],
            $register,
            $schema,
            $entityId
        );
    }//end applyDecisionToEntity()

    /**
     * Adapter invocation helper. Tries common adapter method names so this
     * service does not hard-couple to a single workflow-engine signature.
     *
     * @param object $adapter   Workflow-engine adapter.
     * @param array  $inputData Input bindings.
     *
     * @return array<string, mixed> Decision output map.
     */
    private function invokeAdapter(object $adapter, array $inputData): array
    {
        foreach (['evaluate', 'evaluateDecision', 'execute', 'run'] as $method) {
            if (method_exists($adapter, $method) === true) {
                $result = $adapter->{$method}($inputData);
                if (is_array($result) === true) {
                    return $result;
                }
            }
        }
        throw new \RuntimeException('No evaluate method on DMN adapter.');
    }//end invokeAdapter()

    /**
     * Get the configured register slug.
     *
     * @return string Register slug or empty string.
     */
    private function getRegister(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
    }//end getRegister()

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

    /**
     * Resolve the OpenRegister WorkflowEngineRegistry.
     *
     * @return object The registry instance.
     *
     * @throws \RuntimeException When the registry is not available.
     */
    private function getRegistry(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\WorkflowEngineRegistry');
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenRegister WorkflowEngineRegistry is not available.');
        }
    }//end getRegistry()
}//end class
