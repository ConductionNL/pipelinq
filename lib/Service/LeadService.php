<?php
/**
 * Lead Service.
 *
 * Resolution point + read/write facade for the `lead` schema's MCP-facing
 * operations: creating a lead and computing the per-stage pipeline forecast.
 * Both public entry points are annotated `#[McpTool]` (OpenRegister ADR-063
 * chain 3/3, PR #363) so OpenRegister's AttributeToolScanner can discover
 * them once Pipelinq declares this class scannable via
 * `OCA\Pipelinq\Mcp\PipelinqScannableServices`.
 *
 * Migrated out of `OCA\Pipelinq\Mcp\PipelinqToolProvider` (deleted) by
 * `plq-mcp-provider-surgery` — the hand-written CRUD reads (`listLeads`,
 * `searchLeads`, `getLead`) are superseded by OpenRegister's schema-derived
 * `pipelinq.lead.{search,get}` tools and are NOT ported here; only the two
 * curated, non-CRUD tools survive.
 *
 * Auth design (OWASP A01:2021 / ADR-005), unchanged from the hand-written
 * provider:
 * - Argument validation runs first (cheap before expensive).
 * - The write in createLead() goes through OpenRegister's ObjectService with
 *   RBAC left at its default (enabled); a permission denial is surfaced as a
 *   `forbidden` error envelope, never swallowed into a false success.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/specs/crm-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * LeadService
 *
 * @spec openspec/specs/crm-mcp-tool-surface/spec.md
 *   (Requirement: MCP provider exposes RBAC-guarded CRM write tools)
 * @spec openspec/specs/crm-mcp-tool-surface/spec.md
 *   (Requirement: MCP provider exposes a CRM read tool surface)
 */
class LeadService
{

    /**
     * The OpenRegister ObjectService class name (resolved lazily via the container).
     *
     * @var string
     */
    private const OR_OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

    /**
     * Fetch window for the pipeline-forecast aggregation — mirrors
     * PipelinqToolProvider::AGGREGATION_FETCH_CAP prior to migration.
     *
     * @var int
     */
    private const AGGREGATION_FETCH_CAP = 500;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (for OR ObjectService).
     * @param IAppConfig         $appConfig The app config (lead schema resolution).
     * @param LoggerInterface    $logger    The PSR-3 logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a new sales lead. Only "title" is required; client, value,
     * source and assignee are optional.
     *
     * Validates the required `title`, then writes through
     * ObjectService->saveObject on the configured lead schema (RBAC `create`
     * enforced). The saved lead is returned unmodified — the server-computed
     * `qualificationScore` and the declarative `winProbability` calculation
     * (x-openregister-calculations) flow through as materialised by
     * OpenRegister; this method does not alias or recompute either
     * (pipelinq #381).
     *
     * @param string      $title    A short description of the opportunity.
     * @param string|null $client   Optional client UUID this lead is linked to.
     * @param float|null  $value    Optional estimated deal value.
     * @param string|null $source   Optional origin of the lead (e.g. website, referral, cold-call).
     * @param string|null $assignee Optional Nextcloud user id of the lead owner.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/crm-mcp-tool-surface/spec.md
     *   (Requirement: MCP provider exposes RBAC-guarded CRM write tools)
     */
    #[McpTool(
        name: 'createLead',
        description: 'Create a new sales lead. Only "title" is required; client, value, source and assignee are optional.',
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: false,
        scope: 'create'
    )]
    public function createLead(
        string $title,
        ?string $client=null,
        ?float $value=null,
        ?string $source=null,
        ?string $assignee=null
    ): array {
        $title = trim($title);
        if ($title === '') {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument title is missing.');
        }

        $config = $this->resolveLeadContext();
        if (isset($config['error']) === true) {
            return $config;
        }

        $payload = ['title' => $title];

        if ($client !== null && $client !== '') {
            $payload['client'] = $client;
        }

        if ($value !== null) {
            $payload['value'] = $value;
        }

        if ($source !== null && $source !== '') {
            $payload['source'] = $source;
        }

        if ($assignee !== null && $assignee !== '') {
            $payload['assignee'] = $assignee;
        }

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

        return ['lead' => $this->toArray(item: $saved)];

    }//end createLead()

    /**
     * Per-stage totals over open leads: lead count, summed value, weighted
     * value, plus a grand total.
     *
     * Reads RBAC-visible open leads, buckets by pipeline stage, and sums
     * `value` and the already-materialised `weightedValue` calculation
     * (never recomputed). Rows are ordered by the lowest `stageOrder` seen
     * in each bucket (ADR-031 exception (2): a per-request, caller-shaped
     * aggregation, not a stored x-openregister-aggregations value).
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/crm-mcp-tool-surface/spec.md
     *   (Requirement: MCP provider exposes a CRM read tool surface)
     */
    #[McpTool(
        name: 'pipelineForecast',
        description: 'Per-stage totals over open leads: lead count, summed value, weighted value, plus a grand total.',
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        scope: 'read'
    )]
    public function pipelineForecast(): array
    {
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

    }//end pipelineForecast()

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Reduce a set of raw open-lead rows into per-stage forecast rows plus a
     * grand total.
     *
     * @param iterable<mixed> $rawLeads The raw open-lead rows.
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
     * @param mixed $item Raw item from ObjectService.
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
