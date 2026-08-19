<?php

/**
 * Unit tests for PortalTokenService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the single-use token service.
 */
class PortalTokenServiceTest extends TestCase
{
    /**
     * Mutable "now" used by the fake time factory.
     *
     * @var int
     */
    private int $now = 1000000;

    /**
     * Build a service whose time factory reads $this->now.
     *
     * @return PortalTokenService The service.
     */
    private function service(): PortalTokenService
    {
        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')->willReturnCallback(
            static fn (int $length): string => str_repeat('a', $length)
        );

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturnCallback(
            fn (): \DateTime => (new \DateTime())->setTimestamp($this->now)
        );
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);

        return new PortalTokenService($random, $time);
    }//end service()

    /**
     * A freshly issued token verifies against its own hash + expiry.
     *
     * @return void
     */
    public function testIssuedTokenVerifies(): void
    {
        $service = $this->service();
        $token   = $service->issue(30);

        $this->assertTrue($service->verify($token['plain'], $token['hash'], $token['expiresAt']));
    }//end testIssuedTokenVerifies()

    /**
     * The plaintext is never stored as the hash (only its SHA-256 is).
     *
     * @return void
     */
    public function testHashIsNotPlaintext(): void
    {
        $service = $this->service();
        $token   = $service->issue(30);

        $this->assertNotSame($token['plain'], $token['hash']);
        $this->assertSame(hash('sha256', $token['plain']), $token['hash']);
    }//end testHashIsNotPlaintext()

    /**
     * A wrong token does not verify.
     *
     * @return void
     */
    public function testWrongTokenFails(): void
    {
        $service = $this->service();
        $token   = $service->issue(30);

        $this->assertFalse($service->verify('wrong-token', $token['hash'], $token['expiresAt']));
    }//end testWrongTokenFails()

    /**
     * An expired token does not verify even with the right hash.
     *
     * @return void
     */
    public function testExpiredTokenFails(): void
    {
        $service = $this->service();
        $token   = $service->issue(30);

        // Advance past the 30-minute TTL.
        $this->now += (31 * 60);
        $this->assertFalse($service->verify($token['plain'], $token['hash'], $token['expiresAt']));
    }//end testExpiredTokenFails()

    /**
     * An empty token and empty hash are rejected.
     *
     * @return void
     */
    public function testEmptyInputsRejected(): void
    {
        $service = $this->service();
        $this->assertFalse($service->verify('', 'hash', '2999-01-01T00:00:00+00:00'));
        $this->assertFalse($service->verify('token', '', '2999-01-01T00:00:00+00:00'));
        $this->assertFalse($service->verify('token', 'hash', null));
    }//end testEmptyInputsRejected()
}//end class
