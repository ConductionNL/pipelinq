<?php

/**
 * Unit tests for WhatsAppService orchestration.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\MessageLogService;
use OCA\Pipelinq\Service\Messaging\ProviderConfigService;
use OCA\Pipelinq\Service\Messaging\SendResult;
use OCA\Pipelinq\Service\MessagingContactResolver;
use OCA\Pipelinq\Service\TemplateService;
use OCA\Pipelinq\Service\WhatsAppService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the WhatsApp send/receive orchestrator.
 */
class WhatsAppServiceTest extends TestCase
{
    /**
     * Mock provider config.
     *
     * @var ProviderConfigService
     */
    private ProviderConfigService $providerConfig;

    /**
     * Mock template service.
     *
     * @var TemplateService
     */
    private TemplateService $templateService;

    /**
     * Mock consent service.
     *
     * @var ConsentService
     */
    private ConsentService $consentService;

    /**
     * Mock budget service.
     *
     * @var BudgetService
     */
    private BudgetService $budgetService;

    /**
     * Mock message log.
     *
     * @var MessageLogService
     */
    private MessageLogService $messageLog;

    /**
     * Mock contact resolver.
     *
     * @var MessagingContactResolver
     */
    private MessagingContactResolver $contactResolver;

    /**
     * The service under test.
     *
     * @var WhatsAppService
     */
    private WhatsAppService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->providerConfig  = $this->createMock(ProviderConfigService::class);
        $this->templateService = $this->createMock(TemplateService::class);
        $this->consentService  = $this->createMock(ConsentService::class);
        $this->budgetService   = $this->createMock(BudgetService::class);
        $this->messageLog      = $this->createMock(MessageLogService::class);
        $this->contactResolver = $this->createMock(MessagingContactResolver::class);

        $this->service = new WhatsAppService(
            $this->providerConfig,
            $this->templateService,
            $this->consentService,
            $this->budgetService,
            $this->messageLog,
            $this->contactResolver,
            $this->createMock(IEventDispatcher::class),
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * An opted-out contact is refused with consentMissing (403).
     *
     * @return void
     */
    public function testTemplateSendBlockedByConsent(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(false);

        $result = $this->service->sendTemplate('c1', 'tpl-1', []);

        $this->assertFalse($result->success);
        $this->assertSame(Http::STATUS_FORBIDDEN, $result->statusCode);
        $this->assertSame('consentMissing', $result->errorCode);
    }//end testTemplateSendBlockedByConsent()

    /**
     * A pending template is refused with templateNotApproved (422).
     *
     * @return void
     */
    public function testTemplateNotApproved(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(true);
        $this->templateService->method('find')->willReturn(['externalId' => 'x', 'status' => 'pending', 'body' => 'Hoi']);
        $this->templateService->method('isApproved')->willReturn(false);

        $result = $this->service->sendTemplate('c1', 'tpl-1', []);

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->statusCode);
        $this->assertSame('templateNotApproved', $result->errorCode);
    }//end testTemplateNotApproved()

    /**
     * A parameter-count mismatch is refused with detail (422).
     *
     * @return void
     */
    public function testTemplateParameterMismatch(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(true);
        $this->templateService->method('find')->willReturn(['externalId' => 'x', 'status' => 'approved', 'body' => '{{1}} {{2}} {{3}}']);
        $this->templateService->method('isApproved')->willReturn(true);
        $this->templateService->method('validateParameters')->willReturn(['valid' => false, 'expected' => 3, 'given' => 2]);

        $result = $this->service->sendTemplate('c1', 'tpl-1', ['a', 'b']);

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->statusCode);
        $this->assertSame('templateParameterMismatch', $result->errorCode);
        $this->assertSame(3, $result->detail['expected']);
        $this->assertSame(2, $result->detail['given']);
    }//end testTemplateParameterMismatch()

    /**
     * A budget breach refuses the send with budgetExceeded (403).
     *
     * @return void
     */
    public function testTemplateSendBlockedByBudget(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(true);
        $this->templateService->method('find')->willReturn(['externalId' => 'x', 'status' => 'approved', 'language' => 'nl', 'body' => 'Hoi']);
        $this->templateService->method('isApproved')->willReturn(true);
        $this->templateService->method('validateParameters')->willReturn(['valid' => true, 'expected' => 0, 'given' => 0]);
        $this->providerConfig->method('activeProviders')->willReturn([['vendor' => 'meta']]);
        $this->providerConfig->method('providerId')->willReturn('p1');
        $this->budgetService->method('canSend')->willReturn(false);

        $result = $this->service->sendTemplate('c1', 'tpl-1', []);

        $this->assertSame(Http::STATUS_FORBIDDEN, $result->statusCode);
        $this->assertSame('budgetExceeded', $result->errorCode);
    }//end testTemplateSendBlockedByBudget()

    /**
     * A free-form send outside the session window is refused (409).
     *
     * @return void
     */
    public function testFreeFormOutsideWindow(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(true);
        $this->messageLog->method('isWindowOpen')->willReturn(false);

        $result = $this->service->sendFreeForm('c1', 'Hallo nog even');

        $this->assertSame(Http::STATUS_CONFLICT, $result->statusCode);
        $this->assertSame('sessionWindowExpired', $result->errorCode);
    }//end testFreeFormOutsideWindow()

    /**
     * A free-form send within the window succeeds and is logged.
     *
     * @return void
     */
    public function testFreeFormWithinWindowSucceeds(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(true);
        $this->messageLog->method('isWindowOpen')->willReturn(true);
        $this->messageLog->method('log')->willReturn(['@self' => ['id' => 'msg-1']]);
        $this->providerConfig->method('activeProviders')->willReturn([['vendor' => 'meta']]);
        $this->providerConfig->method('providerId')->willReturn('p1');
        $this->budgetService->method('canSend')->willReturn(true);

        $client = $this->createMock(\OCA\Pipelinq\Service\Messaging\ChannelProviderInterface::class);
        $client->method('sendFreeForm')->willReturn(SendResult::ok('wamid.1'));
        $this->providerConfig->method('buildClient')->willReturn($client);

        $this->budgetService->expects($this->once())->method('recordSend');

        $result = $this->service->sendFreeForm('c1', 'Hallo');

        $this->assertTrue($result->success);
        $this->assertSame('wamid.1', $result->externalMessageId);
        $this->assertSame('msg-1', $result->messageId);
    }//end testFreeFormWithinWindowSucceeds()

    /**
     * Failover: a transient failure on the primary retries the next provider.
     *
     * @return void
     */
    public function testTemplateFailoverOnTransientError(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(true);
        $this->templateService->method('find')->willReturn(['externalId' => 'x', 'status' => 'approved', 'language' => 'nl', 'body' => 'Hoi']);
        $this->templateService->method('isApproved')->willReturn(true);
        $this->templateService->method('validateParameters')->willReturn(['valid' => true, 'expected' => 0, 'given' => 0]);
        $this->messageLog->method('log')->willReturn(['@self' => ['id' => 'msg-9']]);

        $this->providerConfig->method('activeProviders')->willReturn(
            [['vendor' => 'meta'], ['vendor' => 'twilio']]
        );
        $this->providerConfig->method('providerId')->willReturnOnConsecutiveCalls('p1', 'p2');
        $this->budgetService->method('canSend')->willReturn(true);

        $primary = $this->createMock(\OCA\Pipelinq\Service\Messaging\ChannelProviderInterface::class);
        $primary->method('sendTemplate')->willReturn(SendResult::transient('provider_transient'));
        $fallback = $this->createMock(\OCA\Pipelinq\Service\Messaging\ChannelProviderInterface::class);
        $fallback->method('sendTemplate')->willReturn(SendResult::ok('wamid.fb'));
        $this->providerConfig->method('buildClient')->willReturnOnConsecutiveCalls($primary, $fallback);

        $result = $this->service->sendTemplate('c1', 'tpl-1', []);

        $this->assertTrue($result->success);
        $this->assertSame('wamid.fb', $result->externalMessageId);
    }//end testTemplateFailoverOnTransientError()

    /**
     * Inbound from an unknown number creates a placeholder and persists.
     *
     * @return void
     */
    public function testInboundCreatesPlaceholderAndPersists(): void
    {
        $this->contactResolver->method('resolveForInbound')->willReturn(['contactId' => 'c-new', 'created' => true]);
        $this->messageLog->method('log')->willReturn(['@self' => ['id' => 'in-1']]);
        $this->consentService->method('classifyKeyword')->willReturn('none');

        $inbound = new \OCA\Pipelinq\Service\Messaging\InboundMessage('whatsapp', '31699998888', '31611112222', 'Hallo');
        $messageId = $this->service->handleInbound($inbound, 'p1');

        $this->assertSame('in-1', $messageId);
    }//end testInboundCreatesPlaceholderAndPersists()

    /**
     * Inbound STOP records an opt-out.
     *
     * @return void
     */
    public function testInboundStopRecordsOptOut(): void
    {
        $this->contactResolver->method('resolveForInbound')->willReturn(['contactId' => 'c1', 'created' => false]);
        $this->messageLog->method('log')->willReturn(['@self' => ['id' => 'in-2']]);
        $this->consentService->method('classifyKeyword')->willReturn('opt-out');

        $this->consentService->expects($this->once())
            ->method('recordOptOut')
            ->with('c1', 'whatsapp', 'keyword-stop', $this->anything());

        $inbound = new \OCA\Pipelinq\Service\Messaging\InboundMessage('whatsapp', '31699998888', '31611112222', 'STOP');
        $this->service->handleInbound($inbound, 'p1');
    }//end testInboundStopRecordsOptOut()
}//end class
