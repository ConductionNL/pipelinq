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

use OCA\Pipelinq\Service\NaviService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers intent detection, context building, empty-result degradation and
 * response shaping. The OpenRegister ObjectService is faked through the DI
 * container so the tests run without the OR app installed.
 */
class NaviServiceTest extends TestCase
{
    /**
     * Build a service with deterministic config and a fake ObjectService.
     *
     * Request / contactmoment fixtures are keyed `ticket_schema:<ticketType>`:
     * both are subtypes of the unified `ticket` schema and are narrowed with a
     * `ticketType` filter rather than with a schema of their own.
     *
     * @param array<string, array<int, array<string, mixed>>> $byCollection Per-collection fixture rows.
     * @param bool                                            $configMissing Force the register/schema config blank.
     *
     * @return NaviService
     */
    private function buildService(
        array $byCollection = [],
        bool $configMissing = false,
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
        $ticketService = $this->createMock(TicketService::class);
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

        return new NaviService(
            container: $container,
            appConfig: $appConfig,
            logger: $logger,
            ticketService: $ticketService
        );
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
