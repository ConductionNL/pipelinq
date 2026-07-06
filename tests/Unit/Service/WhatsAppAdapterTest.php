<?php

/**
 * Unit tests for WhatsAppAdapter.
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
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCA\Pipelinq\Service\WhatsAppProviderClient;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WhatsAppAdapter — template lookup + parameter
 * validation, session-window enforcement, inbound signature
 * verification, placeholder contact creation.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.1
 */
class WhatsAppAdapterTest extends TestCase
{
    private ContainerInterface $container;
    private IAppConfig $appConfig;
    private ChannelProviderRepository $providerRepo;
    private WhatsAppProviderClient $providerClient;
    private ConsentService $consentService;
    private BudgetService $budgetService;
    private NotificationService $notificationService;
    private LoggerInterface $logger;
    private object $objectService;
    private WhatsAppAdapter $adapter;

    /**
     * setUp.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->providerRepo        = $this->createMock(ChannelProviderRepository::class);
        $this->providerClient      = $this->createMock(WhatsAppProviderClient::class);
        $this->consentService      = $this->createMock(ConsentService::class);
        $this->budgetService       = $this->createMock(BudgetService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->objectService = new class {
            /** @var array<string, array<string, mixed>> */
            public array $store = [];

            /** @var array<int, array<string, mixed>> */
            public array $inboundMessages = [];

            /**
             * Mock saveObject.
             *
             * @param array       $object   Payload.
             * @param mixed       $register Register.
             * @param mixed       $schema   Schema.
             * @param string|null $uuid     Id.
             *
             * @return array<string, mixed>
             */
            public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array
            {
                if ($uuid === null || $uuid === '') {
                    $uuid = (string) ($object['uuid'] ?? '');
                }
                if ($uuid === '') {
                    $uuid = ('row-' . count($this->store));
                }
                $object['uuid']       = $uuid;
                $this->store[$uuid]   = $object;
                return $object;
            }

            /**
             * Mock find.
             *
             * @param string $id       Id.
             * @param mixed  $register Register.
             * @param mixed  $schema   Schema.
             *
             * @return array<string, mixed>|null
             */
            public function find(string $id, $register = null, $schema = null): ?array
            {
                return ($this->store[$id] ?? null);
            }

            /**
             * Mock findAll. For 'inbound' filter return the seeded
             * inbound messages.
             *
             * @param array<string, mixed> $filters  Filters.
             * @param mixed                $register Register.
             * @param mixed                $schema   Schema.
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $filters = [], $register = null, $schema = null): array
            {
                if (($filters['direction'] ?? '') === 'inbound') {
                    return $this->inboundMessages;
                }
                return [];
            }
        };

        $this->container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $this->objectService;
                }
                throw new \RuntimeException('not registered: ' . $id);
            }
        );

        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default) {
                return match ($key) {
                    'register'                   => 'pipelinq',
                    'tenant_id'                  => 'tenant-1',
                    'whatsapp.default_language'  => 'nl',
                    default                      => $default,
                };
            }
        );

        $this->adapter = new WhatsAppAdapter(
            $this->container,
            $this->appConfig,
            $this->providerRepo,
            $this->providerClient,
            $this->consentService,
            $this->budgetService,
            $this->notificationService,
            $this->logger,
        );
    }//end setUp()

    /**
     * parseTemplatePlaceholders counts the distinct {{N}} positions.
     *
     * @return void
     */
    public function testParseTemplatePlaceholders(): void
    {
        $this->assertSame(0, $this->adapter->parseTemplatePlaceholders('plain text'));
        $this->assertSame(3, $this->adapter->parseTemplatePlaceholders('Beste {{1}}, op {{2}} om {{3}}'));
        // Duplicate placeholders count once.
        $this->assertSame(2, $this->adapter->parseTemplatePlaceholders('{{1}} {{2}} {{1}}'));
    }//end testParseTemplatePlaceholders()

    /**
     * Template with mismatched parameter count returns
     * templateParameterMismatch.
     *
     * @return void
     */
    public function testSendTemplateWithMismatchedParameters(): void
    {
        $template = [
            'uuid'       => 'tpl-1',
            'providerId' => 'prov-1',
            'status'     => 'approved',
            'externalId' => 'afspraak_bevestiging_nl',
            'language'   => 'nl',
            'body'       => 'Beste {{1}}, op {{2}} om {{3}}',
        ];
        $this->objectService->saveObject($template);

        // Template sends are business-initiated: they now require an
        // opted-in record (canSendBusinessInitiated), gated before the
        // parameter-count check reached below.
        $this->consentService->method('canSend')->willReturn(true);
        $this->consentService->method('canSendBusinessInitiated')->willReturn(true);
        $this->providerRepo->method('listActive')->willReturn([
            ['uuid' => 'prov-1', 'kind' => 'whatsapp-cloud-api', 'vendor' => 'meta'],
        ]);

        $result = $this->adapter->send(
            ['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
            '',
            'tpl-1',
            ['Jan', 'vrijdag'],
        );

        $this->assertSame('templateParameterMismatch', $result['status']);
        $this->assertSame(3, $result['expected']);
        $this->assertSame(2, $result['given']);
    }//end testSendTemplateWithMismatchedParameters()

    /**
     * Template with `status: pending` is refused.
     *
     * @return void
     */
    public function testSendRefusesPendingTemplate(): void
    {
        $template = [
            'uuid'       => 'tpl-2',
            'providerId' => 'prov-1',
            'status'     => 'pending',
            'externalId' => 'pending_tpl',
            'language'   => 'nl',
            'body'       => 'hi {{1}}',
        ];
        $this->objectService->saveObject($template);

        $this->consentService->method('canSend')->willReturn(true);
        $this->consentService->method('canSendBusinessInitiated')->willReturn(true);

        $result = $this->adapter->send(
            ['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
            '',
            'tpl-2',
            ['Jan'],
        );

        $this->assertSame('templateNotApproved', $result['status']);
    }//end testSendRefusesPendingTemplate()

    /**
     * Free-form send within the 24h session window succeeds.
     *
     * @return void
     */
    public function testSendFreeFormWithinSessionWindow(): void
    {
        $this->objectService->inboundMessages = [
            [
                'uuid'      => 'msg-1',
                'channel'   => 'whatsapp',
                'direction' => 'inbound',
                'sentAt'    => gmdate('Y-m-d\TH:i:s\Z', (time() - 3600)),
            ],
        ];

        $this->consentService->method('canSend')->willReturn(true);
        $this->budgetService->method('canSend')->willReturn(true);
        $this->providerRepo->method('listActive')->willReturn([
            ['uuid' => 'prov-1', 'kind' => 'whatsapp-cloud-api', 'vendor' => 'meta'],
        ]);

        $this->providerClient->method('sendFreeForm')
            ->willReturn(['externalMessageId' => 'wamid.1', 'vendor' => 'meta']);

        $result = $this->adapter->send(
            ['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
            'Hi there',
        );

        $this->assertSame('sent', $result['status']);
        $this->assertSame('wamid.1', $result['externalMessageId']);
    }//end testSendFreeFormWithinSessionWindow()

    /**
     * Free-form send outside the 24h session window is refused.
     *
     * @return void
     */
    public function testSendFreeFormOutsideSessionWindow(): void
    {
        $this->objectService->inboundMessages = [
            [
                'uuid'      => 'msg-1',
                'channel'   => 'whatsapp',
                'direction' => 'inbound',
                'sentAt'    => gmdate('Y-m-d\TH:i:s\Z', (time() - 90000)),
            ],
        ];

        $this->consentService->method('canSend')->willReturn(true);

        $result = $this->adapter->send(
            ['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
            'Hi there',
        );

        $this->assertSame('sessionWindowExpired', $result['status']);
    }//end testSendFreeFormOutsideSessionWindow()

    /**
     * Free-form send with no prior inbound (no session) is refused.
     *
     * @return void
     */
    public function testSendFreeFormNoSessionRefused(): void
    {
        $this->consentService->method('canSend')->willReturn(true);

        $result = $this->adapter->send(
            ['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
            'Hi',
        );

        $this->assertSame('sessionWindowExpired', $result['status']);
    }//end testSendFreeFormNoSessionRefused()

    /**
     * Inbound webhook with an invalid signature is rejected.
     *
     * @return void
     */
    public function testInboundWebhookInvalidSignature(): void
    {
        $this->providerRepo->method('findById')->willReturn(['uuid' => 'prov-1', 'webhookSecret' => 'secret']);
        $this->providerClient->method('verifySignature')->willReturn(false);

        $result = $this->adapter->handleInboundWebhook('{}', 'sha256=bad', 'prov-1');

        $this->assertSame('invalidSignature', $result['status']);
    }//end testInboundWebhookInvalidSignature()

    /**
     * Inbound webhook with a valid signature persists + creates a
     * placeholder contact when the phone is unknown.
     *
     * @return void
     */
    public function testInboundWebhookCreatesPlaceholderForUnknownContact(): void
    {
        $this->providerRepo->method('findById')->willReturn(['uuid' => 'prov-1', 'webhookSecret' => 'secret']);
        $this->providerClient->method('verifySignature')->willReturn(true);
        $this->consentService->method('isOptOutKeyword')->willReturn(false);
        $this->consentService->method('isOptInKeyword')->willReturn(false);

        $body = json_encode([
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    ['from' => '+31600000000', 'text' => ['body' => 'hello']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->adapter->handleInboundWebhook($body, 'sha256=ok', 'prov-1');

        $this->assertSame('received', $result['status']);
        $this->assertTrue($result['placeholderCreated']);
    }//end testInboundWebhookCreatesPlaceholderForUnknownContact()
}//end class
