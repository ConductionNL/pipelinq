<?php

/**
 * Unit tests for LeadService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\LeadService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use OCP\IAppConfig;

/**
 * Tests for LeadService.
 *
 * Behaviour cases are ported from the deleted `PipelinqToolProviderTest`
 * (pipelinq.createLead / pipelinq.pipelineForecast sections) — the only
 * change is that `createLead()` no longer aliases `winProbability`
 * (pipelinq #381, resolved by `plq-mcp-provider-surgery`).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LeadServiceTest extends TestCase
{

    /**
     * Nil UUID used for every client fixture reference.
     *
     * @var string
     */
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * The DI container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * The app config mock (register / lead_schema resolution).
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
        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $this->logger    = $this->createMock(originalClassName: LoggerInterface::class);
    }//end setUp()

    /**
     * Build the service under test.
     *
     * @return LeadService
     */
    private function buildService(): LeadService
    {
        return new LeadService(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }//end buildService()

    /**
     * Stub IAppConfig::getValueString with a fixed key => value map; any key
     * not in the map resolves to the caller's default (empty string).
     *
     * @param array<string, string> $values Map of app-config key => value.
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
    // #[McpTool] attribute presence
    // =========================================================================

    /**
     * createLead() carries #[McpTool] with the expected name and description.
     *
     * @return void
     */
    public function testCreateLeadHasMcpToolAttribute(): void
    {
        $method     = new ReflectionMethod(LeadService::class, 'createLead');
        $attributes = $method->getAttributes(McpTool::class);

        $this->assertCount(expectedCount: 1, haystack: $attributes);

        /** @var McpTool $instance */
        $instance = $attributes[0]->newInstance();
        $this->assertSame(expected: 'createLead', actual: $instance->name);
        $this->assertStringContainsString(needle: 'title', haystack: (string) $instance->description);
        $this->assertTrue(condition: $method->isPublic());
    }//end testCreateLeadHasMcpToolAttribute()

    /**
     * pipelineForecast() carries #[McpTool] with the expected name and description.
     *
     * @return void
     */
    public function testPipelineForecastHasMcpToolAttribute(): void
    {
        $method     = new ReflectionMethod(LeadService::class, 'pipelineForecast');
        $attributes = $method->getAttributes(McpTool::class);

        $this->assertCount(expectedCount: 1, haystack: $attributes);

        /** @var McpTool $instance */
        $instance = $attributes[0]->newInstance();
        $this->assertSame(expected: 'pipelineForecast', actual: $instance->name);
        $this->assertStringContainsString(needle: 'stage', haystack: (string) $instance->description);
        $this->assertTrue(condition: $method->isPublic());
    }//end testPipelineForecastHasMcpToolAttribute()

    // =========================================================================
    // pipelineForecast()
    // =========================================================================

    /**
     * pipelineForecast() returns not_configured when the register / lead
     * schema are unset.
     *
     * @return void
     */
    public function testPipelineForecastWithoutConfigReturnsNotConfigured(): void
    {
        $this->stubAppConfig(values: []);

        $result = $this->buildService()->pipelineForecast();

        $this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
    }//end testPipelineForecastWithoutConfigReturnsNotConfigured()

    /**
     * pipelineForecast() reads only open leads, groups by stage (ordered by
     * stageOrder), sums value and the already-materialised weightedValue
     * (never recomputed), and returns a grand total.
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

        $result = $this->buildService()->pipelineForecast();

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
    // createLead()
    // =========================================================================

    /**
     * createLead() without a title returns invalid_arguments and writes nothing.
     *
     * @return void
     */
    public function testCreateLeadWithoutTitleReturnsInvalidArguments(): void
    {
        $objectService = $this->mockObjectService();
        $objectService->expects($this->never())->method('saveObject');

        $result = $this->buildService()->createLead(title: '');

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testCreateLeadWithoutTitleReturnsInvalidArguments()

    /**
     * createLead() with a title writes through ObjectService->saveObject on
     * the configured lead schema and returns the created lead including its
     * server-computed qualificationScore and declarative winProbability,
     * unmodified by any alias (pipelinq #381).
     *
     * @return void
     */
    public function testCreateLeadWritesLeadAndReturnsMaterialisedCalculations(): void
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
                return array_merge($object, ['id' => self::NIL_UUID, 'qualificationScore' => 45, 'winProbability' => 36.0]);
            }
        );

        $result = $this->buildService()->createLead(
            title: 'KCC self-service portal',
            client: self::NIL_UUID,
            value: 45000,
            source: 'referral',
        );

        $this->assertArrayNotHasKey(key: 'error', array: $result);
        $this->assertSame(expected: 'KCC self-service portal', actual: $captured['object']['title']);
        $this->assertSame(expected: self::NIL_UUID, actual: $captured['object']['client']);
        $this->assertSame(expected: 45000.0, actual: $captured['object']['value']);
        $this->assertSame(expected: 'referral', actual: $captured['object']['source']);
        $this->assertArrayNotHasKey(key: 'winProbability', array: $captured['object']);
        $this->assertSame(expected: 'reg-1', actual: $captured['register']);
        $this->assertSame(expected: 'sch-lead', actual: $captured['schema']);
        $this->assertNull(actual: $captured['uuid']);
        $this->assertSame(expected: 45, actual: $result['lead']['qualificationScore']);
        $this->assertSame(expected: 36.0, actual: $result['lead']['winProbability']);
    }//end testCreateLeadWritesLeadAndReturnsMaterialisedCalculations()

    /**
     * createLead() denied by RBAC (no `create` permission on the lead
     * schema) maps to a forbidden envelope, not a success.
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

        $result = $this->buildService()->createLead(title: 'KCC self-service portal');

        $this->assertSame(expected: 'forbidden', actual: $result['error']['code']);
    }//end testCreateLeadDeniedByRbacReturnsForbidden()
}//end class
