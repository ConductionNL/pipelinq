<?php

/**
 * Unit tests for the Asterisk and RingCentral adapters' webhook auth.
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

use OCA\Pipelinq\Service\Cti\Adapter\AsteriskAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\RingCentralAdapter;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AsteriskAdapter and RingCentralAdapter signature handling.
 */
class AsteriskRingCentralAdapterTest extends TestCase
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
     * Asterisk accepts a matching shared-secret query parameter.
     *
     * @return void
     */
    public function testAsteriskValidQuerySecretPasses(): void
    {
        $adapter = new AsteriskAdapter($this->clientService, $this->logger);

        $this->assertTrue(
            $adapter->verifyWebhookSignature('', [], ['secret' => 'asterisk-secret-123'], 'asterisk-secret-123')
        );
    }//end testAsteriskValidQuerySecretPasses()

    /**
     * Asterisk rejects a mismatched query secret.
     *
     * @return void
     */
    public function testAsteriskMismatchedSecretRejected(): void
    {
        $adapter = new AsteriskAdapter($this->clientService, $this->logger);

        $this->assertFalse(
            $adapter->verifyWebhookSignature('', [], ['secret' => 'wrong'], 'asterisk-secret-123')
        );
    }//end testAsteriskMismatchedSecretRejected()

    /**
     * Asterisk maps a hangup event to the normalised "ended" type.
     *
     * @return void
     */
    public function testAsteriskMapsHangupToEnded(): void
    {
        $adapter = new AsteriskAdapter($this->clientService, $this->logger);

        $result = $adapter->handleInboundWebhook(['event' => 'hangup', 'Uniqueid' => 'ast-1', 'duration' => 42]);

        $this->assertSame('ended', $result->eventType);
        $this->assertSame('ast-1', $result->externalCallId);
        $this->assertSame(42, $result->durationSeconds);
    }//end testAsteriskMapsHangupToEnded()

    /**
     * RingCentral accepts a matching validation token header.
     *
     * @return void
     */
    public function testRingCentralValidTokenPasses(): void
    {
        $adapter = new RingCentralAdapter($this->clientService, $this->logger);

        $this->assertTrue(
            $adapter->verifyWebhookSignature('', ['validation-token' => 'tok-123'], [], 'tok-123')
        );
    }//end testRingCentralValidTokenPasses()

    /**
     * RingCentral accepts a Bearer Authorization header form.
     *
     * @return void
     */
    public function testRingCentralBearerTokenPasses(): void
    {
        $adapter = new RingCentralAdapter($this->clientService, $this->logger);

        $this->assertTrue(
            $adapter->verifyWebhookSignature('', ['authorization' => 'Bearer tok-123'], [], 'tok-123')
        );
    }//end testRingCentralBearerTokenPasses()

    /**
     * RingCentral rejects an invalid token.
     *
     * @return void
     */
    public function testRingCentralInvalidTokenRejected(): void
    {
        $adapter = new RingCentralAdapter($this->clientService, $this->logger);

        $this->assertFalse(
            $adapter->verifyWebhookSignature('', ['validation-token' => 'nope'], [], 'tok-123')
        );
    }//end testRingCentralInvalidTokenRejected()

    /**
     * RingCentral maps CallConnected to the normalised "answered" type.
     *
     * @return void
     */
    public function testRingCentralMapsCallConnectedToAnswered(): void
    {
        $adapter = new RingCentralAdapter($this->clientService, $this->logger);

        $result = $adapter->handleInboundWebhook(
            ['body' => ['telephonyStatus' => 'CallConnected', 'sessionId' => 'rc-9']]
        );

        $this->assertSame('answered', $result->eventType);
        $this->assertSame('rc-9', $result->externalCallId);
    }//end testRingCentralMapsCallConnectedToAnswered()

    /**
     * RingCentral originate fails closed without an access token.
     *
     * @return void
     */
    public function testRingCentralOriginateWithoutTokenFails(): void
    {
        $adapter = new RingCentralAdapter($this->clientService, $this->logger, 'https://platform.ringcentral.example');

        $result = $adapter->originateCall('205', '+31612987654', '+31303033000');

        $this->assertFalse($result->success);
    }//end testRingCentralOriginateWithoutTokenFails()
}//end class
