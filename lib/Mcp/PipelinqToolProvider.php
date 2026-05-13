<?php
/**
 * Pipelinq MCP Tool Provider
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider for Pipelinq
 * (client and request management — a thin OpenRegister client). Exposes a small
 * read-only MVP tool set so the AI Chat Companion (hydra ADR-034 / ADR-035) can
 * surface Pipelinq capabilities — listing requests and reading a single request
 * with its activity timeline — to an LLM.
 *
 * @category Mcp
 * @package  OCA\Pipelinq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Mcp;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Pipelinq MCP Tool Provider.
 *
 * Implements IMcpToolProvider (from openregister PR #1466,
 * change ai-chat-companion-orchestrator) exposing 2 read-only MVP tools to the
 * AI Chat Companion. The full tool surface tracked in ConductionNL/pipelinq#342
 * lands incrementally; this is the MVP skeleton.
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Argument validation runs first (cheap before expensive).
 * - Per-object authorisation runs BEFORE business logic by delegating reads to
 *   OpenRegister's ObjectService with RBAC enabled (the default). OR's
 *   PermissionHandler enforces the per-object 'read' verdict against the current
 *   user session and raises on denial — there is no unconditional `return true`,
 *   and the RBAC verdict is not swallowed by a blanket catch (a denial is
 *   surfaced as a `forbidden` error envelope).
 *
 * @spec https://github.com/ConductionNL/pipelinq/issues/342
 */
class PipelinqToolProvider implements IMcpToolProvider
{

    /**
     * Maximum number of objects returned by any list tool (MVP cap).
     *
     * @var int
     */
    private const LIST_CAP = 20;

    /**
     * The OpenRegister ObjectService class name (resolved lazily via the container).
     *
     * @var string
     */
    private const OR_OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

    /**
     * Tool catalogue (MVP — exactly 2 read-only tools).
     *
     * Hard-coded as a constant so unit tests can assert it as a fixture.
     *
     * @var array<int, array<string, mixed>>
     */
    private const TOOL_DESCRIPTORS = [
        [
            'id'          => 'pipelinq.listRequests',
            'name'        => 'List requests',
            'description' => 'List intake requests, newest first. Optionally filter by status or by client.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit'    => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 20,
                    ],
                    'status'   => [
                        'type'        => 'string',
                        'description' => 'Optional request status to filter on (e.g. "new", "in-progress", "closed").',
                    ],
                    'clientId' => [
                        'type'        => 'string',
                        'description' => 'Optional client UUID — only return requests linked to this client.',
                    ],
                ],
                'required'   => [],
            ],
        ],
        [
            'id'          => 'pipelinq.getRequest',
            'name'        => 'Get request',
            'description' => 'Fetch a single intake request by UUID, including its activity timeline.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'string',
                        'description' => 'The request UUID (also accepted via the alias "uuid").',
                    ],
                ],
                'required'   => ['id'],
            ],
        ],
    ];

    /**
     * Constructor for PipelinqToolProvider.
     *
     * Injects the same collaborators the request-facing controllers/services use:
     * the app config (for the configured OpenRegister register + request schema),
     * the DI container (for OR's ObjectService), the activity timeline service,
     * and the PSR-3 logger.
     *
     * @param IAppConfig              $appConfig       The app config service
     * @param ContainerInterface      $container       The DI container (for OR ObjectService)
     * @param ActivityTimelineService $timelineService The activity timeline aggregator
     * @param LoggerInterface         $logger          The PSR-3 logger
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly ActivityTimelineService $timelineService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns the app ID that namespaces every tool id.
     *
     * @return string "pipelinq"
     *
     * @spec https://github.com/ConductionNL/pipelinq/issues/342
     */
    public function getAppId(): string
    {
        return 'pipelinq';

    }//end getAppId()

    /**
     * Returns the full tool catalogue (2 tools, always).
     *
     * The full catalogue is always returned regardless of caller permissions.
     * Per-object authorisation runs in invokeTool().
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec https://github.com/ConductionNL/pipelinq/issues/342
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * Dispatch a tool call by id.
     *
     * Argument validation runs BEFORE authorisation (cheap before expensive),
     * which runs BEFORE business logic (per-object RBAC via OR's ObjectService).
     * Unknown tool ids return a structured error; no exception is thrown.
     *
     * @param string               $toolId    The tool id (e.g. "pipelinq.getRequest")
     * @param array<string, mixed> $arguments Tool arguments from the LLM call
     *
     * @return array<string, mixed>
     *
     * @spec https://github.com/ConductionNL/pipelinq/issues/342
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        if ($toolId === 'pipelinq.listRequests') {
            return $this->handleListRequests(args: $arguments);
        }

        if ($toolId === 'pipelinq.getRequest') {
            return $this->handleGetRequest(args: $arguments);
        }

        $known = implode(separator: ', ', array: array_column(array: self::TOOL_DESCRIPTORS, column_key: 'id'));

        return [
            'error' => [
                'code'    => 'unknown_tool',
                'message' => "Unknown tool id '{$toolId}'. Available tools: {$known}.",
            ],
        ];

    }//end invokeTool()

    // =========================================================================
    // Private tool handlers
    // =========================================================================

    /**
     * Handle pipelinq.listRequests.
     *
     * Lists intake requests (newest first), optionally filtered by status or
     * client. The OpenRegister ObjectService query runs with RBAC enabled, so
     * only requests the caller is allowed to read are returned.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleListRequests(array $args): array
    {
        $limit = $this->resolveLimit(args: $args);
        if (is_array(value: $limit) === true) {
            return $limit;
        }

        $config = $this->resolveRequestContext();
        if (isset($config['error']) === true) {
            return $config;
        }

        $filters = [
            'register' => $config['register'],
            'schema'   => $config['schema'],
        ];

        $status = $this->optionalStringArg(args: $args, key: 'status');
        if ($status !== null) {
            $filters['status'] = $status;
        }

        $clientId = $this->optionalStringArg(args: $args, key: 'clientId');
        if ($clientId !== null) {
            $filters['client'] = $clientId;
        }

        try {
            $objectService = $this->getObjectService();

            // RBAC + multitenancy left at their defaults (true): OR enforces the
            // per-object 'read' verdict here, before any data leaves this method.
            $rawRequests = $objectService->findAll(
                [
                    'filters' => $filters,
                    'limit'   => $limit,
                    'order'   => ['dateCreated' => 'DESC'],
                ]
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'list requests', exception: $e);
        }//end try

        $items = [];
        foreach ($rawRequests as $raw) {
            $items[] = $this->toArray(item: $raw);
        }

        return [
            'requests' => array_slice(array: $items, offset: 0, length: self::LIST_CAP),
            'count'    => count($items),
        ];

    }//end handleListRequests()

    /**
     * Handle pipelinq.getRequest.
     *
     * Fetches a single request by UUID (RBAC enforced) and inlines its activity
     * timeline. The 'uuid' argument is accepted as an alias for 'id'.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleGetRequest(array $args): array
    {
        $id = $this->optionalStringArg(args: $args, key: 'id');
        if ($id === null) {
            $id = $this->optionalStringArg(args: $args, key: 'uuid');
        }

        if ($id === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument id (or uuid) is missing.');
        }

        $config = $this->resolveRequestContext();
        if (isset($config['error']) === true) {
            return $config;
        }

        try {
            $objectService = $this->getObjectService();

            // RBAC left at its default (true): OR's PermissionHandler runs the
            // per-object 'read' check here and raises if the caller is denied.
            $request = $objectService->find(
                $id,
                [],
                false,
                $config['register'],
                $config['schema']
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'get request', exception: $e);
        }//end try

        if ($request === null) {
            return $this->errorEnvelope(code: 'not_found', message: 'Request not found.');
        }

        return [
            'request'  => $this->toArray(item: $request),
            'timeline' => $this->fetchTimeline(requestId: $id),
        ];

    }//end handleGetRequest()

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve and validate the optional `limit` argument for list tools.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return int|array<string, mixed> The validated, capped limit, or an error envelope.
     */
    private function resolveLimit(array $args): int | array
    {
        $limit = self::LIST_CAP;
        if (isset($args['limit']) === true) {
            $limit = (int) $args['limit'];
        }

        if ($limit < 1 || $limit > 50) {
            return $this->errorEnvelope(
                code: 'invalid_arguments',
                message: "Invalid limit {$limit}. Must be between 1 and 50."
            );
        }

        // Hard MVP cap regardless of the requested limit.
        return min($limit, self::LIST_CAP);

    }//end resolveLimit()

    /**
     * Read an optional string argument, treating empty strings as absent.
     *
     * @param array<string, mixed> $args Tool arguments
     * @param string               $key  The argument key
     *
     * @return string|null The trimmed value, or null when missing/empty.
     */
    private function optionalStringArg(array $args, string $key): ?string
    {
        if (isset($args[$key]) === false) {
            return null;
        }

        $value = (string) $args[$key];
        if ($value === '') {
            return null;
        }

        return $value;

    }//end optionalStringArg()

    /**
     * Resolve the configured OpenRegister register + request schema.
     *
     * @return array<string, mixed> Either ['register' => ..., 'schema' => ...] or an error envelope.
     */
    private function resolveRequestContext(): array
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId   = $this->appConfig->getValueString(Application::APP_ID, 'request_schema', '');

        if ($registerId === '' || $schemaId === '') {
            return $this->errorEnvelope(
                code: 'not_configured',
                message: 'Pipelinq is not fully configured: the OpenRegister register or request schema is missing.'
            );
        }

        return [
            'register' => $registerId,
            'schema'   => $schemaId,
        ];

    }//end resolveRequestContext()

    /**
     * Build the activity timeline for a request (best-effort).
     *
     * A timeline failure must not sink the request read — it is logged and an
     * empty timeline is returned.
     *
     * @param string $requestId The request UUID
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTimeline(string $requestId): array
    {
        try {
            $result = $this->timelineService->getTimeline(
                entityType: 'request',
                entityId: $requestId,
                params: ['_limit' => self::LIST_CAP]
            );

            return array_slice(array: $result['items'], offset: 0, length: self::LIST_CAP);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq MCP: getRequest timeline aggregation failed',
                ['requestId' => $requestId, 'exception' => $e->getMessage()]
            );
            return [];
        }//end try

    }//end fetchTimeline()

    /**
     * Resolve the OpenRegister ObjectService via the DI container.
     *
     * @return object The OpenRegister ObjectService instance.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get(self::OR_OBJECT_SERVICE);
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }

    }//end getObjectService()

    /**
     * Map an exception raised by OpenRegister into a structured MCP error envelope.
     *
     * OpenRegister's PermissionHandler raises a plain exception whose message
     * mentions "permission" when the caller is not authorised; we surface that as
     * `forbidden`. Everything else is an `internal_error` (logged for the operator).
     *
     * @param string     $operation Short label of the failed operation (for the log).
     * @param \Exception $exception The caught exception.
     *
     * @return array<string, mixed>
     */
    private function mapServiceException(string $operation, \Exception $exception): array
    {
        $message = $exception->getMessage();

        if (stripos($message, 'permission') !== false || stripos($message, 'not authoriz') !== false) {
            return $this->errorEnvelope(code: 'forbidden', message: 'You are not allowed to access this resource.');
        }

        $this->logger->error(
            "Pipelinq MCP: failed to {$operation}",
            ['exception' => $message]
        );

        return $this->errorEnvelope(
            code: 'internal_error',
            message: "Failed to {$operation}. See server log for details."
        );

    }//end mapServiceException()

    /**
     * Build a structured MCP error envelope.
     *
     * @param string $code    Machine-readable error code.
     * @param string $message Human-readable message for the LLM.
     *
     * @return array<string, mixed>
     */
    private function errorEnvelope(string $code, string $message): array
    {
        return [
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ];

    }//end errorEnvelope()

    /**
     * Normalise an OpenRegister object to a plain PHP array.
     *
     * @param mixed $item Raw item from ObjectService
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $item): array
    {
        if (is_array(value: $item) === true) {
            return $item;
        }

        if (is_object(value: $item) === true && method_exists($item, 'getObject') === true) {
            return $item->getObject();
        }

        if (is_object(value: $item) === true && method_exists($item, 'jsonSerialize') === true) {
            return $item->jsonSerialize();
        }

        return (array) $item;

    }//end toArray()
}//end class
