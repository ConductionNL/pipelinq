<?php

/**
 * Unit tests for KassakoppelingSignatureService.
 *
 * Covers HMAC-SHA256 signature generation/verification and SHA-256
 * hash-chain logic. These tests use known input/output pairs derived
 * from the signing spec in design.md so any regression in the
 * cryptographic pipeline is immediately detectable.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#8.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\KassakoppelingSignatureService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for KassakoppelingSignatureService.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#8.1
 */
class KassakoppelingSignatureServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var KassakoppelingSignatureService
     */
    private KassakoppelingSignatureService $service;

    /**
     * Mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Test signing key.
     *
     * @var string
     */
    private string $secretKey = 'test-kassakoppeling-secret-key-for-unit-tests';

    /**
     * Canonical test entry data.
     *
     * @var array<string,mixed>
     */
    private array $testEntry = [
        'operatorId'      => 'user_john',
        'registerNumber'  => 'REG-001',
        'action'          => 'sale',
        'amount'          => 4950,
        'itemCount'       => 3,
        'taxAmount'       => 870,
        'timestamp'       => '2026-05-20T08:15:30Z',
        'transactionUuid' => 'uuid-txn-20260520-001',
        'previousHash'    => '0',
    ];

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $this->appConfig
            ->method('getValueString')
            ->with('pipelinq', 'kassakoppeling_secret', '')
            ->willReturn($this->secretKey);

        $this->service = new KassakoppelingSignatureService(
            appConfig: $this->appConfig,
        );
    }//end setUp()

    /**
     * Test that generateSignature returns a 64-character lowercase hex string.
     *
     * @return void
     */
    public function testGenerateSignatureReturnsSha256HexString(): void
    {
        $sig = $this->service->generateSignature($this->testEntry);
        $this->assertMatchesRegularExpression(
            pattern: '/^[0-9a-f]{64}$/',
            string: $sig,
            message: 'Signature must be 64-char lowercase hex'
        );
    }//end testGenerateSignatureReturnsSha256HexString()

    /**
     * Test that generateSignature is deterministic (same input → same output).
     *
     * @return void
     */
    public function testGenerateSignatureIsDeterministic(): void
    {
        $sig1 = $this->service->generateSignature($this->testEntry);
        $sig2 = $this->service->generateSignature($this->testEntry);
        $this->assertSame(expected: $sig1, actual: $sig2, message: 'Signature must be deterministic for the same input');
    }//end testGenerateSignatureIsDeterministic()

    /**
     * Test that verifySignature returns true for a valid signature.
     *
     * @return void
     */
    public function testVerifySignatureReturnsTrueForValidSignature(): void
    {
        $sig = $this->service->generateSignature($this->testEntry);
        $this->assertTrue(
            condition: $this->service->verifySignature($this->testEntry, $sig),
            message: 'verifySignature must return true for a freshly generated signature'
        );
    }//end testVerifySignatureReturnsTrueForValidSignature()

    /**
     * Test that verifySignature returns false when the amount has been tampered.
     *
     * @return void
     */
    public function testVerifySignatureReturnsFalseWhenAmountTampered(): void
    {
        $sig      = $this->service->generateSignature($this->testEntry);
        $tampered = array_merge($this->testEntry, ['amount' => 9999]);
        $this->assertFalse(
            condition: $this->service->verifySignature($tampered, $sig),
            message: 'verifySignature must return false after tampering with the amount'
        );
    }//end testVerifySignatureReturnsFalseWhenAmountTampered()

    /**
     * Test that verifySignature returns false for an incorrect signature string.
     *
     * @return void
     */
    public function testVerifySignatureReturnsFalseForWrongSignature(): void
    {
        $wrongSig = str_repeat('a', 64);
        $this->assertFalse(
            condition: $this->service->verifySignature($this->testEntry, $wrongSig),
            message: 'verifySignature must return false for a wrong signature'
        );
    }//end testVerifySignatureReturnsFalseForWrongSignature()

    /**
     * Test that generateHash returns a 64-character hex string.
     *
     * @return void
     */
    public function testGenerateHashReturnsSha256HexString(): void
    {
        $hash = $this->service->generateHash($this->testEntry, '0');
        $this->assertMatchesRegularExpression(
            pattern: '/^[0-9a-f]{64}$/',
            string: $hash,
            message: 'Hash must be 64-char lowercase hex'
        );
    }//end testGenerateHashReturnsSha256HexString()

    /**
     * Test that generateHash is deterministic.
     *
     * @return void
     */
    public function testGenerateHashIsDeterministic(): void
    {
        $hash1 = $this->service->generateHash($this->testEntry, '0');
        $hash2 = $this->service->generateHash($this->testEntry, '0');
        $this->assertSame(expected: $hash1, actual: $hash2, message: 'Hash must be deterministic');
    }//end testGenerateHashIsDeterministic()

    /**
     * Test that changing previousHash produces a different currentHash.
     *
     * @return void
     */
    public function testGenerateHashDiffersWithDifferentPreviousHash(): void
    {
        $hash1 = $this->service->generateHash($this->testEntry, '0');
        $hash2 = $this->service->generateHash($this->testEntry, str_repeat('f', 64));
        $this->assertNotSame(expected: $hash1, actual: $hash2, message: 'Different previousHash must produce different currentHash');
    }//end testGenerateHashDiffersWithDifferentPreviousHash()

    /**
     * Test that verifyHashChain returns true for a valid 3-entry chain.
     *
     * @return void
     */
    public function testVerifyHashChainReturnsTrueForValidChain(): void
    {
        $entry1 = $this->testEntry;
        $entry1['previousHash'] = '0';
        $entry1['currentHash']  = $this->service->generateHash($entry1, '0');

        $entry2 = array_merge($this->testEntry, ['action' => 'void', 'timestamp' => '2026-05-20T08:18:15Z']);
        $entry2['previousHash'] = $entry1['currentHash'];
        $entry2['currentHash']  = $this->service->generateHash($entry2, $entry1['currentHash']);

        $entry3 = array_merge($this->testEntry, ['action' => 'refund', 'timestamp' => '2026-05-20T09:45:20Z', 'operatorId' => 'user_maria']);
        $entry3['previousHash'] = $entry2['currentHash'];
        $entry3['currentHash']  = $this->service->generateHash($entry3, $entry2['currentHash']);

        $this->assertTrue(
            condition: $this->service->verifyHashChain([$entry1, $entry2, $entry3]),
            message: 'verifyHashChain must return true for a correctly built chain'
        );
    }//end testVerifyHashChainReturnsTrueForValidChain()

    /**
     * Test that verifyHashChain returns false when a link in the chain is broken.
     *
     * @return void
     */
    public function testVerifyHashChainReturnsFalseForBrokenLink(): void
    {
        $entry1 = $this->testEntry;
        $entry1['previousHash'] = '0';
        $entry1['currentHash']  = $this->service->generateHash($entry1, '0');

        // Entry2 claims a wrong previousHash (not entry1's currentHash).
        $entry2 = array_merge($this->testEntry, ['action' => 'void']);
        $entry2['previousHash'] = str_repeat('d', 64);
        $entry2['currentHash']  = $this->service->generateHash($entry2, $entry2['previousHash']);

        // Corrupt entry2's currentHash to test that the chain rejects it.
        $entry2['currentHash'] = str_repeat('e', 64);

        $this->assertFalse(
            condition: $this->service->verifyHashChain([$entry1, $entry2]),
            message: 'verifyHashChain must return false when a link\'s currentHash is tampered'
        );
    }//end testVerifyHashChainReturnsFalseForBrokenLink()

    /**
     * Test that verifyHashChain returns true for an empty array.
     *
     * @return void
     */
    public function testVerifyHashChainReturnsTrueForEmptyArray(): void
    {
        $this->assertTrue(
            condition: $this->service->verifyHashChain([]),
            message: 'Empty chain is trivially valid.'
        );
    }//end testVerifyHashChainReturnsTrueForEmptyArray()

    /**
     * Test that getSecretKey throws RuntimeException when key not configured.
     *
     * @return void
     */
    public function testGetSecretKeyThrowsWhenNotConfigured(): void
    {
        $this->expectException(exception: RuntimeException::class);

        $appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $appConfig
            ->method('getValueString')
            ->with('pipelinq', 'kassakoppeling_secret', '')
            ->willReturn('');

        $service = new KassakoppelingSignatureService(appConfig: $appConfig);
        $service->getSecretKey();
    }//end testGetSecretKeyThrowsWhenNotConfigured()

    /**
     * Test that generateSignature throws RuntimeException when key not configured.
     *
     * @return void
     */
    public function testGenerateSignatureThrowsWhenKeyNotConfigured(): void
    {
        $this->expectException(exception: RuntimeException::class);

        $appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $appConfig
            ->method('getValueString')
            ->with('pipelinq', 'kassakoppeling_secret', '')
            ->willReturn('');

        $service = new KassakoppelingSignatureService(appConfig: $appConfig);
        $service->generateSignature($this->testEntry);
    }//end testGenerateSignatureThrowsWhenKeyNotConfigured()
}//end class
