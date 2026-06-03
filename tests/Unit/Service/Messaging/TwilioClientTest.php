<?php

/**
 * Unit tests for TwilioClient.
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

use OCA\Pipelinq\Service\Messaging\Provider\TwilioClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Twilio messaging client.
 */
class TwilioClientTest extends TestCase
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
     * @var TwilioClient
     */
    private TwilioClient $client;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->client        = new TwilioClient(
            $this->clientService,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * The Twilio signature over URL + sorted params is validated.
     *
     * @return void
     */
    public function testValidSignatureAccepted(): void
    {
        $token = 'auth-token';
        $url   = 'https://example.org/api/messaging/webhook/sms-twilio';
        $this->client->configure(['kind' => 'sms', 'phoneNumber' => '+31600'], ['accountSid' => 'AC', 'authToken' => $token, 'webhookUrl' => $url]);

        $params = ['From' => '+31699998888', 'Body' => 'Hoi', 'To' => '+31611112222'];
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }

        $signature = base64_encode(hash_hmac('sha1', $data, $token, true));
        $headers   = ['x-twilio-signature' => $signature];

        $this->assertTrue($this->client->verifyWebhookSignature('', $headers, $params, $token));
    }//end testValidSignatureAccepted()

    /**
     * A wrong signature is rejected.
     *
     * @return void
     */
    public function testInvalidSignatureRejected(): void
    {
        $this->client->configure(['kind' => 'sms'], ['webhookUrl' => 'https://example.org/hook']);

        $this->assertFalse(
            $this->client->verifyWebhookSignature('', ['x-twilio-signature' => 'nope'], ['a' => 'b'], 'auth-token')
        );
    }//end testInvalidSignatureRejected()

    /**
     * A delivery status webhook captures Twilio's Price as a positive EUR-able amount.
     *
     * @return void
     */
    public function testDeliveryUpdateCapturesPrice(): void
    {
        $payload = [
            'MessageSid'    => 'SM123',
            'MessageStatus' => 'delivered',
            'Price'         => '-0.0075',
            'PriceUnit'     => 'USD',
        ];

        $updates = $this->client->parseDeliveryUpdates($payload);

        $this->assertCount(1, $updates);
        $this->assertSame('delivered', $updates[0]->status);
        $this->assertTrue($updates[0]->hasCost());
        $this->assertEqualsWithDelta(0.0075, $updates[0]->costAmount, 0.00001);
        $this->assertSame('USD', $updates[0]->costCurrency);
    }//end testDeliveryUpdateCapturesPrice()

    /**
     * Inbound SMS is parsed and the whatsapp: prefix stripped on a BSP instance.
     *
     * @return void
     */
    public function testInboundWhatsAppPrefixStripped(): void
    {
        $this->client->configure(['kind' => 'whatsapp-bsp'], []);

        $messages = $this->client->parseInboundMessages(
            ['From' => 'whatsapp:+31699998888', 'To' => 'whatsapp:+31611112222', 'Body' => 'Hoi', 'MessageSid' => 'SM1']
        );

        $this->assertCount(1, $messages);
        $this->assertSame('whatsapp', $messages[0]->channel);
        $this->assertSame('+31699998888', $messages[0]->fromNumber);
    }//end testInboundWhatsAppPrefixStripped()

    /**
     * A 503 from Twilio is classified as a transient (retryable) failure.
     *
     * @return void
     */
    public function testServerErrorIsTransient(): void
    {
        $this->client->configure(['kind' => 'sms', 'phoneNumber' => '+31600'], ['accountSid' => 'AC', 'authToken' => 'tok']);

        $http = $this->createMock(IClient::class);
        $http->method('post')->willThrowException(new \RuntimeException('Service Unavailable', 503));
        $this->clientService->method('newClient')->willReturn($http);

        $result = $this->client->sendFreeForm('+31699998888', 'hi');

        $this->assertFalse($result->success);
        $this->assertTrue($result->transientFailure);
    }//end testServerErrorIsTransient()

    /**
     * A successful send returns the Twilio SID.
     *
     * @return void
     */
    public function testSuccessfulSendReturnsSid(): void
    {
        $this->client->configure(['kind' => 'sms', 'phoneNumber' => '+31600'], ['accountSid' => 'AC', 'authToken' => 'tok']);

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{"sid":"SM999"}');
        $http = $this->createMock(IClient::class);
        $http->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($http);

        $result = $this->client->sendFreeForm('+31699998888', 'hi');

        $this->assertTrue($result->success);
        $this->assertSame('SM999', $result->externalMessageId);
    }//end testSuccessfulSendReturnsSid()
}//end class
