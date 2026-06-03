<?php

/**
 * Unit tests for the SMS provider clients (MessageBird, CM.com).
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

use OCA\Pipelinq\Service\Messaging\Provider\CmComClient;
use OCA\Pipelinq\Service\Messaging\Provider\MessageBirdClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the MessageBird and CM.com clients.
 */
class SmsProviderClientsTest extends TestCase
{
    /**
     * Mock HTTP client service.
     *
     * @var IClientService
     */
    private IClientService $clientService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
    }//end setUp()

    /**
     * MessageBird HMAC-SHA256 base64 signature validates.
     *
     * @return void
     */
    public function testMessageBirdSignature(): void
    {
        $client = new MessageBirdClient($this->clientService, $this->createMock(LoggerInterface::class));
        $secret = 'mb-secret';
        $body   = '{"id":"x"}';
        $sig    = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $this->assertTrue($client->verifyWebhookSignature($body, ['messagebird-signature' => $sig], [], $secret));
        $this->assertFalse($client->verifyWebhookSignature($body, ['messagebird-signature' => 'bad'], [], $secret));
    }//end testMessageBirdSignature()

    /**
     * MessageBird inbound MO is parsed into an SMS message.
     *
     * @return void
     */
    public function testMessageBirdInbound(): void
    {
        $client   = new MessageBirdClient($this->clientService, $this->createMock(LoggerInterface::class));
        $messages = $client->parseInboundMessages(['originator' => '31699998888', 'recipient' => '31611112222', 'payload' => 'STOP', 'id' => 'm1']);

        $this->assertCount(1, $messages);
        $this->assertSame('sms', $messages[0]->channel);
        $this->assertSame('STOP', $messages[0]->body);
    }//end testMessageBirdInbound()

    /**
     * MessageBird status webhook captures price when present.
     *
     * @return void
     */
    public function testMessageBirdStatusPrice(): void
    {
        $client  = new MessageBirdClient($this->clientService, $this->createMock(LoggerInterface::class));
        $updates = $client->parseDeliveryUpdates(['id' => 'm1', 'status' => 'delivered', 'price' => ['amount' => 0.05, 'currency' => 'EUR']]);

        $this->assertCount(1, $updates);
        $this->assertSame('delivered', $updates[0]->status);
        $this->assertTrue($updates[0]->hasCost());
        $this->assertSame('EUR', $updates[0]->costCurrency);
    }//end testMessageBirdStatusPrice()

    /**
     * MessageBird send returns the provider id on success.
     *
     * @return void
     */
    public function testMessageBirdSend(): void
    {
        $client = new MessageBirdClient($this->clientService, $this->createMock(LoggerInterface::class));
        $client->configure(['phoneNumber' => 'Pipelinq'], ['accessKey' => 'key']);

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{"id":"mb-1"}');
        $http = $this->createMock(IClient::class);
        $http->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($http);

        $result = $client->sendFreeForm('+31699998888', 'Hoi');

        $this->assertTrue($result->success);
        $this->assertSame('mb-1', $result->externalMessageId);
    }//end testMessageBirdSend()

    /**
     * CM.com HMAC-SHA256 hex signature validates.
     *
     * @return void
     */
    public function testCmComSignature(): void
    {
        $client = new CmComClient($this->clientService, $this->createMock(LoggerInterface::class));
        $secret = 'cm-secret';
        $body   = '{"reference":"r1"}';
        $sig    = hash_hmac('sha256', $body, $secret);

        $this->assertTrue($client->verifyWebhookSignature($body, ['x-cm-signature' => $sig], [], $secret));
        $this->assertFalse($client->verifyWebhookSignature($body, ['x-cm-signature' => 'bad'], [], $secret));
    }//end testCmComSignature()

    /**
     * An unconfigured CM.com send fails permanently.
     *
     * @return void
     */
    public function testCmComUnconfiguredSend(): void
    {
        $client = new CmComClient($this->clientService, $this->createMock(LoggerInterface::class));
        $result = $client->sendFreeForm('+31699998888', 'Hoi');

        $this->assertFalse($result->success);
        $this->assertSame('provider_not_configured', $result->errorCode);
    }//end testCmComUnconfiguredSend()
}//end class
