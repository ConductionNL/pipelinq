<?php

/**
 * Unit tests for MessagingService.
 *
 * Covers the outcome sanitisation (no raw vendor error leaks), channel
 * availability, connectivity-test degradation and the per-object contact
 * guard of the outbound send surface.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/specs/outbound-messaging/spec.md#requirement-req-om-004--server-side-send-endpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\MessagingService;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * MessagingService unit coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MessagingServiceTest extends TestCase
{
    private ChannelProviderRepository $providerRepo;
    private SmsAdapter $smsAdapter;
    private WhatsAppAdapter $whatsAppAdapter;
    private ConsentService $consentService;
    private MessagingService $service;

    /**
     * Build the service with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->providerRepo    = $this->createMock(ChannelProviderRepository::class);
        $this->smsAdapter      = $this->createMock(SmsAdapter::class);
        $this->whatsAppAdapter = $this->createMock(WhatsAppAdapter::class);
        $this->consentService  = $this->createMock(ConsentService::class);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return $default;
            }
        );

        $this->service = new MessagingService(
            $this->createMock(ContainerInterface::class),
            $appConfig,
            $this->providerRepo,
            $this->smsAdapter,
            $this->whatsAppAdapter,
            $this->consentService,
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * A sent SMS outcome is sanitised to {status: sent, messageId}.
     *
     * @return void
     */
    public function testSendSmsSentSanitised(): void
    {
        $this->smsAdapter->method('send')->willReturn(['status' => 'sent', 'messageId' => 'm1', 'vendor' => 'messagebird']);

        $outcome = $this->service->send(
            contact: ['uuid' => 'c1', 'phoneNumber' => '+31611111111'],
            channel: 'sms',
            body: 'hi',
            templateId: null,
            parameters: [],
            providerHint: null,
            actor: 'agent-1'
        );

        $this->assertSame('sent', $outcome['status']);
        $this->assertSame('m1', $outcome['messageId']);
        $this->assertArrayNotHasKey('vendor', $outcome);
    }//end testSendSmsSentSanitised()

    /**
     * A failed outcome never leaks the raw vendor error string.
     *
     * @return void
     */
    public function testSendFailedHidesVendorError(): void
    {
        $this->smsAdapter->method('send')->willReturn(['status' => 'failed', 'error' => 'HTTP 500 from rest.messagebird.com']);

        $outcome = $this->service->send(
            contact: ['uuid' => 'c1'],
            channel: 'sms',
            body: 'hi',
            templateId: null,
            parameters: [],
            providerHint: null,
            actor: 'agent-1'
        );

        $this->assertSame('failed', $outcome['status']);
        $this->assertArrayNotHasKey('error', $outcome);
    }//end testSendFailedHidesVendorError()

    /**
     * A WhatsApp session-window-expired outcome maps to template-required.
     *
     * @return void
     */
    public function testSendWhatsAppSessionExpiredMapsTemplateRequired(): void
    {
        $this->whatsAppAdapter->method('send')->willReturn(['status' => 'sessionWindowExpired']);

        $outcome = $this->service->send(
            contact: ['uuid' => 'c1'],
            channel: 'whatsapp',
            body: 'hi',
            templateId: null,
            parameters: [],
            providerHint: null,
            actor: 'agent-1'
        );

        $this->assertSame('template-required', $outcome['status']);
    }//end testSendWhatsAppSessionExpiredMapsTemplateRequired()

    /**
     * A template mismatch maps to template-invalid with the reason.
     *
     * @return void
     */
    public function testSendTemplateMismatchMapsTemplateInvalid(): void
    {
        $this->whatsAppAdapter->method('send')->willReturn(['status' => 'templateParameterMismatch']);

        $outcome = $this->service->send(
            contact: ['uuid' => 'c1'],
            channel: 'whatsapp',
            body: '',
            templateId: 'tpl-1',
            parameters: [],
            providerHint: null,
            actor: 'agent-1'
        );

        $this->assertSame('template-invalid', $outcome['status']);
        $this->assertSame('templateParameterMismatch', $outcome['reason']);
    }//end testSendTemplateMismatchMapsTemplateInvalid()

    /**
     * availableChannels reflects the active providers per kind.
     *
     * @return void
     */
    public function testAvailableChannels(): void
    {
        $this->providerRepo->method('listActive')->willReturnCallback(
            static function (string $kind): array {
                if ($kind === 'sms') {
                    return [['uuid' => 'p1']];
                }

                return [];
            }
        );

        $channels = $this->service->availableChannels();
        $this->assertTrue($channels['sms']);
        $this->assertFalse($channels['whatsapp']);
    }//end testAvailableChannels()

    /**
     * A provider with no sourceId reports not-reachable with a clean cause.
     *
     * @return void
     */
    public function testProviderTestNoSource(): void
    {
        $result = $this->service->runProviderTest(provider: ['uuid' => 'p1']);
        $this->assertFalse($result['reachable']);
        $this->assertSame('no-source-configured', $result['cause']);
    }//end testProviderTestNoSource()

    /**
     * loadContact returns null when OpenRegister is absent (guard denies).
     *
     * @return void
     */
    public function testLoadContactNullWithoutOr(): void
    {
        $this->assertNull($this->service->loadContact(contactId: 'c1'));
    }//end testLoadContactNullWithoutOr()
}//end class
