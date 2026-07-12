<?php

/**
 * Unit tests for PipelinqToolProvider.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Mcp;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Mcp\PipelinqToolProvider;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCA\Pipelinq\Service\TicketService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCP\IAppConfig;

/**
 * Tests for PipelinqToolProvider.
 *
 * Fixtures follow the design.md seed data (municipality, consultancy, travel
 * agency archetypes), with client references using the nil UUID rather than
 * realistic-looking values.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class PipelinqToolProviderTest extends TestCase
{

    /**
     * Nil UUID used for every client/lead/ticket fixture reference.
     *
     * @var string
     */
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * The unified ticket resolver mock.
     *
     * @var TicketService&MockObject
     */
    private TicketService $ticketService;

    /**
     * The DI container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * The activity timeline service mock.
     *
     * @var ActivityTimelineService&MockObject
     */
    private ActivityTimelineService $timelineService;

    /**
     * The app config mock (client_schema / lead_schema resolution).
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->ticketService   = $this->createMock(originalClassName: TicketService::class);
        $this->container       = $this->createMock(originalClassName: ContainerInterface::class);
        $this->timelineService = $this->createMock(originalClassName: ActivityTimelineService::class);
        $this->appConfig       = $this->createMock(originalClassName: IAppConfig::class);
        $this->logger          = $this->createMock(originalClassName: LoggerInterface::class);
    }//end setUp()

    /**
     * Build the provider under test.
     *
     * @return PipelinqToolProvider
     */
    private function buildProvider(): PipelinqToolProvider
    {
        return new PipelinqToolProvider(
            ticketService: $this->ticketService,
            container: $this->container,
            timelineService: $this->timelineService,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }//end buildProvider()

    /**
     * Stub IAppConfig::getValueString with a fixed key => value map; any key
     * not in the map resolves to the caller's default (empty string).
     *
     * @param array<string, string> $values Map of app-config key => value
     *
     * @return void
     */
    private function stubAppConfig(array $values): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $appId, string $key, string $default = '') use ($values): string {
                return $values[$key] ?? $default;
            }
        );
    }//end stubAppConfig()

    /**
     * Build an ObjectService mock and wire it as the container's resolved service.
     *
     * @return ObjectService&MockObject
     */
    private function mockObjectService(): ObjectService
    {
        $objectService = $this->createMock(originalClassName: ObjectService::class);
        $this->container->method('get')->willReturn($objectService);

        return $objectService;
    }//end mockObjectService()

    // =========================================================================
    // Catalogue
    // =========================================================================

    /**
     * getAppId() returns the pipelinq slug.
     *
     * @return void
     */
    public function testGetAppIdReturnsPipelinq(): void
    {
        $this->assertSame(expected: 'pipelinq', actual: $this->buildProvider()->getAppId());
    }//end testGetAppIdReturnsPipelinq()

    /**
     * getTools() returns the full catalogue with valid descriptor shapes.
     *
     * @return void
     */
    public function testGetToolsReturnsFullCatalogueWithValidDescriptors(): void
    {
        $tools = $this->buildProvider()->getTools();

        $this->assertCount(expectedCount: 11, haystack: $tools);

        foreach ($tools as $tool) {
            $this->assertArrayHasKey(key: 'id', array: $tool);
            $this->assertArrayHasKey(key: 'name', array: $tool);
            $this->assertArrayHasKey(key: 'description', array: $tool);
            $this->assertArrayHasKey(key: 'inputSchema', array: $tool);

            $this->assertIsString(actual: $tool['id']);
            $this->assertStringStartsWith(prefix: 'pipelinq.', string: $tool['id']);
            $this->assertNotEmpty(actual: $tool['name']);
            $this->assertNotEmpty(actual: $tool['description']);

            $this->assertIsArray(actual: $tool['inputSchema']);
            $this->assertSame(expected: 'object', actual: $tool['inputSchema']['type']);
            $this->assertArrayHasKey(key: 'properties', array: $tool['inputSchema']);
            $this->assertIsArray(actual: $tool['inputSchema']['properties']);
            $this->assertArrayHasKey(key: 'required', array: $tool['inputSchema']);
            $this->assertIsArray(actual: $tool['inputSchema']['required']);
        }
    }//end testGetToolsReturnsFullCatalogueWithValidDescriptors()

    /**
     * getTools() advertises every CRM tool alongside the pre-existing request tools.
     *
     * @return void
     */
    public function testGetToolsIncludesFullCrmSurface(): void
    {
        $ids = array_column(array: $this->buildProvider()->getTools(), column_key: 'id');

        $this->assertContains(needle: 'pipelinq.listRequests', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.getRequest', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.listClients', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.searchClients', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.getClient', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.listLeads', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.searchLeads', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.getLead', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.pipelineForecast', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.createLead', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.logContactmoment', haystack: $ids);
    }//end testGetToolsIncludesFullCrmSurface()

    /**
     * getRequest descriptor requires the id argument.
     *
     * @return void
     */
    public function testGetRequestDescriptorRequiresId(): void
    {
        $tools = $this->buildProvider()->getTools();
        $byId  = array_column(array: $tools, column_key: null, index_key: 'id');

        $this->assertArrayHasKey(key: 'pipelinq.getRequest', array: $byId);
        $this->assertContains(
            needle: 'id',
            haystack: $byId['pipelinq.getRequest']['inputSchema']['required']
        );
    }//end testGetRequestDescriptorRequiresId()

    /**
     * invokeTool() with an unknown id returns a structured error array (no throw).
     *
     * @return void
     */
    public function testInvokeUnknownToolReturnsErrorArray(): void
    {
        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.bogus', arguments: []);

        $this->assertIsArray(actual: $result);
        $this->assertArrayHasKey(key: 'error', array: $result);
        $this->assertIsArray(actual: $result['error']);
        $this->assertSame(expected: 'unknown_tool', actual: $result['error']['code']);
        $this->assertNotEmpty(actual: $result['error']['message']);
    }//end testInvokeUnknownToolReturnsErrorArray()

    // =========================================================================
    // Requests (pre-existing MVP tools — unchanged behaviour)
    // =========================================================================

    /**
     * invokeTool('pipelinq.getRequest') without an id returns an invalid_arguments error.
     *
     * @return void
     */
    public function testGetRequestWithoutIdReturnsInvalidArguments(): void
    {
        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.getRequest', arguments: []);

        $this->assertIsArray(actual: $result);
        $this->assertArrayHasKey(key: 'error', array: $result);
        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testGetRequestWithoutIdReturnsInvalidArguments()

    /**
     * invokeTool('pipelinq.listRequests') with an out-of-range limit returns invalid_arguments.
     *
     * @return void
     */
    public function testListRequestsWithBadLimitReturnsInvalidArguments(): void
    {
        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.listRequests',
            arguments: ['limit' => 999]
        );

        $this->assertIsArray(actual: $result);
        $this->assertArrayHasKey(key: 'error', array: $result);
        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testListRequestsWithBadLimitReturnsInvalidArguments()

    /**
     * invokeTool('pipelinq.listRequests') returns not_configured when the
     * register / unified ticket schema are unset.
     *
     * @return void
     */
    public function testListRequestsWithoutConfigReturnsNotConfigured(): void
    {
        $this->ticketService->method('isConfigured')->willReturn(false);

        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.listRequests', arguments: []);

        $this->assertIsArray(actual: $result);
        $this->assertArrayHasKey(key: 'error', array: $result);
        $this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
    }//end testListRequestsWithoutConfigReturnsNotConfigured()

    /**
     * invokeTool('pipelinq.listRequests') queries the unified ticket schema and
     * narrows on the `ticketType: request` discriminator (unify-ticket-supertype)
     * rather than a dedicated `request_schema`.
     *
     * @return void
     */
    public function testListRequestsQueriesTicketSchemaWithDiscriminator(): void
    {
        $this->ticketService->method('isConfigured')->willReturn(true);
        $this->ticketService->method('getRegisterId')->willReturn('reg-1');
        $this->ticketService->method('getSchemaId')->willReturn('sch-ticket');

        $captured      = [];
        $objectService = $this->mockObjectService();
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$captured): array {
                $captured = $config;
                return [['uuid' => 'tkt-1', 'ticketType' => 'request']];
            }
        );

        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.listRequests', arguments: []);

        $this->assertSame(expected: 1, actual: $result['count']);
        $this->assertSame(expected: 'sch-ticket', actual: $captured['filters']['schema']);
        $this->assertSame(expected: 'request', actual: $captured['filters']['ticketType']);
    }//end testListRequestsQueriesTicketSchemaWithDiscriminator()

    /**
     * invokeTool('pipelinq.getRequest') on a ticket that is not a request
     * (e.g. a complaint sharing the unified schema) reports not_found.
     *
     * @return void
     */
    public function testGetRequestRejectsNonRequestTicket(): void
    {
        $this->ticketService->method('isConfigured')->willReturn(true);
        $this->ticketService->method('getRegisterId')->willReturn('reg-1');
        $this->ticketService->method('getSchemaId')->willReturn('sch-ticket');

        $objectService = $this->mockObjectService();
        $objectService->method('find')->willReturn(['uuid' => 'tkt-2', 'ticketType' => 'complaint']);

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.getRequest',
            arguments: ['id' => 'tkt-2']
        );

        $this->assertArrayHasKey(key: 'error', array: $result);
        $this->assertSame(expected: 'not_found', actual: $result['error']['code']);
    }//end testGetRequestRejectsNonRequestTicket()

    // =========================================================================
    // Clients
    // =========================================================================

    /**
     * invokeTool('pipelinq.listClients') returns not_configured when the
     * register / client schema are unset.
     *
     * @return void
     */
    public function testListClientsWithoutConfigReturnsNotConfigured(): void
    {
        $this->stubAppConfig(values: []);

        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.listClients', arguments: []);

        $this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
    }//end testListClientsWithoutConfigReturnsNotConfigured()

    /**
     * invokeTool('pipelinq.listClients') queries the configured client schema
     * (RBAC enabled via ObjectService->findAll) and applies an optional type filter.
     *
     * @return void
     */
    public function testListClientsQueriesClientSchemaAndAppliesTypeFilter(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'client_schema' => 'sch-client']);

        $captured      = [];
        $objectService = $this->mockObjectService();
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$captured): array {
                $captured = $config;
                return [['name' => 'Gemeente Voorbeeld', 'type' => 'organization']];
            }
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.listClients',
            arguments: ['type' => 'organization']
        );

        $this->assertSame(expected: 1, actual: $result['count']);
        $this->assertSame(expected: 'sch-client', actual: $captured['filters']['schema']);
        $this->assertSame(expected: 'organization', actual: $captured['filters']['type']);
    }//end testListClientsQueriesClientSchemaAndAppliesTypeFilter()

    /**
     * invokeTool('pipelinq.searchClients') without a query returns invalid_arguments.
     *
     * @return void
     */
    public function testSearchClientsWithoutQueryReturnsInvalidArguments(): void
    {
        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.searchClients', arguments: []);

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testSearchClientsWithoutQueryReturnsInvalidArguments()

    /**
     * invokeTool('pipelinq.searchClients') matches case-insensitively against
     * name/email, RBAC-scoped (only what ObjectService returns is searched).
     *
     * @return void
     */
    public function testSearchClientsFiltersByNameOrEmailCaseInsensitively(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'client_schema' => 'sch-client']);

        $objectService = $this->mockObjectService();
        $objectService->method('findAll')->willReturn(
            [
                ['name' => 'Gemeente Voorbeeld', 'email' => 'info@voorbeeld.nl', 'type' => 'organization'],
                ['name' => 'Meridiaan Advies B.V.', 'email' => 'contact@meridiaan.nl', 'type' => 'organization'],
            ]
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.searchClients',
            arguments: ['query' => 'GEMEENTE']
        );

        $this->assertSame(expected: 1, actual: $result['count']);
        $this->assertSame(expected: 'Gemeente Voorbeeld', actual: $result['clients'][0]['name']);
    }//end testSearchClientsFiltersByNameOrEmailCaseInsensitively()

    /**
     * invokeTool('pipelinq.getClient') without an id returns invalid_arguments.
     *
     * @return void
     */
    public function testGetClientWithoutIdReturnsInvalidArguments(): void
    {
        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.getClient', arguments: []);

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testGetClientWithoutIdReturnsInvalidArguments()

    /**
     * invokeTool('pipelinq.getClient') on a missing id returns not_found —
     * never a partial/empty client object presented as success.
     *
     * @return void
     */
    public function testGetClientNotFoundReturnsNotFoundEnvelope(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'client_schema' => 'sch-client']);

        $objectService = $this->mockObjectService();
        $objectService->method('find')->willReturn(null);

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.getClient',
            arguments: ['id' => self::NIL_UUID]
        );

        $this->assertSame(expected: 'not_found', actual: $result['error']['code']);
    }//end testGetClientNotFoundReturnsNotFoundEnvelope()

    /**
     * invokeTool('pipelinq.getClient') denied by RBAC maps to a forbidden envelope.
     *
     * @return void
     */
    public function testGetClientDeniedByRbacReturnsForbidden(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'client_schema' => 'sch-client']);

        $objectService = $this->mockObjectService();
        $objectService->method('find')->willThrowException(
            new \Exception('User is not authorized to read this object (permission denied)')
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.getClient',
            arguments: ['id' => self::NIL_UUID]
        );

        $this->assertSame(expected: 'forbidden', actual: $result['error']['code']);
    }//end testGetClientDeniedByRbacReturnsForbidden()

    /**
     * invokeTool('pipelinq.getClient') returns the client plus a live 360
     * summary: open-ticket count across all ticketTypes, open-lead count +
     * total value, and recent contactmomenten via ActivityTimelineService.
     *
     * @return void
     */
    public function testGetClientReturnsRecordPlus360Summary(): void
    {
        $this->ticketService->method('isConfigured')->willReturn(true);
        $this->ticketService->method('getRegisterId')->willReturn('reg-1');
        $this->ticketService->method('getSchemaId')->willReturn('sch-ticket');

        $this->stubAppConfig(values: ['register' => 'reg-1', 'client_schema' => 'sch-client', 'lead_schema' => 'sch-lead']);

        $objectService = $this->mockObjectService();
        $objectService->method('find')->willReturn(
            ['id' => self::NIL_UUID, 'name' => 'Gemeente Voorbeeld', 'type' => 'organization']
        );
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config): array {
                $schema = $config['filters']['schema'] ?? '';

                if ($schema === 'sch-ticket') {
                    return [
                        ['id' => 'tkt-1', 'status' => 'new', 'client' => self::NIL_UUID],
                        ['id' => 'tkt-2', 'status' => 'closed', 'client' => self::NIL_UUID],
                    ];
                }

                if ($schema === 'sch-lead') {
                    return [['id' => 'lead-1', 'status' => 'open', 'value' => 45000, 'client' => self::NIL_UUID]];
                }

                return [];
            }
        );

        $this->timelineService->method('getTimeline')->willReturn(
            [
                'items' => [['type' => 'contactmoment', 'title' => 'Booking change request']],
                'total' => 1,
                'page'  => 1,
                'pages' => 1,
            ]
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.getClient',
            arguments: ['id' => self::NIL_UUID]
        );

        $this->assertArrayNotHasKey(key: 'error', array: $result);
        $this->assertSame(expected: 'Gemeente Voorbeeld', actual: $result['client']['name']);
        $this->assertSame(expected: 1, actual: $result['summary']['openTicketCount']);
        $this->assertSame(expected: 1, actual: $result['summary']['openLeadCount']);
        $this->assertSame(expected: 45000.0, actual: $result['summary']['openLeadValue']);
        $this->assertCount(expectedCount: 1, haystack: $result['summary']['recentContactmomenten']);
    }//end testGetClientReturnsRecordPlus360Summary()

    /**
     * A timeline aggregation failure degrades recentContactmomenten to an
     * empty list without failing the whole getClient read.
     *
     * @return void
     */
    public function testGetClientTimelineFailureDegradesToEmptyList(): void
    {
        $this->ticketService->method('isConfigured')->willReturn(true);
        $this->ticketService->method('getRegisterId')->willReturn('reg-1');
        $this->ticketService->method('getSchemaId')->willReturn('sch-ticket');

        $this->stubAppConfig(values: ['register' => 'reg-1', 'client_schema' => 'sch-client', 'lead_schema' => 'sch-lead']);

        $objectService = $this->mockObjectService();
        $objectService->method('find')->willReturn(['id' => self::NIL_UUID, 'name' => 'Zonnereizen', 'type' => 'organization']);
        $objectService->method('findAll')->willReturn([]);

        $this->timelineService->method('getTimeline')->willThrowException(new \RuntimeException('timeline aggregator down'));

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.getClient',
            arguments: ['id' => self::NIL_UUID]
        );

        $this->assertArrayNotHasKey(key: 'error', array: $result);
        $this->assertSame(expected: [], actual: $result['summary']['recentContactmomenten']);
    }//end testGetClientTimelineFailureDegradesToEmptyList()

    // =========================================================================
    // Leads
    // =========================================================================

    /**
     * invokeTool('pipelinq.listLeads') returns not_configured when the
     * register / lead schema are unset.
     *
     * @return void
     */
    public function testListLeadsWithoutConfigReturnsNotConfigured(): void
    {
        $this->stubAppConfig(values: []);

        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.listLeads', arguments: []);

        $this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
    }//end testListLeadsWithoutConfigReturnsNotConfigured()

    /**
     * invokeTool('pipelinq.listLeads') narrows by status, stage, and clientId
     * when supplied.
     *
     * @return void
     */
    public function testListLeadsAppliesStatusStageClientFilters(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

        $captured      = [];
        $objectService = $this->mockObjectService();
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$captured): array {
                $captured = $config;
                return [['title' => 'KCC self-service portal', 'status' => 'open', 'stage' => 'qualification']];
            }
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.listLeads',
            arguments: ['status' => 'open', 'stage' => 'qualification', 'clientId' => self::NIL_UUID]
        );

        $this->assertSame(expected: 1, actual: $result['count']);
        $this->assertSame(expected: 'open', actual: $captured['filters']['status']);
        $this->assertSame(expected: 'qualification', actual: $captured['filters']['stage']);
        $this->assertSame(expected: self::NIL_UUID, actual: $captured['filters']['client']);
    }//end testListLeadsAppliesStatusStageClientFilters()

    /**
     * invokeTool('pipelinq.searchLeads') without a query returns invalid_arguments.
     *
     * @return void
     */
    public function testSearchLeadsWithoutQueryReturnsInvalidArguments(): void
    {
        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.searchLeads', arguments: []);

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testSearchLeadsWithoutQueryReturnsInvalidArguments()

    /**
     * invokeTool('pipelinq.searchLeads') matches title, contact name/email,
     * and organisation, case-insensitively.
     *
     * @return void
     */
    public function testSearchLeadsMatchesTitleContactOrOrganisation(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

        $objectService = $this->mockObjectService();
        $objectService->method('findAll')->willReturn(
            [
                ['title' => 'KCC self-service portal', 'organisation' => 'Gemeente Voorbeeld'],
                ['title' => 'Data-governance retainer', 'organisation' => 'Meridiaan Advies B.V.'],
            ]
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.searchLeads',
            arguments: ['query' => 'governance']
        );

        $this->assertSame(expected: 1, actual: $result['count']);
        $this->assertSame(expected: 'Data-governance retainer', actual: $result['leads'][0]['title']);
    }//end testSearchLeadsMatchesTitleContactOrOrganisation()

    /**
     * invokeTool('pipelinq.getLead') on a missing id returns not_found.
     *
     * @return void
     */
    public function testGetLeadNotFoundReturnsNotFoundEnvelope(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

        $objectService = $this->mockObjectService();
        $objectService->method('find')->willReturn(null);

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.getLead',
            arguments: ['id' => self::NIL_UUID]
        );

        $this->assertSame(expected: 'not_found', actual: $result['error']['code']);
    }//end testGetLeadNotFoundReturnsNotFoundEnvelope()

    /**
     * invokeTool('pipelinq.getLead') denied by RBAC maps to a forbidden envelope.
     *
     * @return void
     */
    public function testGetLeadDeniedByRbacReturnsForbidden(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

        $objectService = $this->mockObjectService();
        $objectService->method('find')->willThrowException(new \Exception('permission denied'));

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.getLead',
            arguments: ['id' => self::NIL_UUID]
        );

        $this->assertSame(expected: 'forbidden', actual: $result['error']['code']);
    }//end testGetLeadDeniedByRbacReturnsForbidden()

    /**
     * invokeTool('pipelinq.getLead') returns the backend-computed
     * qualificationScore/weightedValue as read from OpenRegister, a
     * winProbability alias of `probability`, and the activity timeline.
     *
     * @return void
     */
    public function testGetLeadReturnsComputedFieldsAndTimeline(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

        $objectService = $this->mockObjectService();
        $objectService->method('find')->willReturn(
            [
                'id'                 => self::NIL_UUID,
                'title'              => 'KCC self-service portal',
                'value'              => 45000,
                'probability'        => 50,
                'qualificationScore' => 45,
                'weightedValue'      => 22500,
            ]
        );

        $this->timelineService->method('getTimeline')->willReturn(
            ['items' => [['type' => 'email', 'title' => 'Follow-up']], 'total' => 1, 'page' => 1, 'pages' => 1]
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.getLead',
            arguments: ['id' => self::NIL_UUID]
        );

        $this->assertArrayNotHasKey(key: 'error', array: $result);
        $this->assertSame(expected: 45, actual: $result['lead']['qualificationScore']);
        $this->assertSame(expected: 22500, actual: $result['lead']['weightedValue']);
        $this->assertSame(expected: 50, actual: $result['lead']['winProbability']);
        $this->assertCount(expectedCount: 1, haystack: $result['timeline']);
    }//end testGetLeadReturnsComputedFieldsAndTimeline()

    // =========================================================================
    // Pipeline forecast
    // =========================================================================

    /**
     * invokeTool('pipelinq.pipelineForecast') returns not_configured when the
     * register / lead schema are unset.
     *
     * @return void
     */
    public function testPipelineForecastWithoutConfigReturnsNotConfigured(): void
    {
        $this->stubAppConfig(values: []);

        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.pipelineForecast', arguments: []);

        $this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
    }//end testPipelineForecastWithoutConfigReturnsNotConfigured()

    /**
     * invokeTool('pipelinq.pipelineForecast') reads only open leads, groups
     * by stage (ordered by stageOrder), sums value and the already-materialised
     * weightedValue (never recomputed), and returns a grand total.
     *
     * @return void
     */
    public function testPipelineForecastGroupsByStageAndSumsWeightedValue(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

        $captured      = [];
        $objectService = $this->mockObjectService();
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$captured): array {
                $captured = $config;
                return [
                    ['title' => 'Data-governance retainer', 'stage' => 'proposal', 'stageOrder' => 2, 'value' => 18000, 'weightedValue' => 9000],
                    ['title' => 'KCC self-service portal', 'stage' => 'qualification', 'stageOrder' => 1, 'value' => 45000, 'weightedValue' => 22500],
                ];
            }
        );

        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.pipelineForecast', arguments: []);

        $this->assertSame(expected: 'open', actual: $captured['filters']['status']);

        $this->assertSame(expected: 'qualification', actual: $result['stages'][0]['stage']);
        $this->assertSame(expected: 1, actual: $result['stages'][0]['leadCount']);
        $this->assertSame(expected: 45000.0, actual: $result['stages'][0]['value']);
        $this->assertSame(expected: 22500.0, actual: $result['stages'][0]['weightedValue']);

        $this->assertSame(expected: 'proposal', actual: $result['stages'][1]['stage']);
        $this->assertSame(expected: 18000.0, actual: $result['stages'][1]['value']);

        $this->assertSame(expected: 2, actual: $result['total']['leadCount']);
        $this->assertSame(expected: 63000.0, actual: $result['total']['value']);
        $this->assertSame(expected: 31500.0, actual: $result['total']['weightedValue']);
    }//end testPipelineForecastGroupsByStageAndSumsWeightedValue()

    // =========================================================================
    // Write: createLead
    // =========================================================================

    /**
     * invokeTool('pipelinq.createLead') without a title returns
     * invalid_arguments and writes nothing.
     *
     * @return void
     */
    public function testCreateLeadWithoutTitleReturnsInvalidArguments(): void
    {
        $objectService = $this->mockObjectService();
        $objectService->expects($this->never())->method('saveObject');

        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.createLead', arguments: []);

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testCreateLeadWithoutTitleReturnsInvalidArguments()

    /**
     * invokeTool('pipelinq.createLead') with a title writes through
     * ObjectService->saveObject on the configured lead schema and returns
     * the created lead including its server-computed qualificationScore.
     *
     * @return void
     */
    public function testCreateLeadWritesLeadAndReturnsQualificationScore(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

        $captured      = [];
        $objectService = $this->mockObjectService();
        $objectService->method('saveObject')->willReturnCallback(
            static function (array $object, ?array $extend, $register, $schema, ?string $uuid) use (&$captured): array {
                $captured = [
                    'object'   => $object,
                    'register' => $register,
                    'schema'   => $schema,
                    'uuid'     => $uuid,
                ];
                return array_merge($object, ['id' => self::NIL_UUID, 'qualificationScore' => 45]);
            }
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.createLead',
            arguments: [
                'title'  => 'KCC self-service portal',
                'client' => self::NIL_UUID,
                'value'  => 45000,
                'source' => 'referral',
            ]
        );

        $this->assertArrayNotHasKey(key: 'error', array: $result);
        $this->assertSame(expected: 'KCC self-service portal', actual: $captured['object']['title']);
        $this->assertSame(expected: self::NIL_UUID, actual: $captured['object']['client']);
        $this->assertSame(expected: 45000.0, actual: $captured['object']['value']);
        $this->assertSame(expected: 'referral', actual: $captured['object']['source']);
        $this->assertSame(expected: 'reg-1', actual: $captured['register']);
        $this->assertSame(expected: 'sch-lead', actual: $captured['schema']);
        $this->assertNull(actual: $captured['uuid']);
        $this->assertSame(expected: 45, actual: $result['lead']['qualificationScore']);
    }//end testCreateLeadWritesLeadAndReturnsQualificationScore()

    /**
     * invokeTool('pipelinq.createLead') denied by RBAC (no `create` permission
     * on the lead schema) maps to a forbidden envelope, not a success.
     *
     * @return void
     */
    public function testCreateLeadDeniedByRbacReturnsForbidden(): void
    {
        $this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

        $objectService = $this->mockObjectService();
        $objectService->method('saveObject')->willThrowException(
            new \Exception('User is not authorized to create this object (permission denied)')
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.createLead',
            arguments: ['title' => 'KCC self-service portal']
        );

        $this->assertSame(expected: 'forbidden', actual: $result['error']['code']);
    }//end testCreateLeadDeniedByRbacReturnsForbidden()

    // =========================================================================
    // Write: logContactmoment
    // =========================================================================

    /**
     * invokeTool('pipelinq.logContactmoment') without a client returns
     * invalid_arguments and writes nothing.
     *
     * @return void
     */
    public function testLogContactmomentMissingClientReturnsInvalidArguments(): void
    {
        $this->ticketService->expects($this->never())->method('save');

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.logContactmoment',
            arguments: ['channel' => 'telefoon', 'title' => 'Booking change request']
        );

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testLogContactmomentMissingClientReturnsInvalidArguments()

    /**
     * invokeTool('pipelinq.logContactmoment') without a channel returns
     * invalid_arguments and writes nothing.
     *
     * @return void
     */
    public function testLogContactmomentMissingChannelReturnsInvalidArguments(): void
    {
        $this->ticketService->expects($this->never())->method('save');

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.logContactmoment',
            arguments: ['client' => self::NIL_UUID, 'title' => 'Booking change request']
        );

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testLogContactmomentMissingChannelReturnsInvalidArguments()

    /**
     * invokeTool('pipelinq.logContactmoment') without a title returns
     * invalid_arguments and writes nothing.
     *
     * @return void
     */
    public function testLogContactmomentMissingTitleReturnsInvalidArguments(): void
    {
        $this->ticketService->expects($this->never())->method('save');

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.logContactmoment',
            arguments: ['client' => self::NIL_UUID, 'channel' => 'telefoon']
        );

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testLogContactmomentMissingTitleReturnsInvalidArguments()

    /**
     * invokeTool('pipelinq.logContactmoment') returns not_configured when the
     * ticket register/schema are unset, and writes nothing.
     *
     * @return void
     */
    public function testLogContactmomentWithoutConfigReturnsNotConfigured(): void
    {
        $this->ticketService->method('isConfigured')->willReturn(false);
        $this->ticketService->expects($this->never())->method('save');

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.logContactmoment',
            arguments: ['client' => self::NIL_UUID, 'channel' => 'telefoon', 'title' => 'Booking change request']
        );

        $this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
    }//end testLogContactmomentWithoutConfigReturnsNotConfigured()

    /**
     * invokeTool('pipelinq.logContactmoment') writes a ticket with
     * ticketType=contactmoment via TicketService::save and returns the
     * created ticket UUID.
     *
     * @return void
     */
    public function testLogContactmomentWritesTicketAndReturnsUuid(): void
    {
        $this->ticketService->method('isConfigured')->willReturn(true);

        $capturedType    = null;
        $capturedPayload = [];
        $this->ticketService->method('save')->willReturnCallback(
            function (string $ticketType, array $payload) use (&$capturedType, &$capturedPayload): object {
                $capturedType    = $ticketType;
                $capturedPayload = $payload;
                return (object) array_merge($payload, ['id' => self::NIL_UUID]);
            }
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.logContactmoment',
            arguments: [
                'client'  => self::NIL_UUID,
                'channel' => 'telefoon',
                'title'   => 'Booking change request',
                'outcome' => 'afgehandeld',
                'notes'   => 'Customer wants to reschedule.',
            ]
        );

        $this->assertArrayNotHasKey(key: 'error', array: $result);
        $this->assertSame(expected: TicketService::TYPE_CONTACTMOMENT, actual: $capturedType);
        $this->assertSame(expected: self::NIL_UUID, actual: $capturedPayload['client']);
        $this->assertSame(expected: 'telefoon', actual: $capturedPayload['channel']);
        $this->assertSame(expected: 'Booking change request', actual: $capturedPayload['title']);
        $this->assertSame(expected: 'afgehandeld', actual: $capturedPayload['outcome']);
        $this->assertSame(expected: 'Customer wants to reschedule.', actual: $capturedPayload['description']);
        $this->assertSame(expected: self::NIL_UUID, actual: $result['ticketId']);
    }//end testLogContactmomentWritesTicketAndReturnsUuid()

    /**
     * invokeTool('pipelinq.logContactmoment') denied by RBAC maps to a
     * forbidden envelope, not a success.
     *
     * @return void
     */
    public function testLogContactmomentDeniedByRbacReturnsForbidden(): void
    {
        $this->ticketService->method('isConfigured')->willReturn(true);
        $this->ticketService->method('save')->willThrowException(
            new \Exception('User is not authorized to create this object (permission denied)')
        );

        $result = $this->buildProvider()->invokeTool(
            toolId: 'pipelinq.logContactmoment',
            arguments: ['client' => self::NIL_UUID, 'channel' => 'telefoon', 'title' => 'Booking change request']
        );

        $this->assertSame(expected: 'forbidden', actual: $result['error']['code']);
    }//end testLogContactmomentDeniedByRbacReturnsForbidden()
}//end class
