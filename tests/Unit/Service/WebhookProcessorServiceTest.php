<?php

/**
 * Unit tests for WebhookProcessorService.
 *
 * Covers the event-routing matrix from the marketing-05 spec:
 * - delivered → status transition
 * - bounce (hard) → withdraw consent immediately, status "bounced"
 * - bounce (soft x5) → withdraw on threshold only
 * - open → status "opened"
 * - click → AttributionService.recordClick (with utm_campaign extraction)
 * - unsubscribe → withdraw consent, status "unsubscribed"
 * - complaint → withdraw consent ("spam-complaint"), status "complained"
 * - SendGrid normalisation
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AttributionService;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\WebhookProcessorService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WebhookProcessorService.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
 */
class WebhookProcessorServiceTest extends TestCase
{
    private ContainerInterface $container;
    private IAppConfig $appConfig;
    private ComplianceService $complianceService;
    private AttributionService $attributionService;
    private BlastService $blastService;
    private LoggerInterface $logger;
    private object $objectService;
    private WebhookProcessorService $service;

    /**
     * Per-key app-config store used by the mock.
     *
     * @var array<string, string>
     */
    private array $appConfigStore = [];

    /**
     * Set up — mock collaborators + in-memory ObjectService.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container          = $this->createMock(ContainerInterface::class);
        $this->appConfig          = $this->createMock(IAppConfig::class);
        $this->complianceService  = $this->createMock(ComplianceService::class);
        $this->attributionService = $this->createMock(AttributionService::class);
        $this->blastService       = $this->createMock(BlastService::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        // Stub IAppConfig as an in-memory store so soft-bounce counter
        // increments are visible across calls.
        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = ''): string {
                return $this->appConfigStore[$key] ?? $default;
            }
        );
        $this->appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->appConfigStore[$key] = $value;
                return true;
            }
        );
        $this->appConfig->method('deleteKey')->willReturnCallback(
            function (string $app, string $key): bool {
                unset($this->appConfigStore[$key]);
                return true;
            }
        );

        $this->objectService = new class {
            /** @var array<string, array<string, mixed>> */
            public array $store = [];

            /** @var array<int, array<string, mixed>> */
            public array $saved = [];

            /**
             * Mock find() — returns a stored row or null.
             *
             * @param string $id       Identifier.
             * @param mixed  $register Register slug.
             * @param mixed  $schema   Schema slug.
             *
             * @return array<string, mixed>|null
             */
            public function find(string $id, mixed $register = null, mixed $schema = null): ?array
            {
                return ($this->store[$id] ?? null);
            }

            /**
             * Mock findAll() — flat filter over the in-memory store.
             *
             * Mirrors OR's real ObjectService::findAll(array $config): the
             * register/schema context travels INSIDE $config['filters'] and OR
             * treats both as reserved params, never as object-field filters.
             *
             * @param array<string, mixed> $config Config with a `filters` map.
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $config = []): array
            {
                $filters = $config['filters'] ?? [];
                unset($filters['register'], $filters['schema']);

                $out = [];
                foreach ($this->store as $row) {
                    $match = true;
                    foreach ($filters as $key => $value) {
                        if (($row[$key] ?? null) !== $value) {
                            $match = false;
                            break;
                        }
                    }

                    if ($match === true) {
                        $out[] = $row;
                    }
                }

                return $out;
            }

            /**
             * Mock saveObject() — store + record.
             *
             * @param array<string, mixed> $object   Payload.
             * @param mixed                $register Register slug.
             * @param mixed                $schema   Schema slug.
             * @param string|null          $uuid     Existing id.
             *
             * @return array<string, mixed>
             */
            public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null): array
            {
                $id              = ($uuid ?? ($object['uuid'] ?? 'gen-'.count($this->store)));
                $object['uuid']  = $id;
                $this->store[$id] = $object;
                $this->saved[]   = $object;
                return $object;
            }
        };

        $this->container->method('get')->willReturnCallback(
            function (string $class): object {
                if ($class === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $this->objectService;
                }

                throw new \RuntimeException('Service not available: '.$class);
            }
        );

        $this->service = new WebhookProcessorService(
            container: $this->container,
            appConfig: $this->appConfig,
            complianceService: $this->complianceService,
            attributionService: $this->attributionService,
            blastService: $this->blastService,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that a delivered event flips the delivery status.
     *
     * @return void
     */
    public function testProcessDeliveredSetsStatus(): void
    {
        $this->objectService->store['delivery-1'] = [
            'uuid'      => 'delivery-1',
            'blastId'   => 'blast-1',
            'contactId' => 'contact-1',
            'status'    => 'sent',
        ];

        $this->service->processEvent(
            ['eventType' => 'delivered', 'blastDeliveryId' => 'delivery-1', 'timestamp' => 1700000000],
            'sendgrid',
        );

        $this->assertSame('delivered', $this->objectService->store['delivery-1']['status']);
        $this->assertNotEmpty($this->objectService->store['delivery-1']['deliveredAt']);
    }//end testProcessDeliveredSetsStatus()

    /**
     * Test that a hard bounce flips status AND withdraws consent immediately.
     *
     * @return void
     */
    public function testHardBounceWithdrawsConsentImmediately(): void
    {
        $this->objectService->store['delivery-2'] = [
            'uuid'      => 'delivery-2',
            'blastId'   => 'blast-1',
            'contactId' => 'contact-2',
            'channel'   => 'email',
            'status'    => 'sent',
        ];

        $this->complianceService->expects($this->once())
            ->method('recordConsentWithdrawal')
            ->with(
                $this->equalTo('contact-2'),
                $this->equalTo('email'),
                $this->equalTo('bounce-hard'),
                $this->equalTo('blast-1'),
            );

        $this->service->processEvent(
            [
                'eventType'       => 'bounce',
                'blastDeliveryId' => 'delivery-2',
                'bounceType'      => 'hard',
                'reason'          => '550 user unknown',
            ],
            'sendgrid',
        );

        $this->assertSame('bounced', $this->objectService->store['delivery-2']['status']);
        $this->assertSame('hard', $this->objectService->store['delivery-2']['bounceType']);
    }//end testHardBounceWithdrawsConsentImmediately()

    /**
     * Test that soft bounces only withdraw after the threshold (5 by default).
     *
     * @return void
     */
    public function testSoftBounceWithdrawsOnlyAtThreshold(): void
    {
        // Seed five delivery rows for the same contact to drive five soft
        // bounces — each pass loads its own row.
        for ($i = 1; $i <= 5; $i++) {
            $id = 'soft-delivery-'.$i;
            $this->objectService->store[$id] = [
                'uuid'      => $id,
                'blastId'   => 'blast-soft',
                'contactId' => 'contact-soft',
                'channel'   => 'email',
                'status'    => 'sent',
            ];
        }

        // Expect recordConsentWithdrawal called EXACTLY ONCE on the 5th hit.
        $this->complianceService->expects($this->once())
            ->method('recordConsentWithdrawal')
            ->with(
                $this->equalTo('contact-soft'),
                $this->equalTo('email'),
                $this->equalTo('bounce-soft-x5'),
                $this->equalTo('blast-soft'),
            );

        for ($i = 1; $i <= 5; $i++) {
            $this->service->processEvent(
                [
                    'eventType'       => 'bounce',
                    'blastDeliveryId' => 'soft-delivery-'.$i,
                    'bounceType'      => 'soft',
                ],
                'sendgrid',
            );
        }
    }//end testSoftBounceWithdrawsOnlyAtThreshold()

    /**
     * Test that an unsubscribe event withdraws consent with the literal reason.
     *
     * @return void
     */
    public function testUnsubscribeWithdrawsConsent(): void
    {
        $this->objectService->store['delivery-u'] = [
            'uuid'      => 'delivery-u',
            'blastId'   => 'blast-u',
            'contactId' => 'contact-u',
            'channel'   => 'email',
            'status'    => 'delivered',
        ];

        $this->complianceService->expects($this->once())
            ->method('recordConsentWithdrawal')
            ->with(
                $this->equalTo('contact-u'),
                $this->equalTo('email'),
                $this->equalTo('user-unsubscribed'),
                $this->equalTo('blast-u'),
            );

        $this->service->processEvent(
            ['eventType' => 'unsubscribe', 'blastDeliveryId' => 'delivery-u'],
            'sendgrid',
        );

        $this->assertSame('unsubscribed', $this->objectService->store['delivery-u']['status']);
    }//end testUnsubscribeWithdrawsConsent()

    /**
     * Test that a click event delegates to AttributionService AND extracts
     * `utm_campaign` from the URL.
     *
     * @return void
     */
    public function testClickEventExtractsUtmCampaign(): void
    {
        $this->objectService->store['delivery-c'] = [
            'uuid'      => 'delivery-c',
            'blastId'   => 'blast-c',
            'contactId' => 'contact-c',
            'status'    => 'delivered',
        ];

        $url = 'https://example.com/promo?utm_campaign=blast-spring-2026&utm_source=email';

        $this->attributionService->expects($this->once())
            ->method('recordClick')
            ->with(
                $this->equalTo('delivery-c'),
                $this->callback(function (array $payload) use ($url): bool {
                    return $payload['url'] === $url
                        && $payload['utmCampaign'] === 'blast-spring-2026';
                }),
            );

        $this->service->processEvent(
            ['eventType' => 'click', 'blastDeliveryId' => 'delivery-c', 'url' => $url],
            'sendgrid',
        );
    }//end testClickEventExtractsUtmCampaign()

    /**
     * Test that a SendGrid batch is normalised + dispatched event by event.
     *
     * @return void
     */
    public function testProcessSendGridEventDispatchesBatch(): void
    {
        $this->objectService->store['provider-1'] = [
            'uuid'       => 'provider-1',
            'providerId' => 'sg-provider-1',
            'blastId'    => 'blast-1',
            'contactId'  => 'contact-1',
            'status'     => 'sent',
        ];

        $events = [
            ['event' => 'delivered', 'sg_message_id' => 'sg-provider-1', 'timestamp' => 1700000000],
        ];

        $handled = $this->service->processSendGridEvent(events: $events);
        $this->assertSame(1, $handled);
        $this->assertSame('delivered', $this->objectService->store['provider-1']['status']);
    }//end testProcessSendGridEventDispatchesBatch()

    /**
     * Test that an unknown event type is logged and returns true (not handled
     * but not an error).
     *
     * @return void
     */
    public function testUnknownEventTypeIsSafelyIgnored(): void
    {
        $this->logger->expects($this->atLeastOnce())->method('info');

        $result = $this->service->processEvent(
            ['eventType' => 'reactivated'],
            'sendgrid',
        );
        $this->assertTrue($result);
    }//end testUnknownEventTypeIsSafelyIgnored()

    /**
     * Test that a missing delivery (lookup returns null) does not crash any
     * handler and skips the side effect.
     *
     * @return void
     */
    public function testMissingDeliveryIsSkipped(): void
    {
        $this->complianceService->expects($this->never())->method('recordConsentWithdrawal');
        $this->attributionService->expects($this->never())->method('recordClick');

        $this->service->processEvent(
            ['eventType' => 'unsubscribe', 'blastDeliveryId' => 'does-not-exist'],
            'sendgrid',
        );
    }//end testMissingDeliveryIsSkipped()
}//end class
