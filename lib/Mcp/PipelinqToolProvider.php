<?php
/**
 * Pipelinq MCP Tool Provider
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider for Pipelinq
 * (client and request management — a thin OpenRegister client). Exposes the
 * agent-addressable CRM tool surface so the AI Chat Companion
 * (hydra ADR-034 / ADR-035) can drive Pipelinq — requests, clients (incl. a
 * 360 summary), leads, pipeline forecast, and logging a contactmoment — from
 * an LLM.
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
 *
 * @spec openspec/changes/crm-mcp-tool-surface/specs/crm-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Mcp;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCA\Pipelinq\Service\TicketService;
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
 * change ai-chat-companion-orchestrator) exposing the CRM tool surface to the
 * AI Chat Companion: the original request read tools plus client, lead,
 * pipeline-forecast, create-lead, and log-contactmoment tools
 * (ConductionNL/pipelinq#342, change crm-mcp-tool-surface).
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Argument validation runs first (cheap before expensive).
 * - Per-object authorisation runs BEFORE business logic by delegating reads to
 *   OpenRegister's ObjectService with RBAC enabled (the default). OR's
 *   PermissionHandler enforces the per-object 'read' verdict against the current
 *   user session and raises on denial — there is no unconditional `return true`,
 *   and the RBAC verdict is not swallowed by a blanket catch (a denial is
 *   surfaced as a `forbidden` error envelope).
 * - Write tools (createLead, logContactmoment) go through the same
 *   ObjectService/TicketService write path the UI uses, so OR's `create`
 *   authorization is enforced; a denial maps to a `forbidden` envelope.
 *
 * @spec https://github.com/ConductionNL/pipelinq/issues/342
 * @spec openspec/changes/crm-mcp-tool-surface/specs/crm-mcp-tool-surface/spec.md
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
     * Fetch window a search tool reads before filtering in PHP by free-text
     * query. Larger than LIST_CAP so a match further down a recency-sorted
     * page is still found; results are still capped to LIST_CAP afterwards.
     *
     * @var int
     */
    private const SEARCH_FETCH_CAP = 200;

    /**
     * Fetch window for a read-side aggregation (client 360 summary, pipeline
     * forecast) — mirrors ActivityTimelineService::PER_SCHEMA_CEILING. The
     * aggregation reduces over RBAC-visible rows within this window.
     *
     * @var int
     */
    private const AGGREGATION_FETCH_CAP = 500;

    /**
     * Ticket statuses considered "open" (not a final lifecycle state) per the
     * unified ticket schema's x-openregister-lifecycle (register.d
     * 99-unify-ticket-supertype.json): everything except completed, converted,
     * resolved, rejected, closed.
     *
     * @var array<int, string>
     */
    private const OPEN_TICKET_STATUSES = ['new', 'in_progress'];

    /**
     * Tool catalogue.
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
        [
            'id'          => 'pipelinq.listClients',
            'name'        => 'List clients',
            'description' => 'List clients, newest first. Optionally filter by type ("person" or "organization").',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit' => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 20,
                    ],
                    'type'  => [
                        'type'        => 'string',
                        'enum'        => ['person', 'organization'],
                        'description' => 'Optional client type to filter on.',
                    ],
                ],
                'required'   => [],
            ],
        ],
        [
            'id'          => 'pipelinq.searchClients',
            'name'        => 'Search clients',
            'description' => 'Search clients by free text matched against name and email, RBAC-scoped, capped to the list cap.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'Free-text search term (matched case-insensitively against name/email).',
                    ],
                    'limit' => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 20,
                    ],
                ],
                'required'   => ['query'],
            ],
        ],
        [
            'id'          => 'pipelinq.getClient',
            'name'        => 'Get client',
            'description' => 'Fetch a client by UUID plus a 360 summary: open-ticket count, open-lead count/value, recent contactmomenten.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'string',
                        'description' => 'The client UUID.',
                    ],
                ],
                'required'   => ['id'],
            ],
        ],
        [
            'id'          => 'pipelinq.listLeads',
            'name'        => 'List leads',
            'description' => 'List leads, newest first. Optionally filter by status, pipeline stage, or client.',
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
                        'enum'        => ['open', 'won', 'lost'],
                        'description' => 'Optional lead lifecycle status to filter on.',
                    ],
                    'stage'    => [
                        'type'        => 'string',
                        'description' => 'Optional pipeline stage name to filter on.',
                    ],
                    'clientId' => [
                        'type'        => 'string',
                        'description' => 'Optional client UUID — only return leads linked to this client.',
                    ],
                ],
                'required'   => [],
            ],
        ],
        [
            'id'          => 'pipelinq.searchLeads',
            'name'        => 'Search leads',
            'description' => 'Search leads by free text against title, contact name/email, organisation. RBAC-scoped.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'Free-text search term.',
                    ],
                    'limit' => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 20,
                    ],
                ],
                'required'   => ['query'],
            ],
        ],
        [
            'id'          => 'pipelinq.getLead',
            'name'        => 'Get lead',
            'description' => 'Fetch a lead by UUID incl. qualificationScore, weightedValue, winProbability, plus its timeline.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'string',
                        'description' => 'The lead UUID.',
                    ],
                ],
                'required'   => ['id'],
            ],
        ],
        [
            'id'          => 'pipelinq.pipelineForecast',
            'name'        => 'Pipeline forecast',
            'description' => 'Per-stage totals over open leads: lead count, summed value, weighted value, plus a grand total.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [],
                'required'   => [],
            ],
        ],
        [
            'id'          => 'pipelinq.createLead',
            'name'        => 'Create lead',
            'description' => 'Create a new sales lead. Only "title" is required; client, value, source and assignee are optional.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'title'    => [
                        'type'        => 'string',
                        'description' => 'A short description of the opportunity.',
                    ],
                    'client'   => [
                        'type'        => 'string',
                        'description' => 'Optional client UUID this lead is linked to.',
                    ],
                    'value'    => [
                        'type'        => 'number',
                        'description' => 'Optional estimated deal value.',
                    ],
                    'source'   => [
                        'type'        => 'string',
                        'description' => 'Optional origin of the lead (e.g. website, referral, cold-call).',
                    ],
                    'assignee' => [
                        'type'        => 'string',
                        'description' => 'Optional Nextcloud user id of the lead owner.',
                    ],
                ],
                'required'   => ['title'],
            ],
        ],
        [
            'id'          => 'pipelinq.logContactmoment',
            'name'        => 'Log contactmoment',
            'description' => 'Log a client interaction as a contactmoment (client, channel and title are required; outcome and notes are optional).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'client'  => [
                        'type'        => 'string',
                        'description' => 'The client UUID this interaction is with.',
                    ],
                    'channel' => [
                        'type'        => 'string',
                        'description' => 'The interaction channel (e.g. telefoon, email, balie, chat).',
                    ],
                    'title'   => [
                        'type'        => 'string',
                        'description' => 'A short summary of the interaction.',
                    ],
                    'outcome' => [
                        'type'        => 'string',
                        'description' => 'Optional outcome (e.g. afgehandeld, doorverbonden, terugbelverzoek).',
                    ],
                    'notes'   => [
                        'type'        => 'string',
                        'description' => 'Optional free-text notes about the interaction.',
                    ],
                ],
                'required'   => ['client', 'channel', 'title'],
            ],
        ],
    ];

    /**
     * Constructor for PipelinqToolProvider.
     *
     * Injects the same collaborators the request-facing controllers/services use:
     * the ticket resolver (for the configured OpenRegister register + unified
     * ticket schema), the DI container (for OR's ObjectService), the activity
     * timeline service, the app config (to resolve `client_schema`/`lead_schema`
     * exactly like TicketService resolves `ticket_schema`), and the PSR-3 logger.
     *
     * @param TicketService           $ticketService   Resolver for the unified ticket schema
     * @param ContainerInterface      $container       The DI container (for OR ObjectService)
     * @param ActivityTimelineService $timelineService The activity timeline aggregator
     * @param IAppConfig              $appConfig       The app config (client/lead schema resolution)
     * @param LoggerInterface         $logger          The PSR-3 logger
     */
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly ContainerInterface $container,
        private readonly ActivityTimelineService $timelineService,
        private readonly IAppConfig $appConfig,
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
     * Returns the full tool catalogue, always.
     *
     * The full catalogue is always returned regardless of caller permissions.
     * Per-object authorisation runs in invokeTool().
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec https://github.com/ConductionNL/pipelinq/issues/342
     * @spec openspec/changes/crm-mcp-tool-surface/specs/crm-mcp-tool-surface/spec.md#requirement-the-tool-catalogue-is-self-describing-and-stable
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
     * @spec openspec/changes/crm-mcp-tool-surface/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-a-crm-read-tool-surface
     * @spec openspec/changes/crm-mcp-tool-surface/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        if ($toolId === 'pipelinq.listRequests') {
            return $this->handleListRequests(args: $arguments);
        }

        if ($toolId === 'pipelinq.getRequest') {
            return $this->handleGetRequest(args: $arguments);
        }

        if ($toolId === 'pipelinq.listClients') {
            return $this->handleListClients(args: $arguments);
        }

        if ($toolId === 'pipelinq.searchClients') {
            return $this->handleSearchClients(args: $arguments);
        }

        if ($toolId === 'pipelinq.getClient') {
            return $this->handleGetClient(args: $arguments);
        }

        if ($toolId === 'pipelinq.listLeads') {
            return $this->handleListLeads(args: $arguments);
        }

        if ($toolId === 'pipelinq.searchLeads') {
            return $this->handleSearchLeads(args: $arguments);
        }

        if ($toolId === 'pipelinq.getLead') {
            return $this->handleGetLead(args: $arguments);
        }

        if ($toolId === 'pipelinq.pipelineForecast') {
            return $this->handlePipelineForecast(args: $arguments);
        }

        if ($toolId === 'pipelinq.createLead') {
            return $this->handleCreateLead(args: $arguments);
        }

        if ($toolId === 'pipelinq.logContactmoment') {
            return $this->handleLogContactmoment(args: $arguments);
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

        // Narrow the unified ticket schema to the request subtype.
        $filters = [
            'register'   => $config['register'],
            'schema'     => $config['schema'],
            'ticketType' => TicketService::TYPE_REQUEST,
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
                id: $id,
                register: $config['register'],
                schema: $config['schema']
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'get request', exception: $e);
        }//end try

        if ($request === null) {
            return $this->errorEnvelope(code: 'not_found', message: 'Request not found.');
        }

        // The ticket schema also holds complaints and contactmomenten; this tool
        // only exposes the request subtype, so a non-request ticket is a miss.
        $data = $this->toArray(item: $request);
        if (($data['ticketType'] ?? '') !== TicketService::TYPE_REQUEST) {
            return $this->errorEnvelope(code: 'not_found', message: 'Request not found.');
        }

        return [
            'request'  => $data,
            'timeline' => $this->fetchTimeline(entityType: 'request', entityId: $id),
        ];

    }//end handleGetRequest()

    /**
     * Handle pipelinq.listClients.
     *
     * Lists clients (newest first), optionally filtered by type. The
     * OpenRegister ObjectService query runs with RBAC enabled, so only
     * clients the caller is allowed to read are returned.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleListClients(array $args): array
    {
        $limit = $this->resolveLimit(args: $args);
        if (is_array(value: $limit) === true) {
            return $limit;
        }

        $config = $this->resolveClientContext();
        if (isset($config['error']) === true) {
            return $config;
        }

        $filters = [
            'register' => $config['register'],
            'schema'   => $config['schema'],
        ];

        $type = $this->optionalStringArg(args: $args, key: 'type');
        if ($type !== null) {
            $filters['type'] = $type;
        }

        try {
            $objectService = $this->getObjectService();

            $rawClients = $objectService->findAll(
                [
                    'filters' => $filters,
                    'limit'   => $limit,
                    'order'   => ['dateCreated' => 'DESC'],
                ]
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'list clients', exception: $e);
        }//end try

        $items = [];
        foreach ($rawClients as $raw) {
            $items[] = $this->toArray(item: $raw);
        }

        return [
            'clients' => array_slice(array: $items, offset: 0, length: self::LIST_CAP),
            'count'   => count($items),
        ];

    }//end handleListClients()

    /**
     * Handle pipelinq.searchClients.
     *
     * Reads an RBAC-scoped window of clients and filters in PHP by a
     * case-insensitive substring match against name/email (ADR-031 exception
     * (2): a per-request, caller-shaped read-side reduction).
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleSearchClients(array $args): array
    {
        $query = $this->optionalStringArg(args: $args, key: 'query');
        if ($query === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument query is missing.');
        }

        $limit = $this->resolveLimit(args: $args);
        if (is_array(value: $limit) === true) {
            return $limit;
        }

        $config = $this->resolveClientContext();
        if (isset($config['error']) === true) {
            return $config;
        }

        try {
            $objectService = $this->getObjectService();

            $rawClients = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $config['register'],
                        'schema'   => $config['schema'],
                    ],
                    'limit'   => self::SEARCH_FETCH_CAP,
                    'order'   => ['dateCreated' => 'DESC'],
                ]
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'search clients', exception: $e);
        }//end try

        $matches = [];
        foreach ($rawClients as $raw) {
            $data = $this->toArray(item: $raw);
            if ($this->matchesQuery(haystackFields: [($data['name'] ?? ''), ($data['email'] ?? '')], query: $query) === true) {
                $matches[] = $data;
            }
        }

        return [
            'clients' => array_slice(array: $matches, offset: 0, length: $limit),
            'count'   => count($matches),
        ];

    }//end handleSearchClients()

    /**
     * Handle pipelinq.getClient.
     *
     * Fetches a single client by UUID (RBAC enforced) plus a live 360
     * summary composed from RBAC-visible reads (open-ticket count, open-lead
     * count + value, recent contactmomenten).
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleGetClient(array $args): array
    {
        $id = $this->optionalStringArg(args: $args, key: 'id');
        if ($id === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument id is missing.');
        }

        $config = $this->resolveClientContext();
        if (isset($config['error']) === true) {
            return $config;
        }

        try {
            $objectService = $this->getObjectService();

            $client = $objectService->find(
                id: $id,
                register: $config['register'],
                schema: $config['schema']
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'get client', exception: $e);
        }//end try

        if ($client === null) {
            return $this->errorEnvelope(code: 'not_found', message: 'Client not found.');
        }

        return [
            'client'  => $this->toArray(item: $client),
            'summary' => $this->buildClientSummary(clientId: $id),
        ];

    }//end handleGetClient()

    /**
     * Handle pipelinq.listLeads.
     *
     * Lists leads (newest first), optionally filtered by status, stage, or
     * client. Each lead is decorated with a `winProbability` alias for its
     * `probability` field; `qualificationScore`/`weightedValue` are already
     * present on the read object via x-openregister-calculations.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleListLeads(array $args): array
    {
        $limit = $this->resolveLimit(args: $args);
        if (is_array(value: $limit) === true) {
            return $limit;
        }

        $config = $this->resolveLeadContext();
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

        $stage = $this->optionalStringArg(args: $args, key: 'stage');
        if ($stage !== null) {
            $filters['stage'] = $stage;
        }

        $clientId = $this->optionalStringArg(args: $args, key: 'clientId');
        if ($clientId !== null) {
            $filters['client'] = $clientId;
        }

        try {
            $objectService = $this->getObjectService();

            $rawLeads = $objectService->findAll(
                [
                    'filters' => $filters,
                    'limit'   => $limit,
                    'order'   => ['dateCreated' => 'DESC'],
                ]
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'list leads', exception: $e);
        }//end try

        $items = [];
        foreach ($rawLeads as $raw) {
            $items[] = $this->decorateLead(lead: $this->toArray(item: $raw));
        }

        return [
            'leads' => array_slice(array: $items, offset: 0, length: self::LIST_CAP),
            'count' => count($items),
        ];

    }//end handleListLeads()

    /**
     * Handle pipelinq.searchLeads.
     *
     * Reads an RBAC-scoped window of leads and filters in PHP by a
     * case-insensitive substring match against title/contactName/
     * contactEmail/organisation.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleSearchLeads(array $args): array
    {
        $query = $this->optionalStringArg(args: $args, key: 'query');
        if ($query === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument query is missing.');
        }

        $limit = $this->resolveLimit(args: $args);
        if (is_array(value: $limit) === true) {
            return $limit;
        }

        $config = $this->resolveLeadContext();
        if (isset($config['error']) === true) {
            return $config;
        }

        try {
            $objectService = $this->getObjectService();

            $rawLeads = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $config['register'],
                        'schema'   => $config['schema'],
                    ],
                    'limit'   => self::SEARCH_FETCH_CAP,
                    'order'   => ['dateCreated' => 'DESC'],
                ]
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'search leads', exception: $e);
        }//end try

        $matches = [];
        foreach ($rawLeads as $raw) {
            $data     = $this->toArray(item: $raw);
            $haystack = [
                ($data['title'] ?? ''),
                ($data['contactName'] ?? ''),
                ($data['contactEmail'] ?? ''),
                ($data['organisation'] ?? ''),
            ];
            if ($this->matchesQuery(haystackFields: $haystack, query: $query) === true) {
                $matches[] = $this->decorateLead(lead: $data);
            }
        }

        return [
            'leads' => array_slice(array: $matches, offset: 0, length: $limit),
            'count' => count($matches),
        ];

    }//end handleSearchLeads()

    /**
     * Handle pipelinq.getLead.
     *
     * Fetches a single lead by UUID (RBAC enforced), decorated with a
     * `winProbability` alias, and inlines its activity timeline.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleGetLead(array $args): array
    {
        $id = $this->optionalStringArg(args: $args, key: 'id');
        if ($id === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument id is missing.');
        }

        $config = $this->resolveLeadContext();
        if (isset($config['error']) === true) {
            return $config;
        }

        try {
            $objectService = $this->getObjectService();

            $lead = $objectService->find(
                id: $id,
                register: $config['register'],
                schema: $config['schema']
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'get lead', exception: $e);
        }//end try

        if ($lead === null) {
            return $this->errorEnvelope(code: 'not_found', message: 'Lead not found.');
        }

        return [
            'lead'     => $this->decorateLead(lead: $this->toArray(item: $lead)),
            'timeline' => $this->fetchTimeline(entityType: 'lead', entityId: $id),
        ];

    }//end handleGetLead()

    /**
     * Handle pipelinq.pipelineForecast.
     *
     * Reads RBAC-visible open leads, buckets by pipeline stage, and sums
     * `value` and the already-materialised `weightedValue` calculation
     * (never recomputed). Rows are ordered by the lowest `stageOrder` seen
     * in each bucket (ADR-031 exception (2): a per-request, caller-shaped
     * aggregation, not a stored x-openregister-aggregations value).
     *
     * @param array<string, mixed> $args Tool arguments (unused — no filters)
     *
     * @return array<string, mixed>
     */
    private function handlePipelineForecast(array $args): array
    {
        unset($args);

        $config = $this->resolveLeadContext();
        if (isset($config['error']) === true) {
            return $config;
        }

        try {
            $objectService = $this->getObjectService();

            $rawLeads = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $config['register'],
                        'schema'   => $config['schema'],
                        'status'   => 'open',
                    ],
                    'limit'   => self::AGGREGATION_FETCH_CAP,
                ]
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'compute pipeline forecast', exception: $e);
        }//end try

        return $this->buildForecastFromLeads(rawLeads: $rawLeads);

    }//end handlePipelineForecast()

    /**
     * Reduce a set of raw open-lead rows into per-stage forecast rows plus a
     * grand total.
     *
     * @param iterable<mixed> $rawLeads The raw open-lead rows
     *
     * @return array<string, mixed>
     */
    private function buildForecastFromLeads(iterable $rawLeads): array
    {
        $stages = [];
        foreach ($rawLeads as $raw) {
            $lead  = $this->toArray(item: $raw);
            $stage = (string) ($lead['stage'] ?? 'unspecified');
            $order = (int) ($lead['stageOrder'] ?? PHP_INT_MAX);

            if (isset($stages[$stage]) === false) {
                $stages[$stage] = [
                    'stage'         => $stage,
                    'order'         => $order,
                    'leadCount'     => 0,
                    'value'         => 0.0,
                    'weightedValue' => 0.0,
                ];
            }

            $stages[$stage]['leadCount']++;
            $stages[$stage]['value']         += (float) ($lead['value'] ?? 0);
            $stages[$stage]['weightedValue'] += (float) ($lead['weightedValue'] ?? 0);
            $stages[$stage]['order']          = min($stages[$stage]['order'], $order);
        }//end foreach

        usort(
            $stages,
            static function (array $left, array $right): int {
                return $left['order'] <=> $right['order'];
            }
        );

        $rows        = [];
        $totalCount  = 0;
        $totalValue  = 0.0;
        $totalWeight = 0.0;
        foreach ($stages as $row) {
            unset($row['order']);
            $rows[]       = $row;
            $totalCount  += $row['leadCount'];
            $totalValue  += $row['value'];
            $totalWeight += $row['weightedValue'];
        }

        return [
            'stages' => $rows,
            'total'  => [
                'leadCount'     => $totalCount,
                'value'         => $totalValue,
                'weightedValue' => $totalWeight,
            ],
        ];

    }//end buildForecastFromLeads()

    /**
     * Handle pipelinq.createLead.
     *
     * Validates the required `title`, then writes through
     * ObjectService->saveObject on the configured lead schema (RBAC `create`
     * enforced). The saved lead's server-computed `qualificationScore` is
     * included as returned by OpenRegister.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleCreateLead(array $args): array
    {
        $title = $this->optionalStringArg(args: $args, key: 'title');
        if ($title === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument title is missing.');
        }

        $config = $this->resolveLeadContext();
        if (isset($config['error']) === true) {
            return $config;
        }

        $payload = $this->buildCreateLeadPayload(args: $args, title: $title);

        try {
            $objectService = $this->getObjectService();

            $saved = $objectService->saveObject(
                object: $payload,
                register: $config['register'],
                schema: $config['schema'],
                uuid: null,
            );
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'create lead', exception: $e);
        }//end try

        return [
            'lead' => $this->decorateLead(lead: $this->toArray(item: $saved)),
        ];

    }//end handleCreateLead()

    /**
     * Build the create-lead write payload from validated tool arguments.
     *
     * @param array<string, mixed> $args  Tool arguments
     * @param string               $title The already-validated required title
     *
     * @return array<string, mixed>
     */
    private function buildCreateLeadPayload(array $args, string $title): array
    {
        $payload = ['title' => $title];

        $client = $this->optionalStringArg(args: $args, key: 'client');
        if ($client !== null) {
            $payload['client'] = $client;
        }

        if (isset($args['value']) === true) {
            $payload['value'] = (float) $args['value'];
        }

        $source = $this->optionalStringArg(args: $args, key: 'source');
        if ($source !== null) {
            $payload['source'] = $source;
        }

        $assignee = $this->optionalStringArg(args: $args, key: 'assignee');
        if ($assignee !== null) {
            $payload['assignee'] = $assignee;
        }

        return $payload;

    }//end buildCreateLeadPayload()

    /**
     * Handle pipelinq.logContactmoment.
     *
     * Validates the required `client`/`channel`/`title`, then writes through
     * TicketService::save(TYPE_CONTACTMOMENT, ...) so the `ticketType`
     * discriminator is forced and date-time fields are normalised.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleLogContactmoment(array $args): array
    {
        $client = $this->optionalStringArg(args: $args, key: 'client');
        if ($client === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument client is missing.');
        }

        $channel = $this->optionalStringArg(args: $args, key: 'channel');
        if ($channel === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument channel is missing.');
        }

        $title = $this->optionalStringArg(args: $args, key: 'title');
        if ($title === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument title is missing.');
        }

        if ($this->ticketService->isConfigured() === false) {
            return $this->errorEnvelope(
                code: 'not_configured',
                message: 'Pipelinq is not fully configured: the OpenRegister register or ticket schema is missing.'
            );
        }

        $payload = [
            'client'     => $client,
            'channel'    => $channel,
            'title'      => $title,
            'occurredAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];

        $outcome = $this->optionalStringArg(args: $args, key: 'outcome');
        if ($outcome !== null) {
            $payload['outcome'] = $outcome;
        }

        $notes = $this->optionalStringArg(args: $args, key: 'notes');
        if ($notes !== null) {
            $payload['description'] = $notes;
        }

        try {
            $saved = $this->ticketService->save(TicketService::TYPE_CONTACTMOMENT, $payload);
        } catch (\Exception $e) {
            return $this->mapServiceException(operation: 'log contactmoment', exception: $e);
        }

        $data = $this->toArray(item: $saved);

        return [
            'ticketId' => (string) ($data['id'] ?? $data['uuid'] ?? ''),
        ];

    }//end handleLogContactmoment()

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
     * Resolve the configured OpenRegister register + unified ticket schema.
     *
     * Requests are `ticket` objects with `ticketType: request`
     * (unify-ticket-supertype); both tools narrow on the discriminator instead
     * of resolving the retired `request_schema`.
     *
     * @return array<string, mixed> Either ['register' => ..., 'schema' => ...] or an error envelope.
     */
    private function resolveRequestContext(): array
    {
        if ($this->ticketService->isConfigured() === false) {
            return $this->errorEnvelope(
                code: 'not_configured',
                message: 'Pipelinq is not fully configured: the OpenRegister register or ticket schema is missing.'
            );
        }

        return [
            'register' => $this->ticketService->getRegisterId(),
            'schema'   => $this->ticketService->getSchemaId(),
        ];

    }//end resolveRequestContext()

    /**
     * Build the activity timeline for an entity (best-effort).
     *
     * A timeline failure must not sink the enclosing read — it is logged and
     * an empty timeline is returned.
     *
     * @param string $entityType The entity type (request|client|lead)
     * @param string $entityId   The entity UUID
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTimeline(string $entityType, string $entityId): array
    {
        try {
            $result = $this->timelineService->getTimeline(
                entityType: $entityType,
                entityId: $entityId,
                params: ['_limit' => self::LIST_CAP]
            );

            return array_slice(array: $result['items'], offset: 0, length: self::LIST_CAP);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq MCP: timeline aggregation failed',
                ['entityType' => $entityType, 'entityId' => $entityId, 'exception' => $e->getMessage()]
            );
            return [];
        }//end try

    }//end fetchTimeline()

    /**
     * Resolve the configured OpenRegister register + client schema.
     *
     * @return array<string, mixed> Either ['register' => ..., 'schema' => ...] or an error envelope.
     */
    private function resolveClientContext(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');

        if ($register === '' || $schema === '') {
            return $this->errorEnvelope(
                code: 'not_configured',
                message: 'Pipelinq is not fully configured: the OpenRegister register or client schema is missing.'
            );
        }

        return ['register' => $register, 'schema' => $schema];

    }//end resolveClientContext()

    /**
     * Resolve the configured OpenRegister register + lead schema.
     *
     * @return array<string, mixed> Either ['register' => ..., 'schema' => ...] or an error envelope.
     */
    private function resolveLeadContext(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');

        if ($register === '' || $schema === '') {
            return $this->errorEnvelope(
                code: 'not_configured',
                message: 'Pipelinq is not fully configured: the OpenRegister register or lead schema is missing.'
            );
        }

        return ['register' => $register, 'schema' => $schema];

    }//end resolveLeadContext()

    /**
     * Case-insensitive substring match of a query against a set of fields.
     *
     * @param array<int, mixed> $haystackFields The candidate field values
     * @param string            $query          The free-text query
     *
     * @return bool True if the query is a substring of any field
     */
    private function matchesQuery(array $haystackFields, string $query): bool
    {
        $needle = mb_strtolower($query);
        foreach ($haystackFields as $field) {
            if (is_scalar($field) === false) {
                continue;
            }

            $haystack = mb_strtolower((string) $field);
            if ($haystack !== '' && str_contains(haystack: $haystack, needle: $needle) === true) {
                return true;
            }
        }

        return false;

    }//end matchesQuery()

    /**
     * Decorate a lead array with a `winProbability` alias of its `probability`
     * field. `probability` is a caller-set raw input (0-100, "chance of
     * winning"); the lead schema has no dedicated winProbability calculation,
     * so this is a tool-side response alias, not a new persisted field or a
     * recomputation of an existing x-openregister-calculations value.
     *
     * @param array<string, mixed> $lead The raw lead array
     *
     * @return array<string, mixed> The decorated lead array
     */
    private function decorateLead(array $lead): array
    {
        if (array_key_exists('probability', $lead) === true && array_key_exists('winProbability', $lead) === false) {
            $lead['winProbability'] = $lead['probability'];
        }

        return $lead;

    }//end decorateLead()

    /**
     * Compose the live 360 summary for a client from RBAC-visible reads.
     *
     * Each aggregation is best-effort: a failure is logged and degrades to a
     * zero/empty default rather than sinking the enclosing getClient read.
     *
     * @param string $clientId The client UUID
     *
     * @return array<string, mixed>
     */
    private function buildClientSummary(string $clientId): array
    {
        $openLeads = $this->summarizeOpenLeads(clientId: $clientId);

        return [
            'openTicketCount'       => $this->countOpenTickets(clientId: $clientId),
            'openLeadCount'         => $openLeads['count'],
            'openLeadValue'         => $openLeads['value'],
            'recentContactmomenten' => $this->fetchTimeline(entityType: 'client', entityId: $clientId),
        ];

    }//end buildClientSummary()

    /**
     * Count open tickets (any ticketType) linked to a client (best-effort).
     *
     * @param string $clientId The client UUID
     *
     * @return int The open-ticket count
     */
    private function countOpenTickets(string $clientId): int
    {
        if ($this->ticketService->isConfigured() === false) {
            return 0;
        }

        try {
            $objectService = $this->getObjectService();

            $tickets = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $this->ticketService->getRegisterId(),
                        'schema'   => $this->ticketService->getSchemaId(),
                        'client'   => $clientId,
                    ],
                    'limit'   => self::AGGREGATION_FETCH_CAP,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq MCP: getClient open-ticket aggregation failed',
                ['clientId' => $clientId, 'exception' => $e->getMessage()]
            );
            return 0;
        }//end try

        $count = 0;
        foreach ($tickets as $raw) {
            $data = $this->toArray(item: $raw);
            if (in_array(($data['status'] ?? ''), self::OPEN_TICKET_STATUSES, true) === true) {
                $count++;
            }
        }

        return $count;

    }//end countOpenTickets()

    /**
     * Count + sum the value of open leads linked to a client (best-effort).
     *
     * @param string $clientId The client UUID
     *
     * @return array{count: int, value: float}
     */
    private function summarizeOpenLeads(string $clientId): array
    {
        $default = ['count' => 0, 'value' => 0.0];

        $config = $this->resolveLeadContext();
        if (isset($config['error']) === true) {
            return $default;
        }

        try {
            $objectService = $this->getObjectService();

            $leads = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $config['register'],
                        'schema'   => $config['schema'],
                        'client'   => $clientId,
                        'status'   => 'open',
                    ],
                    'limit'   => self::AGGREGATION_FETCH_CAP,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq MCP: getClient open-lead aggregation failed',
                ['clientId' => $clientId, 'exception' => $e->getMessage()]
            );
            return $default;
        }//end try

        $count = 0;
        $value = 0.0;
        foreach ($leads as $raw) {
            $data   = $this->toArray(item: $raw);
            $count += 1;
            $value += (float) ($data['value'] ?? 0);
        }

        return ['count' => $count, 'value' => $value];

    }//end summarizeOpenLeads()

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
