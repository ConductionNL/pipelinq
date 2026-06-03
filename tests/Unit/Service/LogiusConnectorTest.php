<?php

/**
 * Unit tests for LogiusConnector.
 *
 * The Logius BBK 1.7 API is mocked at the HTTP-client boundary; no live Logius
 * credentials or endpoints are required.
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Exception\LogiusApiException;
use OCA\Pipelinq\Service\LogiusConnector;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogiusConnector.
 */
class LogiusConnectorTest extends TestCase
{
    /**
     * In-memory app config store.
     *
     * @var array<string, string>
     */
    private array $store = [];

    /**
     * The HTTP client mock.
     *
     * @var IClient
     */
    private IClient $client;

    /**
     * The connector under test.
     *
     * @var LogiusConnector
     */
    private LogiusConnector $connector;

    /**
     * Set up the connector with mocked HTTP and config.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->store = [
            'berichtenbox_logius_token_url'      => 'https://logius.test/token',
            'berichtenbox_logius_api_base'       => 'https://logius.test/api',
            'berichtenbox_logius_client_id'      => 'cid',
            'berichtenbox_logius_client_secret'  => 'secret',
            'berichtenbox_logius_webhook_secret' => 'whsecret',
        ];

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default=''): string {
                return ($this->store[$key] ?? $default);
            }
        );

        $this->client = $this->createMock(IClient::class);
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($this->client);

        $this->connector = new LogiusConnector(
            $clientService,
            $appConfig,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Build a mocked HTTP response.
     *
     * @param int    $status The status code.
     * @param string $body   The response body.
     *
     * @return IResponse The mocked response.
     */
    private function response(int $status, string $body): IResponse
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);
        return $response;
    }//end response()

    /**
     * A successful send returns the Logius message id.
     *
     * @return void
     */
    public function testSendMessageSuccess(): void
    {
        $this->client->method('post')->willReturnOnConsecutiveCalls(
            $this->response(200, json_encode(['access_token' => 'tok'])),
            $this->response(201, json_encode(['message-id' => 'bbk-123']))
        );

        $result = $this->connector->sendMessage(
            [
                'subject' => 'Test',
                'body'    => '<p>ok</p>',
                'bsn'     => '123456782',
            ]
        );

        $this->assertSame('bbk-123', $result['logiusMessageId']);
        $this->assertSame('sent', $result['status']);
    }//end testSendMessageSuccess()

    /**
     * A 429 maps to a rate-limit LogiusApiException.
     *
     * @return void
     */
    public function testSendMessageRateLimit(): void
    {
        $this->client->method('post')->willReturnOnConsecutiveCalls(
            $this->response(200, json_encode(['access_token' => 'tok'])),
            $this->response(429, '')
        );

        try {
            $this->connector->sendMessage(['subject' => 'T', 'body' => '<p>ok</p>']);
            $this->fail('Expected LogiusApiException');
        } catch (LogiusApiException $e) {
            $this->assertSame('rate-limit', $e->getReason());
        }
    }//end testSendMessageRateLimit()

    /**
     * Missing credentials raise an auth failure.
     *
     * @return void
     */
    public function testAuthFailureWhenNoCredentials(): void
    {
        $this->store['berichtenbox_logius_client_id'] = '';

        $this->expectException(LogiusApiException::class);
        $this->connector->authenticate();
    }//end testAuthFailureWhenNoCredentials()

    /**
     * Outbound validation rejects an over-long subject.
     *
     * @return void
     */
    public function testValidationRejectsLongSubject(): void
    {
        $this->expectException(LogiusApiException::class);
        $this->connector->validateOutbound(['subject' => str_repeat('x', 201), 'body' => '<p>ok</p>']);
    }//end testValidationRejectsLongSubject()

    /**
     * Outbound validation rejects a disallowed attachment MIME type.
     *
     * @return void
     */
    public function testValidationRejectsBadMime(): void
    {
        $this->expectException(LogiusApiException::class);
        $this->connector->validateOutbound(
            [
                'subject'     => 'ok',
                'body'        => '<p>ok</p>',
                'attachments' => [['mime' => 'application/zip', 'sizeBytes' => 10]],
            ]
        );
    }//end testValidationRejectsBadMime()

    /**
     * A mailbox check returns the boolean from Logius.
     *
     * @return void
     */
    public function testCheckMailboxExists(): void
    {
        $this->client->method('post')->willReturnOnConsecutiveCalls(
            $this->response(200, json_encode(['access_token' => 'tok'])),
            $this->response(200, json_encode(['mailboxAvailable' => true]))
        );

        $this->assertTrue($this->connector->checkMailboxExists('123456782'));
    }//end testCheckMailboxExists()

    /**
     * A valid webhook signature is accepted and an invalid one is rejected.
     *
     * @return void
     */
    public function testWebhookSignatureVerification(): void
    {
        $body  = '{"logiusMessageId":"x","readAt":"2026-06-01T00:00:00Z"}';
        $valid = hash_hmac('sha256', $body, 'whsecret');

        $this->assertTrue($this->connector->verifyWebhookSignature($body, $valid));
        $this->assertFalse($this->connector->verifyWebhookSignature($body, 'deadbeef'));
        $this->assertFalse($this->connector->verifyWebhookSignature($body, ''));
    }//end testWebhookSignatureVerification()
}//end class
