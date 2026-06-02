<?php

/**
 * Pipelinq AutomationCrudService.
 *
 * CRUD + history persistence surface for CRM automations, kept separate from
 * the AutomationService execution engine. Wraps the real OpenRegister
 * ObjectService API (find / findAll / saveObject / deleteObject) scoped to this
 * app's own register/schema so a caller can never read or mutate an automation
 * belonging to another app (IDOR-safe). Validates input and never trusts a
 * client-supplied id at write time.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists and reads automation + automationLog objects via OpenRegister.
 *
 * Pagination is applied in-app over the scoped result set. Writes whitelist the
 * editable properties (a client cannot inject runCount / lastRun or escape the
 * app scope). History reads the append-only automationLog filtered by the
 * automation id.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the OR container, app
 *  config and logger — the minimum a scoped CRUD service needs.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
 */
class AutomationCrudService
{
    /**
     * Page size for the automation list.
     *
     * @var int
     */
    private const PAGE_SIZE = 20;

    /**
     * Editable automation properties a client may set.
     *
     * @var array<int, string>
     */
    private const EDITABLE = [
        'name',
        'trigger',
        'triggerConditions',
        'actions',
        'isActive',
        'webhookUrl',
        'n8nWorkflowId',
    ];

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
     * Return a paginated list of automations, optionally filtered by trigger.
     *
     * @param int    $page    The 1-based page number.
     * @param string $trigger Optional trigger filter.
     *
     * @return array<string, mixed> The paginated list (results, total, page, pages).
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    public function list(int $page=1, string $trigger=''): array
    {
        $all = $this->fetchAll(schemaKey: 'automation_schema', trigger: $trigger);

        $total = count($all);
        $pages = (int) ceil(($total / self::PAGE_SIZE));
        $page  = max(1, $page);
        $slice = array_slice($all, (($page - 1) * self::PAGE_SIZE), self::PAGE_SIZE);

        return [
            'results' => array_values($slice),
            'total'   => $total,
            'page'    => $page,
            'pages'   => max(1, $pages),
        ];
    }//end list()

    /**
     * Fetch a single automation by id.
     *
     * @param string $id The automation UUID.
     *
     * @return array<string, mixed> The automation.
     *
     * @throws OCSNotFoundException If the automation is not found in this app.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    public function get(string $id): array
    {
        [$register, $schema] = $this->scope(schemaKey: 'automation_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Automation not found.');
        }

        return $this->toArray(object: $object);
    }//end get()

    /**
     * Create a new automation from client input.
     *
     * @param array<string, mixed> $data The raw request parameters.
     *
     * @return array<string, mixed> The created automation.
     *
     * @throws InvalidArgumentException If required properties are missing/invalid.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    public function create(array $data): array
    {
        $clean = $this->sanitize(data: $data);
        $this->assertValid(automation: $clean);

        // Server-controlled fields are never accepted from the client.
        $clean['runCount'] = 0;
        $clean['isActive'] = (bool) ($clean['isActive'] ?? false);

        [$register, $schema] = $this->scope(schemaKey: 'automation_schema');
        $saved = $this->getObjectService()->saveObject(
            object: $clean,
            extend: [],
            register: $register,
            schema: $schema
        );

        return $this->toArray(object: $saved);
    }//end create()

    /**
     * Update an existing automation, merging whitelisted properties.
     *
     * @param string               $id   The automation UUID.
     * @param array<string, mixed> $data The raw request parameters.
     *
     * @return array<string, mixed> The updated automation.
     *
     * @throws InvalidArgumentException If the resulting automation is invalid.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    public function update(string $id, array $data): array
    {
        $existing = $this->get(id: $id);
        $clean    = $this->sanitize(data: $data);

        foreach ($clean as $key => $value) {
            $existing[$key] = $value;
        }

        $this->assertValid(automation: $existing);
        unset($existing['@self']);

        [$register, $schema] = $this->scope(schemaKey: 'automation_schema');
        $saved = $this->getObjectService()->saveObject(
            object: $existing,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );

        return $this->toArray(object: $saved);
    }//end update()

    /**
     * Set an automation's active flag.
     *
     * @param string $id     The automation UUID.
     * @param bool   $active The desired active state.
     *
     * @return array<string, mixed> The updated automation.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    public function setActive(string $id, bool $active): array
    {
        $existing = $this->get(id: $id);
        $existing['isActive'] = $active;
        unset($existing['@self']);

        [$register, $schema] = $this->scope(schemaKey: 'automation_schema');
        $saved = $this->getObjectService()->saveObject(
            object: $existing,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );

        return $this->toArray(object: $saved);
    }//end setActive()

    /**
     * Delete an automation by id.
     *
     * @param string $id The automation UUID.
     *
     * @return string The deletion status ('deleted').
     *
     * @throws OCSNotFoundException If the automation does not exist.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    public function delete(string $id): string
    {
        // Confirm existence + scope before deleting (404 on a foreign/missing id).
        $this->get(id: $id);

        [$register, $schema] = $this->scope(schemaKey: 'automation_schema');
        $this->getObjectService()->deleteObject(register: $register, schema: $schema, uuid: $id);

        return 'deleted';
    }//end delete()

    /**
     * Return the automationLog history for an automation, newest first.
     *
     * @param string $id The automation UUID.
     *
     * @return array<int, array<string, mixed>> The execution log entries.
     *
     * @throws OCSNotFoundException If the automation does not exist.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    public function history(string $id): array
    {
        // Confirm the automation exists in this app before exposing its logs.
        $this->get(id: $id);

        [$register, $schema] = $this->scope(schemaKey: 'automationLog_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register'   => $register,
                        'schema'     => $schema,
                        'automation' => $id,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch automation history', ['exception' => $e->getMessage()]);
            return [];
        }

        $logs = [];
        foreach (($results ?? []) as $result) {
            $logs[] = $this->toArray(object: $result);
        }

        usort(
            $logs,
            static fn (array $a, array $b): int => strcmp((string) ($b['triggeredAt'] ?? ''), (string) ($a['triggeredAt'] ?? ''))
        );

        return $logs;
    }//end history()

    /**
     * Whitelist the editable automation properties from raw client input.
     *
     * @param array<string, mixed> $data The raw parameters.
     *
     * @return array<string, mixed> The whitelisted properties.
     */
    private function sanitize(array $data): array
    {
        $clean = [];
        foreach (self::EDITABLE as $key) {
            if (array_key_exists($key, $data) === true) {
                $clean[$key] = $data[$key];
            }
        }

        return $clean;
    }//end sanitize()

    /**
     * Validate that an automation has the required, well-formed properties.
     *
     * @param array<string, mixed> $automation The automation data.
     *
     * @return void
     *
     * @throws InvalidArgumentException If a required property is missing/invalid.
     */
    private function assertValid(array $automation): void
    {
        $name = trim((string) ($automation['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('An automation name is required.');
        }

        $trigger = trim((string) ($automation['trigger'] ?? ''));
        if ($trigger === '') {
            throw new InvalidArgumentException('An automation trigger is required.');
        }

        if (isset($automation['actions']) === true && is_array($automation['actions']) === false) {
            throw new InvalidArgumentException('Automation actions must be a list.');
        }

        if (isset($automation['triggerConditions']) === true && is_array($automation['triggerConditions']) === false) {
            throw new InvalidArgumentException('Automation trigger conditions must be an object.');
        }
    }//end assertValid()

    /**
     * Fetch all automations for a schema, optionally filtered by trigger.
     *
     * @param string $schemaKey The app-config schema key.
     * @param string $trigger   Optional trigger filter.
     *
     * @return array<int, array<string, mixed>> The objects.
     */
    private function fetchAll(string $schemaKey, string $trigger=''): array
    {
        [$register, $schema] = $this->scope(schemaKey: $schemaKey);

        $filters = [
            'register' => $register,
            'schema'   => $schema,
        ];
        if ($trigger !== '') {
            $filters['trigger'] = $trigger;
        }

        try {
            $results = $this->getObjectService()->findAll(config: ['filters' => $filters]);
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to list automations', ['exception' => $e->getMessage()]);
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
