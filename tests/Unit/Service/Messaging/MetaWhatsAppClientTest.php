<?php

/**
 * Unit tests for MetaWhatsAppClient.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Messaging
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

namespace OCA\Pipelinq\Tests\Unit\Service\Messaging;

use OCA\Pipelinq\Service\Messaging\Provider\MetaWhatsAppClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Meta WhatsApp Cloud API client.
 */
class MetaWhatsAppClientTest extends TestCase
{
    /**
     * Mock HTTP client service.
     *
     * @var IClientService
     */
    private IClientService $clientService;

    /**
     * The client under test.
     *
     * @var MetaWhatsAppClient
     */
    private MetaWhatsAppClient $client;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->client        = new MetaWhatsAppClient(
            $this->clientService,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * A valid X-Hub-Signature-256 is accepted.
     *
     * @return void
     */
    public function testValidSignatureAccepted(): void
    {
        $secret  = 'app-secret';
        $body    = '{"entry":[]}';
        $sig     = 'sha256='.hash_hmac('sha256', $body, $secret);
        $headers = ['x-hub-signature-256' => $sig];

        $this->assertTrue($this->client->verifyWebhookSignature($body, $headers, [], $secret));
    }//end testValidSignatureAccepted()

    /**
     * A tampered signature is rejected.
     *
     * @return void
     */
    public function testInvalidSignatureRejected(): void
    {
        $headers = ['x-hub-signature-256' => 'sha256=deadbeef'];

        $this->assertFalse($this->client->verifyWebhookSignature('{"a":1}', $headers, [], 'app-secret'));
    }//end testInvalidSignatureRejected()

    /**
     * An empty secret never verifies (fail closed).
     *
     * @return void
     */
    public function testEmptySecretRejected(): void
    {
        $body    = '{}';
        $sig     = 'sha256='.hash_hmac('sha256', $body, '');
        $headers = ['x-hub-signature-256' => $sig];

        $this->assertFalse($this->client->verifyWebhookSignature($body, $headers, [], ''));
    }//end testEmptySecretRejected()

    /**
     * Inbound text and media messages are parsed into normalised messages.
     *
     * @return void
     */
    public function testParseInboundTextAndMedia(): void
    {
        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => ['display_phone_number' => '31611112222'],
                                'contacts' => [['wa_id' => '31699998888']],
                                'messages' => [
                                    [
                                        'id'        => 'wamid.text',
                                        'from'      => '31699998888',
                                        'type'      => 'text',
                                        'text'      => ['body' => 'Hallo'],
                                        'timestamp' => '1700000000',
                                    ],
                                    [
                                        'id'    => 'wamid.image',
                                        'from'  => '31699998888',
                                        'type'  => 'image',
                                        'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $messages = $this->client->parseInboundMessages($payload);

        $this->assertCount(2, $messages);
        $this->assertSame('whatsapp', $messages[0]->channel);
        $this->assertSame('Hallo', $messages[0]->body);
        $this->assertSame('31699998888', $messages[0]->fromNumber);
        $this->assertTrue($messages[1]->hasMedia());
        $this->assertSame('media-1', $messages[1]->media[0]['id']);
    }//end testParseInboundTextAndMedia()

    /**
     * Delivery statuses are parsed and mapped, never carrying a cost (Meta).
     *
     * @return void
     */
    public function testParseDeliveryStatusesHaveNoCost(): void
    {
        $payload = [
            'entry' => [
                [
                    'changes' => [
                        ['value' => ['statuses' => [['id' => 'wamid.x', 'status' => 'delivered']]]],
                    ],
                ],
            ],
        ];

        $updates = $this->client->parseDeliveryUpdates($payload);

        $this->assertCount(1, $updates);
        $this->assertSame('delivered', $updates[0]->status);
        $this->assertFalse($updates[0]->hasCost());
    }//end testParseDeliveryStatusesHaveNoCost()

    /**
     * A configured template send posts to Graph and returns the message id.
     *
     * @return void
     */
    public function testSendTemplateReturnsExternalId(): void
    {
        $this->client->configure(
            ['kind' => 'whatsapp-cloud-api'],
            ['phoneNumberId' => 'pn-1', 'systemUserToken' => 'tok']
        );

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{"messages":[{"id":"wamid.sent"}]}');

        $http = $this->createMock(IClient::class);
        $http->expects($this->once())->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($http);

        $result = $this->client->sendTemplate('+31699998888', 'afspraak_nl', 'nl', ['Jan', 'vrijdag', '14:00']);

        $this->assertTrue($result->success);
        $this->assertSame('wamid.sent', $result->externalMessageId);
    }//end testSendTemplateReturnsExternalId()

    /**
     * An unconfigured client refuses to send (permanent failure).
     *
     * @return void
     */
    public function testUnconfiguredSendFailsPermanently(): void
    {
        $result = $this->client->sendFreeForm('+31699998888', 'hi');

        $this->assertFalse($result->success);
        $this->assertFalse($result->transientFailure);
        $this->assertSame('provider_not_configured', $result->errorCode);
    }//end testUnconfiguredSendFailsPermanently()
}//end class
