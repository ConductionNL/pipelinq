<?php

/**
 * Unit tests for EncryptionService.
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

use OCA\Pipelinq\Exception\CryptoException;
use OCA\Pipelinq\Service\EncryptionService;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EncryptionService.
 */
class EncryptionServiceTest extends TestCase
{
    /**
     * In-memory app config store.
     *
     * @var array<string, string>
     */
    private array $store = [];

    /**
     * The service under test.
     *
     * @var EncryptionService
     */
    private EncryptionService $service;

    /**
     * Set up an EncryptionService backed by an in-memory app config.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->store = [];

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default=''): string {
                return ($this->store[$key] ?? $default);
            }
        );
        $appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->store[$key] = $value;
                return true;
            }
        );

        $secureRandom = $this->createMock(ISecureRandom::class);
        $counter      = 0;
        $secureRandom->method('generate')->willReturnCallback(
            function (int $length) use (&$counter): string {
                $counter++;
                return substr(str_repeat(md5('seed'.$counter), 4), 0, $length);
            }
        );

        $this->service = new EncryptionService(
            $appConfig,
            $secureRandom,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Encrypt then decrypt returns the original plaintext.
     *
     * @return void
     */
    public function testEncryptDecryptRoundTrip(): void
    {
        $bsn        = '123456782';
        $ciphertext = $this->service->encrypt($bsn);

        $this->assertNotSame($bsn, $ciphertext);
        $this->assertStringNotContainsString($bsn, base64_decode($ciphertext));
        $this->assertSame($bsn, $this->service->decrypt($ciphertext));
    }//end testEncryptDecryptRoundTrip()

    /**
     * The same BSN hashes deterministically; different BSNs differ.
     *
     * @return void
     */
    public function testHashIsDeterministicAndDistinct(): void
    {
        $h1 = $this->service->hashBsn('123456782');
        $h2 = $this->service->hashBsn('123456782');
        $h3 = $this->service->hashBsn('111222333');

        $this->assertSame($h1, $h2);
        $this->assertNotSame($h1, $h3);
        $this->assertSame(64, strlen($h1));
    }//end testHashIsDeterministicAndDistinct()

    /**
     * A tampered ciphertext fails the GCM tag check.
     *
     * @return void
     */
    public function testTamperedCiphertextThrows(): void
    {
        $ciphertext = $this->service->encrypt('123456782');
        $raw        = base64_decode($ciphertext);
        $raw[strlen($raw) - 1] = chr((ord($raw[strlen($raw) - 1]) ^ 0xFF));

        $this->expectException(CryptoException::class);
        $this->service->decrypt(base64_encode($raw));
    }//end testTamperedCiphertextThrows()

    /**
     * A malformed envelope is rejected.
     *
     * @return void
     */
    public function testMalformedEnvelopeThrows(): void
    {
        $this->expectException(CryptoException::class);
        $this->service->decrypt('not-base64-or-too-short');
    }//end testMalformedEnvelopeThrows()

    /**
     * A shredded value can never be decrypted back to the original.
     *
     * @return void
     */
    public function testShredIsUndecryptable(): void
    {
        $shred = $this->service->shred();

        $this->expectException(CryptoException::class);
        $this->service->decrypt($shred);
    }//end testShredIsUndecryptable()

    /**
     * Masking hides the middle of a BSN.
     *
     * @return void
     */
    public function testMaskHidesMiddle(): void
    {
        $this->assertSame('1*******2', EncryptionService::mask('123456782'));
        $this->assertSame('**', EncryptionService::mask('99'));
    }//end testMaskHidesMiddle()
}//end class
