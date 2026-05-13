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

use OCA\Pipelinq\Mcp\PipelinqToolProvider;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PipelinqToolProvider.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PipelinqToolProviderTest extends TestCase
{

    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

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
        $this->appConfig       = $this->createMock(originalClassName: IAppConfig::class);
        $this->container       = $this->createMock(originalClassName: ContainerInterface::class);
        $this->timelineService = $this->createMock(originalClassName: ActivityTimelineService::class);
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
            appConfig: $this->appConfig,
            container: $this->container,
            timelineService: $this->timelineService,
            logger: $this->logger,
        );
    }//end buildProvider()

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
     * getTools() returns exactly the two MVP descriptors with valid shapes.
     *
     * @return void
     */
    public function testGetToolsReturnsTwoValidDescriptors(): void
    {
        $tools = $this->buildProvider()->getTools();

        $this->assertCount(expectedCount: 2, haystack: $tools);

        $ids = array_column(array: $tools, column_key: 'id');
        $this->assertContains(needle: 'pipelinq.listRequests', haystack: $ids);
        $this->assertContains(needle: 'pipelinq.getRequest', haystack: $ids);

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
    }//end testGetToolsReturnsTwoValidDescriptors()

    /**
     * getRequest descriptor requires the id argument.
     *
     * @return void
     */
    public function testGetRequestDescriptorRequiresId(): void
    {
        $tools  = $this->buildProvider()->getTools();
        $byId   = array_column(array: $tools, column_key: null, index_key: 'id');

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
     * invokeTool('pipelinq.listRequests') returns not_configured when the register/schema are unset.
     *
     * @return void
     */
    public function testListRequestsWithoutConfigReturnsNotConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $result = $this->buildProvider()->invokeTool(toolId: 'pipelinq.listRequests', arguments: []);

        $this->assertIsArray(actual: $result);
        $this->assertArrayHasKey(key: 'error', array: $result);
        $this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
    }//end testListRequestsWithoutConfigReturnsNotConfigured()
}//end class
