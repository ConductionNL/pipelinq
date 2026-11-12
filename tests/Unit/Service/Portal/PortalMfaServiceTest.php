<?php

/**
 * Unit tests for PortalMfaService.
 *
 * Portal two-factor codes are RFC 6238 TOTP (SHA-1, six digits, 30-second
 * steps, one step of clock skew). Verified against the RFC's published test
 * vectors, plus the refusals: no secret, a malformed code, an undecryptable
 * secret and a code outside the skew window.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalMfaService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for PortalMfaService.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalMfaService
 */
class PortalMfaServiceTest extends TestCase {

	/**
	 * The RFC 6238 SHA-1 test secret "12345678901234567890" in base32.
	 */
	private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

	/**
	 * Mock crypto; decrypting "enc:X" yields X.
	 *
	 * @var ICrypto&MockObject
	 */
	private ICrypto&MockObject $crypto;

	/**
	 * Mock clock.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $time;

	/**
	 * Service under test.
	 *
	 * @var PortalMfaService
	 */
	private PortalMfaService $service;

	/**
	 * Wire the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturn('12345678901234567890');
		$this->crypto = $this->createMock(originalClassName: ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc:' . $plain);
		$this->crypto->method('decrypt')->willReturnCallback(
			static function (string $cipher): string {
				if (str_starts_with($cipher, 'enc:') === false) {
					throw new RuntimeException('HMAC does not match');
				}

				return substr($cipher, 4);
			}
		);
		$this->time = $this->createMock(originalClassName: ITimeFactory::class);

		$this->service = new PortalMfaService(secureRandom: $random, crypto: $this->crypto, time: $this->time);
	}//end setUp()

	/**
	 * RFC 6238 appendix B times and their six-digit SHA-1 codes.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function rfcVectors(): array {
		return [
			'59' => [59, '287082'],
			'1111111109' => [1111111109, '081804'],
			'1111111111' => [1111111111, '050471'],
			'1234567890' => [1234567890, '005924'],
			'2000000000' => [2000000000, '279037'],
		];
	}//end rfcVectors()

	/**
	 * The RFC test vectors verify.
	 *
	 * @param int $now The clock time.
	 * @param string $code The expected code.
	 *
	 * @return void
	 */
	#[DataProvider('rfcVectors')]
	public function testVerifiesTheRfcVectors(int $now, string $code): void {
		$this->time->method('getTime')->willReturn($now);

		self::assertTrue(condition: $this->service->verifyCode(encryptedSecret: 'enc:' . self::RFC_SECRET, code: ' ' . $code . ' '));
	}//end testVerifiesTheRfcVectors()

	/**
	 * One step of skew either way is accepted; two steps are not.
	 *
	 * @return void
	 */
	public function testAcceptsOneStepOfSkewOnly(): void {
		$this->time->method('getTime')->willReturnOnConsecutiveCalls(59 + 30, 59 - 30, 59 + 60);
		$secret = 'enc:' . self::RFC_SECRET;

		self::assertTrue(condition: $this->service->verifyCode(encryptedSecret: $secret, code: '287082'));
		self::assertTrue(condition: $this->service->verifyCode(encryptedSecret: $secret, code: '287082'));
		self::assertFalse(condition: $this->service->verifyCode(encryptedSecret: $secret, code: '287082'));
	}//end testAcceptsOneStepOfSkewOnly()

	/**
	 * Missing secrets, malformed codes and undecryptable secrets are refused.
	 *
	 * @return void
	 */
	public function testRefusesUnusableInput(): void {
		$this->time->method('getTime')->willReturn(59);

		self::assertFalse(condition: $this->service->verifyCode(encryptedSecret: null, code: '287082'));
		self::assertFalse(condition: $this->service->verifyCode(encryptedSecret: '', code: '287082'));
		self::assertFalse(condition: $this->service->verifyCode(encryptedSecret: 'enc:' . self::RFC_SECRET, code: null));
		self::assertFalse(condition: $this->service->verifyCode(encryptedSecret: 'enc:' . self::RFC_SECRET, code: '28708'));
		self::assertFalse(condition: $this->service->verifyCode(encryptedSecret: 'enc:' . self::RFC_SECRET, code: '28708a'));
		self::assertFalse(condition: $this->service->verifyCode(encryptedSecret: 'tampered', code: '287082'));
		self::assertFalse(condition: $this->service->verifyCode(encryptedSecret: 'enc:' . self::RFC_SECRET, code: '287083'));
	}//end testRefusesUnusableInput()

	/**
	 * A generated secret is the base32 form of the random bytes and verifies its own codes.
	 *
	 * @return void
	 */
	public function testGeneratesAndEncryptsABase32Secret(): void {
		$secret = $this->service->generateSecret();

		self::assertSame(expected: self::RFC_SECRET, actual: $secret);
		self::assertSame(expected: 'enc:' . self::RFC_SECRET, actual: $this->service->encryptSecret(secret: $secret));
	}//end testGeneratesAndEncryptsABase32Secret()

	/**
	 * A secret whose length is not a multiple of five bits is padded on its last character.
	 *
	 * @return void
	 */
	public function testGeneratesAPaddedSecretForAnUnevenLength(): void {
		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturn('a');
		$service = new PortalMfaService(secureRandom: $random, crypto: $this->crypto, time: $this->time);

		self::assertSame(expected: 'ME', actual: $service->generateSecret());
	}//end testGeneratesAPaddedSecretForAnUnevenLength()

	/**
	 * The provisioning URI carries the encoded issuer and account and the TOTP parameters.
	 *
	 * @return void
	 */
	public function testBuildsTheProvisioningUri(): void {
		self::assertSame(
			expected: 'otpauth://totp/Gemeente%20Zuiddrecht:jan%40example.org?secret=ABC&issuer=Gemeente%20Zuiddrecht&algorithm=SHA1&digits=6&period=30',
			actual: $this->service->provisioningUri(secret: 'ABC', accountLabel: 'jan@example.org', issuer: 'Gemeente Zuiddrecht')
		);
	}//end testBuildsTheProvisioningUri()
}//end class
