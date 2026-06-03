<?php

/**
 * Unit tests for SmsService orchestration.
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
use OCA\Pipelinq\Service\Messaging\ChannelProviderInterface;
use OCA\Pipelinq\Service\Messaging\ProviderConfigService;
use OCA\Pipelinq\Service\Messaging\SendResult;
use OCA\Pipelinq\Service\MessagingContactResolver;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\SmsService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the SMS send/receive orchestrator.
 */
class SmsServiceTest extends TestCase
{
    /**
     * Mock provider config.
     *
     * @var ProviderConfigService
     */
    private ProviderConfigService $providerConfig;

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
     * @var SmsService
     */
    private SmsService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->providerConfig  = $this->createMock(ProviderConfigService::class);
        $this->consentService  = $this->createMock(ConsentService::class);
        $this->budgetService   = $this->createMock(BudgetService::class);
        $this->messageLog      = $this->createMock(MessageLogService::class);
        $this->contactResolver = $this->createMock(MessagingContactResolver::class);

        $this->service = new SmsService(
            $this->providerConfig,
            $this->consentService,
            $this->budgetService,
            $this->messageLog,
            $this->contactResolver,
            $this->createMock(NotificationService::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IEventDispatcher::class),
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * A successful send via the priority-1 provider returns sent.
     *
     * @return void
     */
    public function testSendSucceedsViaPriorityProvider(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(true);
        $this->budgetService->method('canSend')->willReturn(true);
        $this->messageLog->method('log')->willReturn(['@self' => ['id' => 'sms-1']]);
        $this->providerConfig->method('activeProviders')->willReturn([['vendor' => 'messagebird']]);
        $this->providerConfig->method('providerId')->willReturn('p1');

        $client = $this->createMock(ChannelProviderInterface::class);
        $client->method('sendFreeForm')->willReturn(SendResult::ok('mb-1'));
        $this->providerConfig->method('buildClient')->willReturn($client);

        $result = $this->service->send('c1', 'Uw afspraak is bevestigd');

        $this->assertTrue($result->success);
        $this->assertSame('mb-1', $result->externalMessageId);
    }//end testSendSucceedsViaPriorityProvider()

    /**
     * A 5xx on the primary fails over to the next provider in the same call.
     *
     * @return void
     */
    public function testFailoverOnTransientError(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(true);
        $this->budgetService->method('canSend')->willReturn(true);
        $this->messageLog->method('log')->willReturn(['@self' => ['id' => 'sms-2']]);
        $this->providerConfig->method('activeProviders')->willReturn([['vendor' => 'messagebird'], ['vendor' => 'twilio']]);
        $this->providerConfig->method('providerId')->willReturnOnConsecutiveCalls('p1', 'p2');

        $primary = $this->createMock(ChannelProviderInterface::class);
        $primary->method('sendFreeForm')->willReturn(SendResult::transient('provider_transient'));
        $fallback = $this->createMock(ChannelProviderInterface::class);
        $fallback->method('sendFreeForm')->willReturn(SendResult::ok('tw-1'));
        $this->providerConfig->method('buildClient')->willReturnOnConsecutiveCalls($primary, $fallback);

        $result = $this->service->send('c1', 'Hoi');

        $this->assertTrue($result->success);
        $this->assertSame('tw-1', $result->externalMessageId);
    }//end testFailoverOnTransientError()

    /**
     * When all providers fail, the message is persisted failed and the call fails.
     *
     * @return void
     */
    public function testAllProvidersFail(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(true);
        $this->budgetService->method('canSend')->willReturn(true);
        $this->providerConfig->method('activeProviders')->willReturn([['vendor' => 'messagebird'], ['vendor' => 'twilio']]);
        $this->providerConfig->method('providerId')->willReturnOnConsecutiveCalls('p1', 'p2');

        $client = $this->createMock(ChannelProviderInterface::class);
        $client->method('sendFreeForm')->willReturn(SendResult::transient('provider_transient'));
        $this->providerConfig->method('buildClient')->willReturn($client);

        // The failed message must be persisted with deliveryStatus failed.
        $this->messageLog->expects($this->once())
            ->method('log')
            ->with($this->callback(static fn(array $f): bool => ($f['deliveryStatus'] ?? '') === 'failed'))
            ->willReturn(['@self' => ['id' => 'sms-fail']]);

        $result = $this->service->send('c1', 'Hoi');

        $this->assertFalse($result->success);
        $this->assertSame(Http::STATUS_BAD_GATEWAY, $result->statusCode);
    }//end testAllProvidersFail()

    /**
     * A caller-pinned provider hint uses that vendor directly (no failover).
     *
     * @return void
     */
    public function testProviderHintPinsVendor(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(true);
        $this->budgetService->method('canSend')->willReturn(true);
        $this->messageLog->method('log')->willReturn(['@self' => ['id' => 'sms-3']]);
        $this->providerConfig->method('activeProviders')->willReturn(
            [['vendor' => 'messagebird'], ['vendor' => 'twilio'], ['vendor' => 'cm-com']]
        );
        $this->providerConfig->method('providerId')->willReturn('p3');

        $client = $this->createMock(ChannelProviderInterface::class);
        $client->expects($this->once())->method('sendFreeForm')->willReturn(SendResult::ok('cm-1'));
        $this->providerConfig->method('buildClient')->willReturn($client);

        $result = $this->service->send('c1', 'Hoi', 'cm-com');

        $this->assertTrue($result->success);
        $this->assertSame('cm-1', $result->externalMessageId);
    }//end testProviderHintPinsVendor()

    /**
     * An opted-out contact is refused with consentMissing.
     *
     * @return void
     */
    public function testBlockedByConsent(): void
    {
        $this->contactResolver->method('phoneForContact')->willReturn('+31699998888');
        $this->consentService->method('canSend')->willReturn(false);

        $result = $this->service->send('c1', 'Hoi');

        $this->assertSame(Http::STATUS_FORBIDDEN, $result->statusCode);
        $this->assertSame('consentMissing', $result->errorCode);
    }//end testBlockedByConsent()

    /**
     * Inbound SMS persists and routes; STOP records opt-out.
     *
     * @return void
     */
    public function testInboundStopRecordsOptOut(): void
    {
        $this->contactResolver->method('resolveForInbound')->willReturn(['contactId' => 'c1', 'created' => false]);
        $this->messageLog->method('log')->willReturn(['@self' => ['id' => 'in-1']]);
        $this->consentService->method('classifyKeyword')->willReturn('opt-out');

        $this->consentService->expects($this->once())
            ->method('recordOptOut')
            ->with('c1', 'sms', 'keyword-stop', $this->anything());

        $inbound = new \OCA\Pipelinq\Service\Messaging\InboundMessage('sms', '31699998888', '31611112222', 'stop');
        $messageId = $this->service->handleInbound($inbound, 'p1');

        $this->assertSame('in-1', $messageId);
    }//end testInboundStopRecordsOptOut()
}//end class
