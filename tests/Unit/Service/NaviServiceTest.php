<?php

/**
 * Unit tests for the Pipelinq NaviService.
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
 * @spec openspec/changes/dashboard/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\NaviConversationStore;
use OCA\Pipelinq\Service\NaviService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers intent detection, context building, empty-result degradation,
 * response shaping and the conversation store behind follow-up questions. The
 * OpenRegister ObjectService is faked through the DI container so the tests run
 * without the OR app installed.
 */
class NaviServiceTest extends TestCase
{
    /**
     * The clarification a query with no recognisable intent produces. A
     * follow-up that inherited its intent must NOT produce this.
     *
     * @var string
     */
    private const CLARIFICATION = 'I am not sure how to answer that yet.';

    /**
     * A conversation identifier of the shape NaviController mints.
     *
     * @var string
     */
    private const CONVERSATION_ID = '0123456789abcdef0123456789abcdef';

    /**
     * Build a service with deterministic config and a fake ObjectService.
     *
     * Request / contactmoment fixtures are keyed `ticket_schema:<ticketType>`:
     * both are subtypes of the unified `ticket` schema and are narrowed with a
     * `ticketType` filter rather than with a schema of their own.
     *
     * @param array<string, array<int, array<string, mixed>>> $byCollection Per-collection fixture rows.
     * @param bool                                            $configMissing Force the register/schema config blank.
     * @param ICache|null                                     $cache         Conversation cache; a throwaway
     *                                                                       empty one when omitted.
     *
     * @return NaviService
     */
    private function buildService(
        array $byCollection = [],
        bool $configMissing = false,
        ?ICache $cache = null,
    ): NaviService {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $appId, string $key, string $default = '') use ($configMissing): string {
                if ($configMissing === true) {
                    return '';
                }
                if ($key === 'register') {
                    return 'register-1';
                }
                return $key;
            }
        );

        $objectService = new class($byCollection) {
            /**
             * @param array<string, array<int, array<string, mixed>>> $byCollection
             */
            public function __construct(private array $byCollection)
            {
            }

            /**
             * @param array{filters?: array<string, mixed>} $config
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $config): array
            {
                $filters = ($config['filters'] ?? []);
                $key     = (string) ($filters['schema'] ?? '');
                if (isset($filters['ticketType']) === true) {
                    $key .= ':'.(string) $filters['ticketType'];
                }
                return $this->byCollection[$key] ?? [];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $logger = $this->createMock(LoggerInterface::class);

        // TicketService stub serving the `ticket_schema:<ticketType>` fixtures;
        // it mirrors the real resolver's fail-soft contract (unconfigured -> []).
        // Only the OpenRegister-facing methods are doubled: detectTypeInText is
        // pure and runs for real, so these tests read the production vocabulary
        // rather than a copy of it.
        $ticketService = $this->createPartialMock(
            TicketService::class,
            ['getRegisterId', 'getSchemaId', 'isConfigured', 'findByType']
        );
        $ticketService->method('getRegisterId')->willReturn($configMissing === true ? '' : 'register-1');
        $ticketService->method('getSchemaId')->willReturn($configMissing === true ? '' : 'ticket_schema');
        $ticketService->method('isConfigured')->willReturn($configMissing === false);
        $ticketService->method('findByType')->willReturnCallback(
            static function (string $ticketType) use ($byCollection, $configMissing): array {
                if ($configMissing === true) {
                    return [];
                }
                return $byCollection['ticket_schema:'.$ticketType] ?? [];
            }
        );

        if ($cache === null) {
            $store = [];
            $cache = $this->inMemoryCache($store);
        }

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        // The real store, so the isolation tests exercise the production key
        // derivation and owner check rather than a test double of them.
        $conversationStore = new NaviConversationStore(cacheFactory: $cacheFactory, logger: $logger);

        return new NaviService(
            container: $container,
            appConfig: $appConfig,
            logger: $logger,
            ticketService: $ticketService,
            conversationStore: $conversationStore
        );
    }

    /**
     * An ICache backed by the caller's array, so two services can be given the
     * SAME store and any leak between them becomes observable.
     *
     * @param array<string, mixed> $store Backing store, by reference.
     *
     * @return ICache
     */
    private function inMemoryCache(array &$store): ICache
    {
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(
            static function (string $key) use (&$store): mixed {
                return ($store[$key] ?? null);
            }
        );
        $cache->method('set')->willReturnCallback(
            static function (string $key, mixed $value, int $ttl = 0) use (&$store): bool {
                $store[$key] = $value;
                return true;
            }
        );

        return $cache;
    }

    /**
     * detectIntent recognises trend / count / breakdown / conversion / unknown.
     *
     * @return void
     */
    public function testDetectIntentClassifiesKnownPhrases(): void
    {
        $service = $this->buildService();

        $this->assertSame('trend', $service->detectIntent(query: 'Show me the trend over time'));
        $this->assertSame('trend', $service->detectIntent(query: 'Wat is het verloop van leads?'));
        $this->assertSame('count', $service->detectIntent(query: 'Hoeveel verzoeken zijn er deze maand?'));
        $this->assertSame('breakdown', $service->detectIntent(query: 'Group requests by category'));
        $this->assertSame('breakdown', $service->detectIntent(query: 'verzoeken per categorie'));
        $this->assertSame('conversion', $service->detectIntent(query: 'What is my conversion rate this quarter?'));
        $this->assertSame('conversion', $service->detectIntent(query: 'Hoeveel leads zijn er gewonnen?'));
        $this->assertSame('unknown', $service->detectIntent(query: 'Bake me a cake'));
    }

    /**
     * processQuery returns resultType: "text" when ObjectService yields no data.
     *
     * @return void
     */
    public function testProcessQueryReturnsTextOnEmptyResult(): void
    {
        $service = $this->buildService(byCollection: ['lead_schema' => []]);

        $response = $service->processQuery(
            query: 'Show me the trend of leads',
            userId: 'alice'
        );

        $this->assertSame('text', $response['resultType']);
        $this->assertNotSame('', $response['textResponse']);
        $this->assertArrayNotHasKey('chartData', $response);
        $this->assertArrayNotHasKey('tableData', $response);
        $this->assertNotEmpty($response['suggestedFollowUps']);
    }

    /**
     * formatResponse emits chartData when the intermediate envelope contains it.
     *
     * @return void
     */
    public function testFormatResponseWithChartData(): void
    {
        $service = $this->buildService();

        $envelope = $service->formatResponse(
            query: 'Show trend',
            llmResponse: [
                'resultType' => 'chart',
                'chartData'  => ['type' => 'line', 'series' => [['name' => 'Leads', 'data' => [1, 2, 3]]]],
                'textResponse'       => 'Trend',
                'suggestedFollowUps' => ['A', 'B', 'C', 'D'],
            ],
            rawData: []
        );

        $this->assertSame('chart', $envelope['resultType']);
        $this->assertArrayHasKey('chartData', $envelope);
        $this->assertSame('line', $envelope['chartData']['type']);
        $this->assertCount(NaviService::MAX_FOLLOWUPS, $envelope['suggestedFollowUps']);
    }

    /**
     * formatResponse emits tableData when the intermediate envelope contains it.
     *
     * @return void
     */
    public function testFormatResponseWithTableData(): void
    {
        $service = $this->buildService();

        $envelope = $service->formatResponse(
            query: 'Conversion',
            llmResponse: [
                'resultType' => 'table',
                'tableData'  => ['columns' => ['Metric', 'Value'], 'rows' => [['Won', 3]]],
                'textResponse'       => 'Done',
                'suggestedFollowUps' => [],
            ],
            rawData: []
        );

        $this->assertSame('table', $envelope['resultType']);
        $this->assertArrayHasKey('tableData', $envelope);
        $this->assertCount(1, $envelope['tableData']['rows']);
        $this->assertSame([], $envelope['suggestedFollowUps']);
    }

    /**
     * processQuery with a conversion query against won/lost rows returns a
     * table result whose rate matches the simple won/total computation.
     *
     * @return void
     */
    public function testProcessQueryConversionRate(): void
    {
        $service = $this->buildService(byCollection: [
            'lead_schema' => [
                ['status' => 'won'],
                ['status' => 'won'],
                ['status' => 'lost'],
                ['status' => 'open'],
            ],
        ]);

        $response = $service->processQuery(
            query: 'What is my conversion rate?',
            userId: 'alice'
        );

        $this->assertSame('table', $response['resultType']);
        $this->assertArrayHasKey('tableData', $response);
        $row = $this->findTableRow($response['tableData']['rows'], 'Conversion rate (%)');
        $this->assertNotNull($row);
        $this->assertSame(50.0, $row[1]);
    }

    /**
     * A breakdown query reads request TICKETS (ticketType: request on the
     * unified ticket schema) and groups them by category.
     *
     * @return void
     */
    public function testProcessQueryBreakdownReadsRequestTickets(): void
    {
        $service = $this->buildService(byCollection: [
            'ticket_schema:request' => [
                ['category' => 'belastingen'],
                ['category' => 'belastingen'],
                ['category' => 'vergunningen'],
            ],
            // Contactmoment tickets share the schema but must not leak in.
            'ticket_schema:contactmoment' => [
                ['category' => 'should-not-appear'],
            ],
        ]);

        $response = $service->processQuery(
            query: 'Group requests by category',
            userId: 'alice'
        );

        $this->assertSame('chart', $response['resultType']);
        $this->assertSame(['belastingen', 'vergunningen'], $response['chartData']['labels']);
        $this->assertSame([2, 1], $response['chartData']['series'][0]['data']);
    }

    /**
     * Empty / whitespace query degrades to a text prompt — no exception.
     *
     * @return void
     */
    public function testProcessQueryHandlesBlankInput(): void
    {
        $service  = $this->buildService();
        $response = $service->processQuery(query: '   ', userId: 'alice');

        $this->assertSame('text', $response['resultType']);
        $this->assertNotEmpty($response['suggestedFollowUps']);
    }

    /**
     * The fixtures for the follow-up tests: four request tickets and a single
     * lead. The counts differ so the ANSWER reveals which subject was counted.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function followUpFixtures(): array
    {
        return [
            'ticket_schema:request' => [
                ['category' => 'permits'],
                ['category' => 'permits'],
                ['category' => 'taxes'],
                ['category' => 'waste'],
            ],
            'lead_schema' => [
                ['status' => 'open'],
            ],
        ];
    }

    /**
     * THE test for the second half of the follow-up scenario: the response must
     * reflect the accumulated conversation context.
     *
     * "And how many of those are overdue?" names no subject, so counted on its
     * own words it falls back to leads and answers "Found 1 records" — that is
     * what the control test below observes. As the second turn of a
     * conversation whose first turn counted REQUESTS, "those" must resolve to
     * the requests, and the answer must be "Found 4 records".
     *
     * The two fixture sets are deliberately different sizes, so the count in
     * the answer names which subject was actually queried.
     *
     * @return void
     */
    public function testFollowUpInheritsPreviousTurnSubject(): void
    {
        $store   = [];
        $service = $this->buildService(
            byCollection: $this->followUpFixtures(),
            cache: $this->inMemoryCache($store)
        );

        $first = $service->processQuery(
            query: 'How many requests are open?',
            userId: 'alice',
            conversationId: self::CONVERSATION_ID
        );
        $this->assertStringContainsString('Found 4 records', $first['textResponse']);

        $followUp = $service->processQuery(
            query: 'And how many of those are overdue?',
            userId: 'alice',
            conversationId: self::CONVERSATION_ID
        );

        $this->assertSame('text', $followUp['resultType']);
        $this->assertStringContainsString('Found 4 records', $followUp['textResponse']);
    }

    /**
     * Control for the test above: the same follow-up asked outside any
     * conversation counts the lead fallback instead, which is what proves the
     * inherited answer came from the stored context and not from the wording of
     * the follow-up itself.
     *
     * @return void
     */
    public function testFollowUpWithoutConversationFallsBackToLeads(): void
    {
        $service = $this->buildService(byCollection: $this->followUpFixtures());

        $response = $service->processQuery(
            query: 'And how many of those are overdue?',
            userId: 'alice'
        );

        $this->assertSame('text', $response['resultType']);
        $this->assertStringContainsString('Found 1 records', $response['textResponse']);
    }

    /**
     * A follow-up with no intent keyword of its own inherits the previous
     * turn's intent instead of degrading to the clarification prompt.
     *
     * "And what about last month?" classifies as `unknown` on its own words, so
     * outside a conversation it earns the clarification (asserted by the
     * control below). Inside one it is answered as the count its predecessor
     * was, over that predecessor's subject.
     *
     * @return void
     */
    public function testFollowUpWithoutIntentKeywordInheritsPreviousIntent(): void
    {
        $store   = [];
        $service = $this->buildService(
            byCollection: $this->followUpFixtures(),
            cache: $this->inMemoryCache($store)
        );

        $service->processQuery(
            query: 'How many requests are open?',
            userId: 'alice',
            conversationId: self::CONVERSATION_ID
        );

        $followUp = $service->processQuery(
            query: 'And what about last month?',
            userId: 'alice',
            conversationId: self::CONVERSATION_ID
        );

        $this->assertStringNotContainsString(self::CLARIFICATION, $followUp['textResponse']);
        $this->assertStringContainsString('Found 4 records', $followUp['textResponse']);
    }

    /**
     * Control for the intent-inheritance test: the same wording outside a
     * conversation still gets the clarification prompt.
     *
     * @return void
     */
    public function testQueryWithoutIntentKeywordGetsClarification(): void
    {
        $service = $this->buildService(byCollection: $this->followUpFixtures());

        $response = $service->processQuery(query: 'And what about last month?', userId: 'alice');

        $this->assertStringContainsString(self::CLARIFICATION, $response['textResponse']);
    }

    /**
     * Cross-user isolation. The Navi endpoint is `#[NoAdminRequired]`, so any
     * authenticated user can send any conversation identifier. Bob replays the
     * exact identifier Alice's conversation is stored under, against the SAME
     * cache, and must see none of Alice's turns: his follow-up falls back to
     * the clarification instead of inheriting Alice's request count.
     *
     * @return void
     */
    public function testConversationIsNotReadableByAnotherUser(): void
    {
        $store = [];
        $cache = $this->inMemoryCache($store);

        $alice = $this->buildService(byCollection: $this->followUpFixtures(), cache: $cache);
        $alice->processQuery(
            query: 'How many requests are open?',
            userId: 'alice',
            conversationId: self::CONVERSATION_ID
        );
        $this->assertNotSame([], $store, 'the conversation must have been stored at all');

        $bob      = $this->buildService(byCollection: $this->followUpFixtures(), cache: $cache);
        $response = $bob->processQuery(
            query: 'And what about last month?',
            userId: 'bob',
            conversationId: self::CONVERSATION_ID
        );

        $this->assertStringContainsString(self::CLARIFICATION, $response['textResponse']);
        $this->assertStringNotContainsString('Found 4 records', $response['textResponse']);
        $this->assertCount(
            2,
            $store,
            'one identifier used by two users must occupy two separate cache entries'
        );
    }

    /**
     * The owner recorded inside the conversation record is a second, independent
     * barrier: even a cache that hands back Alice's record for EVERY key — the
     * worst case a key collision or a poisoned store could produce — must not
     * let Bob inherit her context.
     *
     * @return void
     */
    public function testConversationRecordOwnedByAnotherUserIsIgnored(): void
    {
        $aliceRecord = (string) json_encode([
            'owner' => 'alice',
            'turns' => [['query' => 'How many requests are open?', 'intent' => 'count']],
        ]);

        $leakyCache = $this->createMock(ICache::class);
        $leakyCache->method('get')->willReturn($aliceRecord);
        $leakyCache->method('set')->willReturn(true);

        $bob      = $this->buildService(byCollection: $this->followUpFixtures(), cache: $leakyCache);
        $response = $bob->processQuery(
            query: 'And what about last month?',
            userId: 'bob',
            conversationId: self::CONVERSATION_ID
        );

        $this->assertStringContainsString(self::CLARIFICATION, $response['textResponse']);
        $this->assertStringNotContainsString('Found 4 records', $response['textResponse']);
    }

    /**
     * The record is bounded: it is written under the conversation TTL and keeps
     * at most NaviConversationStore::MAX_TURNS turns however long the chat runs.
     *
     * @return void
     */
    public function testConversationRecordIsBoundedByTurnsAndTtl(): void
    {
        $store = [];
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(
            static function (string $key) use (&$store): mixed {
                return ($store[$key] ?? null);
            }
        );
        $cache->expects($this->atLeastOnce())
            ->method('set')
            ->with(
                $this->isType('string'),
                $this->isType('string'),
                NaviConversationStore::TTL
            )
            ->willReturnCallback(
                static function (string $key, mixed $value, int $ttl = 0) use (&$store): bool {
                    $store[$key] = $value;
                    return true;
                }
            );

        $service   = $this->buildService(byCollection: $this->followUpFixtures(), cache: $cache);
        $extraRuns = (NaviConversationStore::MAX_TURNS + 5);
        for ($turn = 0; $turn < $extraRuns; $turn++) {
            $service->processQuery(
                query: 'How many requests are open?',
                userId: 'alice',
                conversationId: self::CONVERSATION_ID
            );
        }

        $this->assertCount(1, $store, 'one conversation must occupy exactly one cache entry');
        $record = json_decode((string) reset($store), true);
        $this->assertSame('alice', $record['owner']);
        $this->assertCount(NaviConversationStore::MAX_TURNS, $record['turns']);
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param string                        $label
     *
     * @return array<int, mixed>|null
     */
    private function findTableRow(array $rows, string $label): ?array
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $label) {
                return $row;
            }
        }
        return null;
    }
}
