<?php

/**
 * Unit tests for EncryptionService — AES-256-GCM encrypt/decrypt round-trip,
 * HMAC-SHA256 BSN hashing, key-rotation tolerance, hash_equals compare.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-encryption-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\EncryptionService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EncryptionService.
 */
class EncryptionServiceTest extends TestCase
{
    /**
     * Build the service under test with a fixed system secret so derived
     * keys are deterministic across tests.
     *
     * @return EncryptionService
     */
    private function buildService(): EncryptionService
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static function (string $key, mixed $default=null): string {
                if ($key === 'secret') {
                    return 'unit-test-secret-1234567890abcdef';
                }
                return (string) ($default ?? '');
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        return new EncryptionService($config, $logger);
    }//end buildService()

    /**
     * Encrypt + decrypt round-trip recovers the plaintext.
     *
     * @return void
     */
    public function testEncryptDecryptRoundTrip(): void
    {
        $service = $this->buildService();
        $plain   = '123456789';

        $cipher = $service->encrypt($plain, 'tenant-a');
        $this->assertNotSame($plain, $cipher);
        $this->assertNotEmpty($cipher);
        $decrypted = $service->decrypt($cipher, 'tenant-a');
        $this->assertSame($plain, $decrypted);
    }//end testEncryptDecryptRoundTrip()

    /**
     * Different tenants produce different ciphertexts for the same plaintext.
     *
     * @return void
     */
    public function testTenantIsolation(): void
    {
        $service = $this->buildService();
        $plain   = '123456789';
        $a = $service->encrypt($plain, 'tenant-a');
        $b = $service->encrypt($plain, 'tenant-b');
        $this->assertNotSame($a, $b);

        // Decrypting tenant-a's ciphertext with tenant-b's key must fail.
        $this->expectException(\RuntimeException::class);
        $service->decrypt($a, 'tenant-b');
    }//end testTenantIsolation()

    /**
     * Hash generation is deterministic per tenant + plaintext.
     *
     * @return void
     */
    public function testHashIsDeterministic(): void
    {
        $service = $this->buildService();
        $hash1 = $service->hashBsn('123456789', 'tenant-a');
        $hash2 = $service->hashBsn('123456789', 'tenant-a');
        $this->assertSame($hash1, $hash2);
        $this->assertSame(64, strlen($hash1), 'HMAC-SHA256 hex digest is 64 chars.');
    }//end testHashIsDeterministic()

    /**
     * Hash differs between tenants for the same plaintext.
     *
     * @return void
     */
    public function testHashTenantIsolation(): void
    {
        $service = $this->buildService();
        $a = $service->hashBsn('123456789', 'tenant-a');
        $b = $service->hashBsn('123456789', 'tenant-b');
        $this->assertNotSame($a, $b);
    }//end testHashTenantIsolation()

    /**
     * bsnEquals does constant-time compare and accepts only matching pairs.
     *
     * @return void
     */
    public function testBsnEquals(): void
    {
        $service = $this->buildService();
        $hash    = $service->hashBsn('123456789', 'tenant-a');
        $this->assertTrue($service->bsnEquals('123456789', $hash, 'tenant-a'));
        $this->assertFalse($service->bsnEquals('987654321', $hash, 'tenant-a'));
    }//end testBsnEquals()

    /**
     * Vault provider takes precedence when present (key rotation support).
     *
     * @return void
     */
    public function testVaultProviderPrecedence(): void
    {
        $service = $this->buildService();
        $rotated = random_bytes(32);
        $vault = new class($rotated) {
            public function __construct(private string $key)
            {
            }
            public function getEncryptionKey(string $tenantId): string
            {
                return $this->key;
            }
            public function getDecryptionKeys(string $tenantId): array
            {
                return [$this->key];
            }
            public function getHmacKey(string $tenantId): string
            {
                return 'rotated-hmac';
            }
        };
        $service->setVaultProvider($vault);

        $cipher = $service->encrypt('999000999', 'tenant-z');
        $plain  = $service->decrypt($cipher, 'tenant-z');
        $this->assertSame('999000999', $plain);
    }//end testVaultProviderPrecedence()

    /**
     * Malformed ciphertext payload throws.
     *
     * @return void
     */
    public function testDecryptRejectsMalformedPayload(): void
    {
        $service = $this->buildService();
        $this->expectException(\RuntimeException::class);
        $service->decrypt('not-base64-ciphertext', 'tenant-a');
    }//end testDecryptRejectsMalformedPayload()
}//end class
