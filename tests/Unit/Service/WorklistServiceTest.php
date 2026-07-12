<?php

/**
 * Unit tests for the Pipelinq WorklistService.
 *
 * Verifies the server-side "my work" union ports the client rules
 * verbatim: closed-stage lead exclusion, terminal request exclusion,
 * per-source overdue semantics, the overdue -> priority -> dueDate
 * comparator, the ?limit cap and the assignee scoping pushed into the
 * OpenRegister query.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Service\TicketService;
use OCA\Pipelinq\Service\WorklistService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests WorklistService union / exclusion / overdue / sort semantics and
 * the OpenRegister query shape (assignee filter + limits).
 */
class WorklistServiceTest extends TestCase
{
    /**
     * The fake ObjectService of the most recently built service — exposes
     * the captured findAll() configs via its public $calls property.
     *
     * @var object|null
     */
    private ?object $objectService = null;

    /**
     * Captured TicketService::findByType() calls of the most recently built
     * service, in call order: {ticketType, filters, limit}.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $ticketCalls = [];

    /**
     * Build a service with deterministic config and a fake ObjectService.
     *
     * Request fixtures are keyed `ticket_schema:request`: requests are a
     * subtype of the unified `ticket` schema, narrowed with a `ticketType`
     * filter rather than read from a schema of their own.
     *
     * @param array<string, array<int, array<string, mixed>>> $byCollection           Per-collection fixture rows.
     * @param bool                                            $registerMissing        Force the app-config `register` empty.
     * @param bool                                            $throwFromObjectService Force the fake to throw on findAll.
     *
     * @return WorklistService
     */
    private function buildService(
        array $byCollection = [],
        bool $registerMissing = false,
        bool $throwFromObjectService = false,
    ): WorklistService {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $appId, string $key, string $default = '') use ($registerMissing): string {
                if ($key === 'register') {
                    return $registerMissing === true ? '' : 'register-1';
                }
                return $key;
            }
        );

        $this->objectService = new class($byCollection, $throwFromObjectService) {
            /**
             * Captured findAll() configs, in call order.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $calls = [];

            /**
             * @param array<string, array<int, array<string, mixed>>> $byCollection
             */
            public function __construct(private array $byCollection, private bool $throwAlways)
            {
            }

            /**
             * @param array{filters?: array<string, mixed>, limit?: int} $config
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $config): array
            {
                $this->calls[] = $config;
                if ($this->throwAlways === true) {
                    throw new \RuntimeException('boom');
                }
                $filters = ($config['filters'] ?? []);
                $key     = (string) ($filters['schema'] ?? '');
                if (isset($filters['ticketType']) === true) {
                    $key .= ':'.(string) $filters['ticketType'];
                }
                return $this->byCollection[$key] ?? [];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $parameters = []): string {
                return $text;
            }
        );

        $logger = $this->createMock(LoggerInterface::class);

        // TicketService stub serving the `ticket_schema:<ticketType>` fixtures.
        // It records its calls so the pushed-down assignee filter and fetch cap
        // stay assertable, and mirrors the real resolver's fail-soft contract
        // (unconfigured register / OR failure -> empty row set, never a throw).
        $this->ticketCalls = [];
        $ticketService     = $this->createMock(TicketService::class);
        $ticketService->method('getRegisterId')->willReturn($registerMissing === true ? '' : 'register-1');
        $ticketService->method('getSchemaId')->willReturn('ticket_schema');
        $ticketService->method('isConfigured')->willReturn($registerMissing === false);
        $ticketService->method('findByType')->willReturnCallback(
            function (string $ticketType, array $extraFilters = [], int $limit = 10000) use (
                $byCollection,
                $registerMissing,
                $throwFromObjectService
            ): array {
                $this->ticketCalls[] = [
                    'ticketType' => $ticketType,
                    'filters'    => $extraFilters,
                    'limit'      => $limit,
                ];

                if ($registerMissing === true || $throwFromObjectService === true) {
                    return [];
                }

                return $byCollection['ticket_schema:'.$ticketType] ?? [];
            }
        );

        return new WorklistService(
            container: $container,
            appConfig: $appConfig,
            l10n: $l10n,
            logger: $logger,
            ticketService: $ticketService
        );
    }

    /**
     * ISO-8601 date string relative to now.
     *
     * @param string $modifier Date modifier (e.g. '-31 days').
     *
     * @return string
     */
    private static function iso(string $modifier): string
    {
        return (new DateTimeImmutable($modifier))->format(DateTimeInterface::ATOM);
    }

    /**
     * The union merges leads and requests into normalized rows with the
     * documented shape, entity types and route names.
     *
     * @return void
     */
    public function testMineUnionsLeadsAndRequestsIntoNormalizedRows(): void
    {
        $service = $this->buildService(byCollection: [
            'lead_schema' => [
                [
                    'id'                => 'lead-1',
                    'title'             => 'Big deal',
                    'stage'             => 'Proposal',
                    'priority'          => 'high',
                    'expectedCloseDate' => self::iso('+5 days'),
                ],
            ],
            'ticket_schema:request' => [
                [
                    'id'          => 'req-1',
                    'title'       => 'Support question',
                    'status'      => 'in_progress',
                    'priority'    => 'low',
                    'occurredAt' => self::iso('-2 days'),
                ],
            ],
        ]);

        $result = $service->getMine(userId: 'alice');

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['leadCount']);
        $this->assertSame(1, $result['requestCount']);
        $this->assertCount(2, $result['items']);

        $lead = $result['items'][0];
        $this->assertSame('lead', $lead['entityType']);
        $this->assertSame('lead-1', $lead['id']);
        $this->assertSame('Big deal', $lead['title']);
        $this->assertSame('Proposal', $lead['stageOrStatus']);
        $this->assertSame('high', $lead['priority']);
        $this->assertFalse($lead['isOverdue']);
        $this->assertSame('LeadDetail', $lead['routeName']);
        $this->assertArrayNotHasKey('dueTimestamp', $lead);

        $request = $result['items'][1];
        $this->assertSame('request', $request['entityType']);
        $this->assertSame('req-1', $request['id']);
        // Status label mirrors the client STATUS_LABELS map (requestStatus.js:15-21).
        $this->assertSame('In progress', $request['stageOrStatus']);
        $this->assertFalse($request['isOverdue']);
        // unify-ticket-supertype: request-type tickets open on the single unified
        // detail page; the RequestDetail page was retired with its schema.
        $this->assertSame('TicketDetail', $request['routeName']);
    }

    /**
     * Leads sitting in a stage flagged isClosed on any pipeline are
     * excluded (MyWorkWidget.vue:76 + dashboardData.js getClosedStageNames).
     *
     * @return void
     */
    public function testLeadsInClosedPipelineStagesAreExcluded(): void
    {
        $service = $this->buildService(byCollection: [
            'pipeline_schema' => [
                [
                    'id'     => 'pipe-1',
                    'stages' => [
                        ['name' => 'Intake', 'isClosed' => false],
                        ['name' => 'Won', 'isClosed' => true],
                        ['name' => 'Lost', 'isClosed' => true],
                    ],
                ],
            ],
            'lead_schema' => [
                ['id' => 'lead-open', 'title' => 'Open', 'stage' => 'Intake'],
                ['id' => 'lead-won', 'title' => 'Won deal', 'stage' => 'Won'],
                ['id' => 'lead-lost', 'title' => 'Lost deal', 'stage' => 'Lost'],
            ],
        ]);

        $result = $service->getMine(userId: 'alice');

        $this->assertSame(1, $result['total']);
        $this->assertSame('lead-open', $result['items'][0]['id']);
    }

    /**
     * A lead is overdue when expectedCloseDate is strictly in the past;
     * future or absent dates are not overdue (MyWorkWidget.vue:77,85).
     *
     * @return void
     */
    public function testLeadOverdueWhenExpectedCloseDateInPast(): void
    {
        $service = $this->buildService(byCollection: [
            'lead_schema' => [
                ['id' => 'past', 'title' => 'Past', 'expectedCloseDate' => self::iso('-1 day')],
                ['id' => 'future', 'title' => 'Future', 'expectedCloseDate' => self::iso('+1 day')],
                ['id' => 'none', 'title' => 'No date'],
            ],
        ]);

        $result = $service->getMine(userId: 'alice');
        $byId   = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertTrue($byId['past']['isOverdue']);
        $this->assertFalse($byId['future']['isOverdue']);
        $this->assertFalse($byId['none']['isOverdue']);
        $this->assertNull($byId['none']['dueDate']);
    }

    /**
     * Requests in a terminal status (completed / rejected / converted)
     * are excluded from the worklist (MyWorkWidget.vue:90).
     *
     * @return void
     */
    public function testTerminalRequestsAreExcluded(): void
    {
        $service = $this->buildService(byCollection: [
            'ticket_schema:request' => [
                ['id' => 'r-new', 'title' => 'A', 'status' => 'new'],
                ['id' => 'r-progress', 'title' => 'B', 'status' => 'in_progress'],
                ['id' => 'r-completed', 'title' => 'C', 'status' => 'completed'],
                ['id' => 'r-rejected', 'title' => 'D', 'status' => 'rejected'],
                ['id' => 'r-converted', 'title' => 'E', 'status' => 'converted'],
            ],
        ]);

        $result = $service->getMine(userId: 'alice');

        $ids = array_column($result['items'], 'id');
        sort($ids);
        $this->assertSame(['r-new', 'r-progress'], $ids);
    }

    /**
     * A request is overdue only when its occurredAt (the ticket-schema name of
     * the request's requestedAt) is older than 30 days
     * (MyWorkWidget.vue:99 — strictly greater than the 30-day window).
     *
     * @return void
     */
    public function testRequestOverdueOnlyWhenOccurredAtOlderThanThirtyDays(): void
    {
        $service = $this->buildService(byCollection: [
            'ticket_schema:request' => [
                ['id' => 'old', 'title' => 'Old', 'status' => 'new', 'occurredAt' => self::iso('-31 days')],
                ['id' => 'recent', 'title' => 'Recent', 'status' => 'new', 'occurredAt' => self::iso('-29 days')],
                ['id' => 'undated', 'title' => 'Undated', 'status' => 'new'],
            ],
        ]);

        $result = $service->getMine(userId: 'alice');
        $byId   = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertTrue($byId['old']['isOverdue']);
        $this->assertFalse($byId['recent']['isOverdue']);
        $this->assertFalse($byId['undated']['isOverdue']);
    }

    /**
     * Rows sort overdue first, then by priority (urgent > high > normal >
     * low, unknown treated as normal), then by due date ascending with
     * date-less rows last (MyWorkWidget.vue:103-112).
     *
     * @return void
     */
    public function testSortsOverdueThenPriorityThenDueDate(): void
    {
        $service = $this->buildService(byCollection: [
            'lead_schema' => [
                [
                    'id'                => 'lead-normal-later',
                    'title'             => 'Normal later',
                    'priority'          => 'normal',
                    'expectedCloseDate' => self::iso('+10 days'),
                ],
                [
                    'id'                => 'lead-normal-sooner',
                    'title'             => 'Normal sooner',
                    'priority'          => 'normal',
                    'expectedCloseDate' => self::iso('+2 days'),
                ],
                [
                    'id'       => 'lead-unknown-priority-no-date',
                    'title'    => 'Unknown priority',
                    'priority' => 'whatever',
                ],
                [
                    'id'                => 'lead-overdue-normal',
                    'title'             => 'Overdue normal',
                    'priority'          => 'normal',
                    'expectedCloseDate' => self::iso('-3 days'),
                ],
                [
                    'id'       => 'lead-urgent-no-date',
                    'title'    => 'Urgent no date',
                    'priority' => 'urgent',
                ],
            ],
            'ticket_schema:request' => [
                [
                    'id'          => 'req-overdue-urgent',
                    'title'       => 'Overdue urgent',
                    'status'      => 'new',
                    'priority'    => 'urgent',
                    'occurredAt' => self::iso('-40 days'),
                ],
                [
                    'id'          => 'req-low',
                    'title'       => 'Low prio',
                    'status'      => 'new',
                    'priority'    => 'low',
                    'occurredAt' => self::iso('-1 day'),
                ],
            ],
        ]);

        $result = $service->getMine(userId: 'alice');

        $this->assertSame(
            [
                // Overdue block first: urgent request before normal lead.
                'req-overdue-urgent',
                'lead-overdue-normal',
                // Non-overdue: urgent first (even without a due date) ...
                'lead-urgent-no-date',
                // ... then normal priority by due date ascending ...
                'lead-normal-sooner',
                'lead-normal-later',
                // ... unknown priority ranks as normal, date-less rows last ...
                'lead-unknown-priority-no-date',
                // ... and low priority sorts after normal despite having a date.
                'req-low',
            ],
            array_column($result['items'], 'id')
        );
    }

    /**
     * `limit` caps the returned rows while total / leadCount /
     * requestCount keep reflecting the full union (widget top-5 vs the
     * full MyWork page).
     *
     * @return void
     */
    public function testLimitCapsItemsButCountsReflectFullUnion(): void
    {
        $leads = [];
        for ($i = 0; $i < 4; $i++) {
            $leads[] = [
                'id'                => sprintf('lead-%d', $i),
                'title'             => sprintf('Lead %d', $i),
                'expectedCloseDate' => self::iso(sprintf('+%d days', $i + 1)),
            ];
        }

        $requests = [];
        for ($i = 0; $i < 3; $i++) {
            $requests[] = [
                'id'     => sprintf('req-%d', $i),
                'title'  => sprintf('Request %d', $i),
                'status' => 'new',
            ];
        }

        $service = $this->buildService(byCollection: [
            'lead_schema'           => $leads,
            'ticket_schema:request' => $requests,
        ]);

        $result = $service->getMine(userId: 'alice', limit: 5);

        $this->assertCount(5, $result['items']);
        $this->assertSame(7, $result['total']);
        $this->assertSame(4, $result['leadCount']);
        $this->assertSame(3, $result['requestCount']);
    }

    /**
     * Assignee scoping and fetch caps are pushed into the OpenRegister
     * query: leads/requests filter on assignee with limit 200, pipelines
     * are unscoped with limit 100 (mirrors dashboardData.js:126,160-175).
     * Requests are queried on the unified ticket schema with a
     * `ticketType: request` discriminator instead of on `request_schema`.
     *
     * @return void
     */
    public function testAssigneeFilterAndLimitsPushedToOpenRegister(): void
    {
        $service = $this->buildService(byCollection: []);
        $service->getMine(userId: 'alice');

        // Leads + pipelines go straight to OpenRegister; requests go through
        // the ticket resolver, so only two raw findAll() calls remain.
        $calls = $this->objectService->calls;
        $this->assertCount(2, $calls);

        $byQueriedSchema = [];
        foreach ($calls as $call) {
            $byQueriedSchema[(string) $call['filters']['schema']] = $call;
        }

        $this->assertArrayNotHasKey('request_schema', $byQueriedSchema);

        $this->assertSame('alice', $byQueriedSchema['lead_schema']['filters']['assignee']);
        $this->assertSame(200, $byQueriedSchema['lead_schema']['limit']);
        $this->assertSame('register-1', $byQueriedSchema['lead_schema']['filters']['register']);

        $this->assertArrayNotHasKey('assignee', $byQueriedSchema['pipeline_schema']['filters']);
        $this->assertSame(100, $byQueriedSchema['pipeline_schema']['limit']);

        // The request query narrows the unified ticket schema on ticketType and
        // still pushes the assignee filter + the 200-row cap into OpenRegister.
        $this->assertSame(
            [
                [
                    'ticketType' => TicketService::TYPE_REQUEST,
                    'filters'    => ['assignee' => 'alice'],
                    'limit'      => 200,
                ],
            ],
            $this->ticketCalls
        );
    }

    /**
     * Missing stage / priority / status fall back to the client defaults:
     * stageOrStatus `-`, priority `normal`, unknown status echoed raw
     * (MyWorkWidget.vue:82-83, requestStatus.js:89-91).
     *
     * @return void
     */
    public function testDefaultsMirrorClientFallbacks(): void
    {
        $service = $this->buildService(byCollection: [
            'lead_schema' => [
                ['id' => 'lead-min', 'title' => 'Minimal lead'],
            ],
            'ticket_schema:request' => [
                ['id' => 'req-odd', 'title' => 'Odd status', 'status' => 'somethingelse'],
            ],
        ]);

        $result = $service->getMine(userId: 'alice');
        $byId   = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertSame('-', $byId['lead-min']['stageOrStatus']);
        $this->assertSame('normal', $byId['lead-min']['priority']);
        $this->assertSame('somethingelse', $byId['req-odd']['stageOrStatus']);
        $this->assertSame('normal', $byId['req-odd']['priority']);
    }

    /**
     * Unconfigured register yields an empty worklist without touching
     * OpenRegister (graceful no-op, same posture as the client's missing
     * objectTypeRegistry entries).
     *
     * @return void
     */
    public function testUnconfiguredRegisterYieldsEmptyWorklist(): void
    {
        $service = $this->buildService(byCollection: [], registerMissing: true);

        $result = $service->getMine(userId: 'alice');

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['leadCount']);
        $this->assertSame(0, $result['requestCount']);
        $this->assertSame([], $this->objectService->calls);
    }

    /**
     * OpenRegister failures surface as RuntimeException so the controller
     * maps them onto a 500 with a static message.
     *
     * @return void
     */
    public function testThrowsRuntimeExceptionWhenOpenRegisterFails(): void
    {
        $service = $this->buildService(byCollection: [], throwFromObjectService: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Worklist query failed');
        $service->getMine(userId: 'alice');
    }
}
