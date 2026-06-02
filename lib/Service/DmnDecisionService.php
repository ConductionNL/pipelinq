<?php

/**
 * Pipelinq DmnDecisionService.
 *
 * Server-side evaluator for DMN-style (Decision Model and Notation) decision
 * tables stored as OpenRegister `decisionTable` objects. A decision table maps
 * a set of input conditions (the `rules[].when` map) to a set of output values
 * (the `rules[].then` map). Used for automated lead scoring, SLA-tier
 * assignment, routing and eligibility rules. Evaluation is fully self-contained
 * and deterministic; no external workflow engine is required.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Evaluates DMN-style decision tables against CRM entity data.
 *
 * Decision tables are app-owned OpenRegister objects, scoped to this app's
 * register/schema; a caller cannot evaluate a table belonging to another app
 * (a bad id resolves to a 404/InvalidArgumentException, never a silent empty
 * result). Evaluation failures throw (REQ-DMN-004) so the controller can return
 * a 400 and the automation engine can record a failed step — they never return
 * a misleading empty array.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.2
 */
class DmnDecisionService
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
     * Evaluate a decision table against input data.
     *
     * Resolves the decision table by id, evaluates its rules in order against
     * the input map and combines matching outputs per the table's hit policy
     * (FIRST → the first matching rule's output; COLLECT → a list of all
     * matching outputs under the `matches` key). Throws on an unknown table id
     * or a malformed table — never returns an empty array to mask an error.
     *
     * @param string               $decisionTableId The decision table UUID.
     * @param array<string, mixed> $inputData       The decision input map.
     *
     * @return array<string, mixed> The decision output values.
     *
     * @throws InvalidArgumentException If the table id is empty or unknown.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The branches are the input
     *  validation guards plus the two hit-policy outcomes (FIRST / COLLECT);
     *  each is a single trivial step in the evaluation contract.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.2
     */
    public function evaluateDecision(string $decisionTableId, array $inputData): array
    {
        if (trim($decisionTableId) === '') {
            throw new InvalidArgumentException('A decision table id is required.');
        }

        $table  = $this->fetchTable(id: $decisionTableId);
        $rules  = ($table['rules'] ?? []);
        $policy = strtoupper((string) ($table['hitPolicy'] ?? 'FIRST'));

        if (is_array($rules) === false) {
            throw new InvalidArgumentException('Decision table has no valid rules.');
        }

        $matches = [];
        foreach ($rules as $rule) {
            if (is_array($rule) === false) {
                continue;
            }

            $when = ($rule['when'] ?? []);
            $then = ($rule['then'] ?? []);
            if (is_array($when) === false || is_array($then) === false) {
                continue;
            }

            if ($this->ruleMatches(when: $when, input: $inputData) === true) {
                if ($policy === 'FIRST') {
                    return $then;
                }

                $matches[] = $then;
            }
        }//end foreach

        if ($policy === 'COLLECT') {
            return ['matches' => $matches];
        }

        return [];
    }//end evaluateDecision()

    /**
     * List the available decision tables (id, name, description).
     *
     * @return array<int, array<string, mixed>> The decision table summaries.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.2
     */
    public function listTables(): array
    {
        [$register, $schema] = $this->scope();

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
            $this->logger->warning('Pipelinq: failed to list decision tables', ['exception' => $e->getMessage()]);
            return [];
        }

        $tables = [];
        foreach (($results ?? []) as $result) {
            $table    = $this->toArray(object: $result);
            $tables[] = [
                'id'          => (string) ($table['id'] ?? $table['uuid'] ?? ''),
                'name'        => (string) ($table['name'] ?? ''),
                'description' => (string) ($table['description'] ?? ''),
            ];
        }

        return $tables;
    }//end listTables()

    /**
     * Write decision output properties back onto the triggering entity.
     *
     * The output map is merged onto the entity's current data and persisted via
     * ObjectService::saveObject, so the change is recorded in the entity's audit
     * trail by OpenRegister (REQ-DMN-003).
     *
     * @param string               $entityId       The target entity UUID.
     * @param string               $schema         The target schema id/slug.
     * @param array<string, mixed> $decisionOutput The decision output to apply.
     *
     * @return void
     *
     * @throws InvalidArgumentException If the entity id or schema is empty.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-2.2
     */
    public function applyDecisionToEntity(string $entityId, string $schema, array $decisionOutput): void
    {
        if (trim($entityId) === '' || trim($schema) === '') {
            throw new InvalidArgumentException('An entity id and schema are required to apply a decision.');
        }

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($register === '') {
            throw new RuntimeException('Pipelinq register is not configured.');
        }

        $object = $this->getObjectService()->find(id: $entityId, register: $register, schema: $schema);
        if ($object === null) {
            throw new InvalidArgumentException('Target entity not found.');
        }

        $entity = $this->toArray(object: $object);
        unset($entity['@self']);

        foreach ($decisionOutput as $key => $value) {
            $entity[$key] = $value;
        }

        $this->getObjectService()->saveObject(
            object: $entity,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $entityId
        );
    }//end applyDecisionToEntity()

    /**
     * Determine whether a rule's `when` conditions match the input.
     *
     * AND logic across all conditions. A scalar condition is a case-insensitive
     * equality; an array condition is an operator map (gte|lte|gt|lt|eq|neq).
     * An empty `when` is the catch-all default rule (always matches).
     *
     * @param array<string, mixed> $when  The rule conditions.
     * @param array<string, mixed> $input The decision input.
     *
     * @return bool Whether the rule matches.
     */
    private function ruleMatches(array $when, array $input): bool
    {
        foreach ($when as $field => $expected) {
            $actual = ($input[$field] ?? null);

            if (is_array($expected) === true) {
                if ($this->matchesOperatorMap(operators: $expected, actual: $actual) === false) {
                    return false;
                }

                continue;
            }

            if (strcasecmp((string) $expected, (string) $actual) !== 0) {
                return false;
            }
        }//end foreach

        return true;
    }//end ruleMatches()

    /**
     * Match an actual value against a numeric/equality operator map.
     *
     * @param array<string, mixed> $operators The operator map.
     * @param mixed                $actual    The actual value.
     *
     * @return bool Whether all operators hold.
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
                'eq'    => strcasecmp((string) $bound, (string) $actual) === 0,
                'neq'   => strcasecmp((string) $bound, (string) $actual) !== 0,
                default => false,
            };

            if ($matched === false) {
                return false;
            }
        }//end foreach

        return true;
    }//end matchesOperatorMap()

    /**
     * Fetch a decision table object by id, scoped to this app.
     *
     * @param string $id The decision table UUID.
     *
     * @return array<string, mixed> The decision table data.
     *
     * @throws InvalidArgumentException If the table is not found in this app's schema.
     */
    private function fetchTable(string $id): array
    {
        [$register, $schema] = $this->scope();

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new InvalidArgumentException('Decision table not found.');
        }

        return $this->toArray(object: $object);
    }//end fetchTable()

    /**
     * Resolve the register + decisionTable schema into their stored IDs.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws RuntimeException If the register or schema is not configured.
     */
    private function scope(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'decisionTable_schema', '');

        if ($register === '' || $schema === '') {
            throw new RuntimeException('Decision table register or schema is not configured.');
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
