<?php

/**
 * Unit tests for the CallVoipAdapter.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
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

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\Service\Cti\Adapter\CallVoipAdapter;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CallVoipAdapter (REQ-CTI-006/007) — external telephony is mocked.
 */
class CallVoipAdapterTest extends TestCase
{
    /**
     * The HTTP client service mock.
     *
     * @var IClientService
     */
    private IClientService $clientService;

    /**
     * The logger mock.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the adapter under test.
     *
     * @param string $apiBaseUrl The API base URL.
     *
     * @return CallVoipAdapter The adapter.
     */
    private function adapter(string $apiBaseUrl=''): CallVoipAdapter
    {
        return new CallVoipAdapter($this->clientService, $this->logger, $apiBaseUrl);
    }//end adapter()

    /**
     * A correct HMAC-SHA256 signature passes verification.
     *
     * @return void
     */
    public function testValidHmacSignaturePasses(): void
    {
        $body      = '{"event":"answered","callId":"c-1"}';
        $secret    = 'shared-secret-xyz';
        $signature = hash_hmac('sha256', $body, $secret);

        $this->assertTrue(
            $this->adapter()->verifyWebhookSignature($body, ['x-signature' => $signature], [], $secret)
        );
    }//end testValidHmacSignaturePasses()

    /**
     * An invalid signature is rejected.
     *
     * @return void
     */
    public function testInvalidSignatureRejected(): void
    {
        $this->assertFalse(
            $this->adapter()->verifyWebhookSignature('{"a":1}', ['x-signature' => 'deadbeef'], [], 'secret')
        );
    }//end testInvalidSignatureRejected()

    /**
     * A missing signature header is rejected.
     *
     * @return void
     */
    public function testMissingSignatureRejected(): void
    {
        $this->assertFalse($this->adapter()->verifyWebhookSignature('{"a":1}', [], [], 'secret'));
    }//end testMissingSignatureRejected()

    /**
     * An empty secret is always rejected (fail closed).
     *
     * @return void
     */
    public function testEmptySecretRejected(): void
    {
        $body = '{}';
        $this->assertFalse(
            $this->adapter()->verifyWebhookSignature($body, ['x-signature' => hash_hmac('sha256', $body, '')], [], '')
        );
    }//end testEmptySecretRejected()

    /**
     * An answered webhook normalises into a CtiWebhookResult.
     *
     * @return void
     */
    public function testHandleInboundWebhookNormalisesAnswered(): void
    {
        $result = $this->adapter()->handleInboundWebhook(
            [
                'event'     => 'answered',
                'callId'    => 'call-12345',
                'from'      => '+31612345678',
                'extension' => '101',
            ]
        );

        $this->assertSame('answered', $result->eventType);
        $this->assertSame('call-12345', $result->externalCallId);
        $this->assertSame('+31612345678', $result->fromNumber);
        $this->assertSame('101', $result->extension);
    }//end testHandleInboundWebhookNormalisesAnswered()

    /**
     * A recording_ready webhook surfaces the recording URL and expiry.
     *
     * @return void
     */
    public function testHandleInboundWebhookExtractsRecording(): void
    {
        $result = $this->adapter()->handleInboundWebhook(
            [
                'event'     => 'recording_ready',
                'callId'    => 'call-12345',
                'recording' => [
                    'url'       => 'https://callvoip.example/recordings/call-12345',
                    'expiresAt' => '2026-08-20T23:59:59Z',
                ],
            ]
        );

        $this->assertSame('recording_ready', $result->eventType);
        $this->assertSame('https://callvoip.example/recordings/call-12345', $result->recordingUrl);
        $this->assertSame('2026-08-20T23:59:59Z', $result->recordingExpiresAt);
    }//end testHandleInboundWebhookExtractsRecording()

    /**
     * originateCall posts to the platform and returns a successful result.
     *
     * @return void
     */
    public function testOriginateCallPostsToPlatform(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{"callId":"new-call-1"}');

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('post')
            ->with($this->stringContains('/calls'))
            ->willReturn($response);

        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->adapter('https://api.callvoip.example/v2')
            ->originateCall('101', '+31612987654', '+31303033000');

        $this->assertTrue($result->success);
        $this->assertSame('new-call-1', $result->externalCallId);
    }//end testOriginateCallPostsToPlatform()

    /**
     * originateCall fails gracefully when the base URL is unconfigured.
     *
     * @return void
     */
    public function testOriginateCallWithoutBaseUrlFails(): void
    {
        $result = $this->adapter()->originateCall('101', '+31612987654', '+31303033000');

        $this->assertFalse($result->success);
    }//end testOriginateCallWithoutBaseUrlFails()
}//end class
