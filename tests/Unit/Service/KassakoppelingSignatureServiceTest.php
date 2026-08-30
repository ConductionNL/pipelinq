<?php

/**
 * Unit tests for KassakoppelingSignatureService.
 *
 * Covers the HMAC-SHA256 signature generation + verification, the SHA-256
 * chain-hash generation + per-register chain verification, the canonical
 * field ordering, the genesis sentinel ('0') for the first entry on a
 * register, and the secret-key resolution (explicit app-config secret
 * versus the deterministic instance-id-derived fallback).
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\KassakoppelingSignatureService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for KassakoppelingSignatureService.
 *
 * The two cryptographic primitives are deterministic and pure (same inputs
 * always produce the same hex digest), so the assertions are exact equality
 * checks against pre-computed reference values.
 */
class KassakoppelingSignatureServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var KassakoppelingSignatureService
	 */
	private KassakoppelingSignatureService $service;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mock system config.
	 *
	 * @var IConfig&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IConfig $config;

	/**
	 * Build the service with an explicit secret so signatures stay reproducible.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->config = $this->createMock(originalClassName: IConfig::class);

		$this->appConfig->method('getValueString')->willReturn('unit-test-secret');

		$this->service = new KassakoppelingSignatureService(
			appConfig: $this->appConfig,
			config: $this->config,
		);

	}//end setUp()

	/**
	 * A reusable sale entry the chain hash tests build on.
	 *
	 * @return array<string, mixed>
	 */
	private function saleEntry(): array {
		return [
			'operatorId' => 'user_john',
			'registerNumber' => 'REG-001',
			'action' => 'sale',
			'amount' => 4950,
			'itemCount' => 3,
			'taxAmount' => 870,
			'timestamp' => '2026-05-20T08:15:30+00:00',
			'transactionUuid' => 'uuid-txn-001',
			'description' => 'Regular sale',
		];

	}//end saleEntry()

	/**
	 * generateSignature() returns a 64-char hex HMAC-SHA256 over the canonical
	 * pipe-joined fields.
	 *
	 * @return void
	 */
	public function testGenerateSignatureReturnsHmacSha256Hex(): void {
		$entry = $this->saleEntry();
		$entry['previousHash'] = '0';

		$signature = $this->service->generateSignature(entryData: $entry);

		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);

		$expected = hash_hmac(
			'sha256',
			'user_john|REG-001|sale|4950|870|2026-05-20T08:15:30+00:00|0',
			'unit-test-secret'
		);
		$this->assertSame($expected, $signature);

	}//end testGenerateSignatureReturnsHmacSha256Hex()

	/**
	 * verifySignature() returns true for the round-tripped HMAC.
	 *
	 * @return void
	 */
	public function testVerifySignaturePasses(): void {
		$entry = $this->saleEntry();
		$entry['previousHash'] = '0';

		$signature = $this->service->generateSignature(entryData: $entry);
		$this->assertTrue($this->service->verifySignature(entryData: $entry, signature: $signature));

	}//end testVerifySignaturePasses()

	/**
	 * verifySignature() returns false after a signed field is tampered.
	 *
	 * @return void
	 */
	public function testVerifySignatureFailsWhenAmountTampered(): void {
		$entry = $this->saleEntry();
		$entry['previousHash'] = '0';
		$signature = $this->service->generateSignature(entryData: $entry);

		$entry['amount'] = 9999;
		$this->assertFalse($this->service->verifySignature(entryData: $entry, signature: $signature));

	}//end testVerifySignatureFailsWhenAmountTampered()

	/**
	 * verifySignature() rejects empty signatures (defensive guard).
	 *
	 * @return void
	 */
	public function testVerifySignatureFailsForEmptySignature(): void {
		$entry = $this->saleEntry();
		$entry['previousHash'] = '0';
		$this->assertFalse($this->service->verifySignature(entryData: $entry, signature: ''));

	}//end testVerifySignatureFailsForEmptySignature()

	/**
	 * generateHash() returns a 64-char hex SHA-256 over the wider canonical
	 * field set (including itemCount, transactionUuid, description).
	 *
	 * @return void
	 */
	public function testGenerateHashReturnsSha256Hex(): void {
		$entry = $this->saleEntry();
		$hash = $this->service->generateHash(entryData: $entry, previousHash: '0');

		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);

		$expected = hash(
			'sha256',
			'user_john|REG-001|sale|4950|3|870|2026-05-20T08:15:30+00:00|uuid-txn-001|Regular sale|0'
		);
		$this->assertSame($expected, $hash);

	}//end testGenerateHashReturnsSha256Hex()

	/**
	 * Tampering a non-signed field (description) still breaks the chain
	 * hash — the chain hash covers fields the signature does not.
	 *
	 * @return void
	 */
	public function testGenerateHashChangesWhenDescriptionTampered(): void {
		$entry = $this->saleEntry();
		$originalHash = $this->service->generateHash(entryData: $entry, previousHash: '0');

		$entry['description'] = 'tampered';
		$tamperedHash = $this->service->generateHash(entryData: $entry, previousHash: '0');

		$this->assertNotSame($originalHash, $tamperedHash);

	}//end testGenerateHashChangesWhenDescriptionTampered()

	/**
	 * verifyHashChain() reports chainValid=true for a clean per-register chain
	 * starting at the genesis hash.
	 *
	 * @return void
	 */
	public function testVerifyHashChainValidates(): void {
		$first = $this->saleEntry();
		$first['previousHash'] = KassakoppelingSignatureService::GENESIS_HASH;
		$first['currentHash'] = $this->service->generateHash(
			entryData: $first,
			previousHash: $first['previousHash']
		);

		$second = $this->saleEntry();
		$second['action'] = 'void';
		$second['timestamp'] = '2026-05-20T08:18:15+00:00';
		$second['previousHash'] = $first['currentHash'];
		$second['currentHash'] = $this->service->generateHash(
			entryData: $second,
			previousHash: $second['previousHash']
		);

		$result = $this->service->verifyHashChain(entries: [$first, $second]);

		$this->assertTrue($result['chainValid']);
		$this->assertNull($result['brokenAt']);
		$this->assertSame(2, $result['unbrokenLinks']);

	}//end testVerifyHashChainValidates()

	/**
	 * verifyHashChain() reports chainValid=false at the broken link when a
	 * later entry's previousHash does not match the prior currentHash.
	 *
	 * @return void
	 */
	public function testVerifyHashChainDetectsBreak(): void {
		$first = $this->saleEntry();
		$first['previousHash'] = KassakoppelingSignatureService::GENESIS_HASH;
		$first['currentHash'] = $this->service->generateHash(
			entryData: $first,
			previousHash: $first['previousHash']
		);

		$second = $this->saleEntry();
		$second['action'] = 'void';
		$second['timestamp'] = '2026-05-20T08:18:15+00:00';
		// Wrong previousHash on purpose.
		$second['previousHash'] = '0';
		$second['currentHash'] = $this->service->generateHash(
			entryData: $second,
			previousHash: $second['previousHash']
		);

		$result = $this->service->verifyHashChain(entries: [$first, $second]);

		$this->assertFalse($result['chainValid']);
		$this->assertSame(2, $result['brokenAt']);
		$this->assertSame(1, $result['unbrokenLinks']);

	}//end testVerifyHashChainDetectsBreak()

	/**
	 * verifyHashChain() detects a tampered currentHash on a link whose
	 * previousHash still matches (the hash digest itself was corrupted).
	 *
	 * @return void
	 */
	public function testVerifyHashChainDetectsCurrentHashTamper(): void {
		$first = $this->saleEntry();
		$first['previousHash'] = KassakoppelingSignatureService::GENESIS_HASH;
		$first['currentHash'] = $this->service->generateHash(
			entryData: $first,
			previousHash: $first['previousHash']
		);

		$first['currentHash'] = str_repeat('a', 64);

		$result = $this->service->verifyHashChain(entries: [$first]);

		$this->assertFalse($result['chainValid']);
		$this->assertSame(1, $result['brokenAt']);

	}//end testVerifyHashChainDetectsCurrentHashTamper()

	/**
	 * verifyHashChain() handles an empty chain (vacuously valid).
	 *
	 * @return void
	 */
	public function testVerifyHashChainEmpty(): void {
		$result = $this->service->verifyHashChain(entries: []);

		$this->assertTrue($result['chainValid']);
		$this->assertNull($result['brokenAt']);
		$this->assertSame(0, $result['unbrokenLinks']);

	}//end testVerifyHashChainEmpty()

	/**
	 * getSecretKey() returns the explicit app-config value when set.
	 *
	 * @return void
	 */
	public function testGetSecretKeyPrefersConfigured(): void {
		$this->assertSame('unit-test-secret', $this->service->getSecretKey());

	}//end testGetSecretKeyPrefersConfigured()

	/**
	 * getSecretKey() falls back to the deterministic instance-id derivation
	 * when no explicit secret is configured.
	 *
	 * @return void
	 */
	public function testGetSecretKeyFallsBackToInstanceId(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getSystemValue')->willReturn('inst-abc');

		$service = new KassakoppelingSignatureService(appConfig: $appConfig, config: $config);
		$expected = hash('sha256', 'pipelinq:kassakoppeling:inst-abc');

		$this->assertSame($expected, $service->getSecretKey());

	}//end testGetSecretKeyFallsBackToInstanceId()

	/**
	 * getSecretKey() throws when neither an explicit secret nor an instance
	 * id is available (defensive guard for unit-test stub configs).
	 *
	 * @return void
	 */
	public function testGetSecretKeyThrowsWhenNoMaterialAvailable(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getSystemValue')->willReturn('');

		$service = new KassakoppelingSignatureService(appConfig: $appConfig, config: $config);

		$this->expectException(exception: RuntimeException::class);
		$service->getSecretKey();

	}//end testGetSecretKeyThrowsWhenNoMaterialAvailable()

}//end class
