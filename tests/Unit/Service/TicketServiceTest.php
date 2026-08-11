<?php

/**
 * Unit tests for TicketService.
 *
 * Scope note: this file is new (no prior `TicketServiceTest` existed at HEAD
 * — TicketService was previously exercised only indirectly, via mocks in
 * other services' test suites). `plq-mcp-provider-surgery` adds it to cover
 * the migrated `logContactmoment()` `#[McpTool]` method; it does not attempt
 * a full retrofit of TicketService's pre-existing surface (find/save/
 * sanitizeForSave), which is out of this change's scope.
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
use OCA\Pipelinq\Service\TicketService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use OCP\IAppConfig;

/**
 * Tests for TicketService::logContactmoment().
 *
 * Behaviour cases are ported from the deleted `PipelinqToolProviderTest`
 * (pipelinq.logContactmoment section) — behaviour is unchanged, only the
 * call site moved from `PipelinqToolProvider::invokeTool()` dispatch to a
 * direct `#[McpTool]`-attributed method call.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TicketServiceTest extends TestCase
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
     * The app config mock (register / ticket_schema resolution).
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
     * @return TicketService
     */
    private function buildService(): TicketService
    {
        return new TicketService(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }//end buildService()

    /**
     * Stub IAppConfig::getValueString so isConfigured() is true.
     *
     * @return void
     */
    private function stubConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $appId, string $key, string $default = ''): string {
                $values = ['register' => 'reg-1', 'ticket_schema' => 'sch-ticket'];
                return $values[$key] ?? $default;
            }
        );
    }//end stubConfigured()

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
     * logContactmoment() carries #[McpTool] with the expected name and description.
     *
     * @return void
     */
    public function testLogContactmomentHasMcpToolAttribute(): void
    {
        $method     = new ReflectionMethod(TicketService::class, 'logContactmoment');
        $attributes = $method->getAttributes(McpTool::class);

        $this->assertCount(expectedCount: 1, haystack: $attributes);

        /** @var McpTool $instance */
        $instance = $attributes[0]->newInstance();
        $this->assertSame(expected: 'logContactmoment', actual: $instance->name);
        $this->assertStringContainsString(needle: 'contactmoment', haystack: (string) $instance->description);
        $this->assertTrue(condition: $method->isPublic());

        // ADR-063 hints/scope: save() is called with no uuid — always creates
        // a new ticket, never destructive, never idempotent.
        $this->assertFalse(condition: $instance->readOnlyHint);
        $this->assertFalse(condition: $instance->destructiveHint);
        $this->assertFalse(condition: $instance->idempotentHint);
        $this->assertSame(expected: 'create', actual: $instance->scope);
    }//end testLogContactmomentHasMcpToolAttribute()

    // =========================================================================
    // logContactmoment()
    // =========================================================================

    /**
     * logContactmoment() without a client returns invalid_arguments and writes nothing.
     *
     * @return void
     */
    public function testLogContactmomentMissingClientReturnsInvalidArguments(): void
    {
        $objectService = $this->mockObjectService();
        $objectService->expects($this->never())->method('saveObject');

        $result = $this->buildService()->logContactmoment(
            client: '',
            channel: 'telefoon',
            title: 'Booking change request'
        );

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testLogContactmomentMissingClientReturnsInvalidArguments()

    /**
     * logContactmoment() without a channel returns invalid_arguments and writes nothing.
     *
     * @return void
     */
    public function testLogContactmomentMissingChannelReturnsInvalidArguments(): void
    {
        $objectService = $this->mockObjectService();
        $objectService->expects($this->never())->method('saveObject');

        $result = $this->buildService()->logContactmoment(
            client: self::NIL_UUID,
            channel: '',
            title: 'Booking change request'
        );

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testLogContactmomentMissingChannelReturnsInvalidArguments()

    /**
     * logContactmoment() without a title returns invalid_arguments and writes nothing.
     *
     * @return void
     */
    public function testLogContactmomentMissingTitleReturnsInvalidArguments(): void
    {
        $objectService = $this->mockObjectService();
        $objectService->expects($this->never())->method('saveObject');

        $result = $this->buildService()->logContactmoment(
            client: self::NIL_UUID,
            channel: 'telefoon',
            title: ''
        );

        $this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
    }//end testLogContactmomentMissingTitleReturnsInvalidArguments()

    /**
     * logContactmoment() returns not_configured when the ticket register/schema
     * are unset, and writes nothing.
     *
     * @return void
     */
    public function testLogContactmomentWithoutConfigReturnsNotConfigured(): void
    {
        $objectService = $this->mockObjectService();
        $objectService->expects($this->never())->method('saveObject');

        $result = $this->buildService()->logContactmoment(
            client: self::NIL_UUID,
            channel: 'telefoon',
            title: 'Booking change request'
        );

        $this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
    }//end testLogContactmomentWithoutConfigReturnsNotConfigured()

    /**
     * logContactmoment() writes a ticket with ticketType=contactmoment via
     * ObjectService->saveObject and returns the created ticket UUID.
     *
     * @return void
     */
    public function testLogContactmomentWritesTicketAndReturnsUuid(): void
    {
        $this->stubConfigured();

        $captured      = [];
        $objectService = $this->mockObjectService();
        $objectService->method('saveObject')->willReturnCallback(
            static function (array $object) use (&$captured): object {
                $captured = $object;
                return (object) array_merge($object, ['id' => self::NIL_UUID]);
            }
        );

        $result = $this->buildService()->logContactmoment(
            client: self::NIL_UUID,
            channel: 'telefoon',
            title: 'Booking change request',
            outcome: 'afgehandeld',
            notes: 'Customer wants to reschedule.'
        );

        $this->assertArrayNotHasKey(key: 'error', array: $result);
        $this->assertSame(expected: TicketService::TYPE_CONTACTMOMENT, actual: $captured['ticketType']);
        $this->assertSame(expected: self::NIL_UUID, actual: $captured['client']);
        $this->assertSame(expected: 'telefoon', actual: $captured['channel']);
        $this->assertSame(expected: 'Booking change request', actual: $captured['title']);
        $this->assertSame(expected: 'afgehandeld', actual: $captured['outcome']);
        $this->assertSame(expected: 'Customer wants to reschedule.', actual: $captured['description']);
        $this->assertSame(expected: self::NIL_UUID, actual: $result['ticketId']);
    }//end testLogContactmomentWritesTicketAndReturnsUuid()

    /**
     * logContactmoment() denied by RBAC maps to a forbidden envelope, not a success.
     *
     * @return void
     */
    public function testLogContactmomentDeniedByRbacReturnsForbidden(): void
    {
        $this->stubConfigured();

        $objectService = $this->mockObjectService();
        $objectService->method('saveObject')->willThrowException(
            new \Exception('User is not authorized to create this object (permission denied)')
        );

        $result = $this->buildService()->logContactmoment(
            client: self::NIL_UUID,
            channel: 'telefoon',
            title: 'Booking change request'
        );

        $this->assertSame(expected: 'forbidden', actual: $result['error']['code']);
    }//end testLogContactmomentDeniedByRbacReturnsForbidden()

    /**
     * detectTypeInText maps the NL/EN words for a subtype onto its constant,
     * singular and plural alike, and returns null for text naming no subtype.
     *
     * @return void
     */
    public function testDetectTypeInTextRecognisesSubtypeVocabulary(): void
    {
        $service = $this->buildService();

        $this->assertSame(
            expected: TicketService::TYPE_REQUEST,
            actual: $service->detectTypeInText(text: 'How many requests are open?')
        );
        $this->assertSame(
            expected: TicketService::TYPE_REQUEST,
            actual: $service->detectTypeInText(text: 'Hoeveel verzoeken zijn er?')
        );
        $this->assertSame(
            expected: TicketService::TYPE_CONTACTMOMENT,
            actual: $service->detectTypeInText(text: 'How many contactmomenten were logged?')
        );
        $this->assertNull(actual: $service->detectTypeInText(text: 'How many leads are open?'));
        $this->assertNull(actual: $service->detectTypeInText(text: ''));
    }//end testDetectTypeInTextRecognisesSubtypeVocabulary()
}//end class
