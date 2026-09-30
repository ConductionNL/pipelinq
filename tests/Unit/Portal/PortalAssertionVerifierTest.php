<?php

/**
 * Unit tests for PortalAssertionVerifier.
 *
 * Pins the fleet-reference A6 receiver contract: the full fail-closed
 * rejection matrix (structure, algorithm, signature, use/iss/exp/iat/sub),
 * both secret-derivation branches (portaliq app-config value, instance-secret
 * fallback — mocked IConfig), and — most importantly — the ROUND-TRIP
 * compatibility pin: assertions are minted here with the byte-identical
 * procedure portaliq's PortalJwtService::createAssertion() uses (same header,
 * same claim set, same base64url encoding, same HMAC input), so any token
 * format drift on either side fails this suite instead of production
 * forwards. Where config sourcing is not under test, the verifier is
 * constructed with a plain secret (portaliq's PortalJwtService testability
 * pattern).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Portal;

use OCA\Pipelinq\Portal\PortalAssertionVerifier;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalAssertionVerifier.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */
class PortalAssertionVerifierTest extends TestCase {

	/**
	 * A >= 16 char signing secret for plain-secret construction.
	 */
	private const SECRET = 'pipelinq-test-secret-0123456789';

	/**
	 * A different valid-length secret for forged-signature tests.
	 */
	private const OTHER_SECRET = 'attacker-secret-9876543210abcd';

	/**
	 * The owner-contact UUID used as the asserted subject.
	 */
	private const SUBJECT = '00000000-0000-0000-0000-000000000000';

	/**
	 * Mint an assertion EXACTLY the way portaliq does.
	 *
	 * Byte-identical replica of PortalJwtService::createAssertion(): same
	 * header, same claim set and order, JSON_UNESCAPED_SLASHES, unpadded
	 * base64url, HMAC-SHA256 over "header.payload". Keep this helper in sync
	 * with portaliq — that is the point of the round-trip pin.
	 *
	 * @param string $secret    The HMAC signing secret.
	 * @param array  $overrides Claim overrides / additions (null removes a claim).
	 * @param string $alg       Header algorithm.
	 *
	 * @return string Compact JWT.
	 */
	private function mintAssertion(string $secret, array $overrides=[], string $alg='HS256'): string {
		$iat    = time();
		$claims = [
			'sub'          => self::SUBJECT,
			'audience'     => 'client',
			'organisation' => '11111111-1111-1111-1111-111111111111',
			'trust'        => 'substantial',
			'jti'          => 'sessionjti0000000000000000000000',
			'use'          => 'assertion',
			'iat'          => $iat,
			'exp'          => ($iat + 60),
			'iss'          => 'portaliq',
		];

		foreach ($overrides as $claim => $value) {
			if ($value === null) {
				unset($claims[$claim]);
				continue;
			}

			$claims[$claim] = $value;
		}

		$header = ['alg' => $alg, 'typ' => 'JWT'];
		$hPart  = $this->b64UrlEncode(bytes: (string) json_encode($header, JSON_UNESCAPED_SLASHES));
		$cPart  = $this->b64UrlEncode(bytes: (string) json_encode($claims, JSON_UNESCAPED_SLASHES));
		$sig    = $this->b64UrlEncode(bytes: hash_hmac('sha256', $hPart.'.'.$cPart, $secret, true));

		return $hPart.'.'.$cPart.'.'.$sig;

	}//end mintAssertion()

	/**
	 * Base64-url encode (no padding) — portaliq's encoding.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

	}//end b64UrlEncode()

	/**
	 * A verifier with a plain secret (config sourcing not under test).
	 *
	 * @return PortalAssertionVerifier
	 */
	private function verifier(): PortalAssertionVerifier {
		return new PortalAssertionVerifier(config: null, secretOverride: self::SECRET);

	}//end verifier()

	/**
	 * ROUND-TRIP PIN: a portaliq-style mint verifies losslessly.
	 *
	 * @return void
	 */
	public function testPortaliqRoundTripVerifies(): void {
		$claims = $this->verifier()->verify($this->mintAssertion(secret: self::SECRET));

		$this->assertIsArray($claims);
		$this->assertSame(self::SUBJECT, $claims['sub']);
		$this->assertSame('client', $claims['audience']);
		$this->assertSame('11111111-1111-1111-1111-111111111111', $claims['organisation']);
		$this->assertSame('substantial', $claims['trust']);
		$this->assertSame('sessionjti0000000000000000000000', $claims['jti']);
		$this->assertSame('assertion', $claims['use']);
		$this->assertSame('portaliq', $claims['iss']);

	}//end testPortaliqRoundTripVerifies()

	/**
	 * An expired assertion (or one without exp) is rejected.
	 *
	 * @return void
	 */
	public function testExpiredOrMissingExpIsRejected(): void {
		$now = time();

		$expired = $this->mintAssertion(secret: self::SECRET, overrides: ['iat' => ($now - 120), 'exp' => ($now - 60)]);
		$this->assertNull($this->verifier()->verify($expired));

		$noExp = $this->mintAssertion(secret: self::SECRET, overrides: ['exp' => null]);
		$this->assertNull($this->verifier()->verify($noExp));

		$stringExp = $this->mintAssertion(secret: self::SECRET, overrides: ['exp' => (string) ($now + 60)]);
		$this->assertNull($this->verifier()->verify($stringExp));

	}//end testExpiredOrMissingExpIsRejected()

	/**
	 * A token signed with a different secret is rejected (forged signature).
	 *
	 * @return void
	 */
	public function testBadSignatureIsRejected(): void {
		$forged = $this->mintAssertion(secret: self::OTHER_SECRET);
		$this->assertNull($this->verifier()->verify($forged));

		// Tampered payload with the original signature.
		$token          = $this->mintAssertion(secret: self::SECRET);
		$parts          = explode('.', $token);
		$tampered       = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/').'=='), true);
		$tampered['sub'] = '22222222-2222-2222-2222-222222222222';
		$parts[1]       = $this->b64UrlEncode(bytes: (string) json_encode($tampered, JSON_UNESCAPED_SLASHES));
		$this->assertNull($this->verifier()->verify(implode('.', $parts)));

	}//end testBadSignatureIsRejected()

	/**
	 * Token-confusion guard: a portal SESSION token (no `use` claim, or a
	 * non-assertion value) can never drive a domain endpoint.
	 *
	 * @return void
	 */
	public function testSessionTokenAndWrongUseAreRejected(): void {
		// Portaliq session tokens carry roles and NO `use` claim.
		$session = $this->mintAssertion(secret: self::SECRET, overrides: ['use' => null, 'roles' => ['viewer']]);
		$this->assertNull($this->verifier()->verify($session));

		$wrongUse = $this->mintAssertion(secret: self::SECRET, overrides: ['use' => 'session']);
		$this->assertNull($this->verifier()->verify($wrongUse));

	}//end testSessionTokenAndWrongUseAreRejected()

	/**
	 * `alg: none` — with or without a signature segment — is rejected.
	 *
	 * @return void
	 */
	public function testNoneAlgIsRejected(): void {
		// Signed shape but header says none.
		$noneSigned = $this->mintAssertion(secret: self::SECRET, alg: 'none');
		$this->assertNull($this->verifier()->verify($noneSigned));

		// Classic unsecured-JWT shape: empty signature segment.
		[$hPart, $cPart] = explode('.', $noneSigned);
		$this->assertNull($this->verifier()->verify($hPart.'.'.$cPart.'.'));

	}//end testNoneAlgIsRejected()

	/**
	 * Any non-HS256 algorithm header is rejected (exact match only).
	 *
	 * @return void
	 */
	public function testWrongAlgIsRejected(): void {
		$this->assertNull($this->verifier()->verify($this->mintAssertion(secret: self::SECRET, alg: 'HS512')));
		$this->assertNull($this->verifier()->verify($this->mintAssertion(secret: self::SECRET, alg: 'hs256')));
		$this->assertNull($this->verifier()->verify($this->mintAssertion(secret: self::SECRET, alg: 'RS256')));

	}//end testWrongAlgIsRejected()

	/**
	 * Structural garbage never throws — it just returns null.
	 *
	 * @return void
	 */
	public function testGarbageIsRejectedWithoutThrowing(): void {
		$verifier = $this->verifier();

		$this->assertNull($verifier->verify(''));
		$this->assertNull($verifier->verify('not-a-jwt'));
		$this->assertNull($verifier->verify('a.b'));
		$this->assertNull($verifier->verify('a.b.c.d'));
		$this->assertNull($verifier->verify('..'));
		$this->assertNull($verifier->verify("\x00\xff binary junk"));
		// Valid-looking segments that are not JSON.
		$this->assertNull($verifier->verify('bm90LWpzb24.bm90LWpzb24.bm90LWpzb24'));

	}//end testGarbageIsRejectedWithoutThrowing()

	/**
	 * An assertion without a usable subject authorises nothing.
	 *
	 * @return void
	 */
	public function testEmptyOrMissingSubIsRejected(): void {
		$this->assertNull($this->verifier()->verify($this->mintAssertion(secret: self::SECRET, overrides: ['sub' => ''])));
		$this->assertNull($this->verifier()->verify($this->mintAssertion(secret: self::SECRET, overrides: ['sub' => null])));
		$this->assertNull($this->verifier()->verify($this->mintAssertion(secret: self::SECRET, overrides: ['sub' => 42])));

	}//end testEmptyOrMissingSubIsRejected()

	/**
	 * Implausible iat values (future beyond leeway, after exp, missing) are rejected.
	 *
	 * @return void
	 */
	public function testImplausibleIatIsRejected(): void {
		$now = time();

		$future = $this->mintAssertion(secret: self::SECRET, overrides: ['iat' => ($now + 3600), 'exp' => ($now + 7200)]);
		$this->assertNull($this->verifier()->verify($future));

		$afterExp = $this->mintAssertion(secret: self::SECRET, overrides: ['iat' => ($now + 30), 'exp' => ($now + 10)]);
		$this->assertNull($this->verifier()->verify($afterExp));

		$noIat = $this->mintAssertion(secret: self::SECRET, overrides: ['iat' => null]);
		$this->assertNull($this->verifier()->verify($noIat));

	}//end testImplausibleIatIsRejected()

	/**
	 * A wrong issuer is rejected — only portaliq mints assertions.
	 *
	 * @return void
	 */
	public function testWrongIssuerIsRejected(): void {
		$this->assertNull($this->verifier()->verify($this->mintAssertion(secret: self::SECRET, overrides: ['iss' => 'procest'])));
		$this->assertNull($this->verifier()->verify($this->mintAssertion(secret: self::SECRET, overrides: ['iss' => null])));

	}//end testWrongIssuerIsRejected()

	/**
	 * Secret derivation branch 1: the dedicated portaliq app-config secret
	 * takes precedence — tokens signed with the instance secret then fail.
	 *
	 * @return void
	 */
	public function testAppConfigSecretTakesPrecedence(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with('portaliq', 'jwt_signing_secret', '')
			->willReturn(self::SECRET);
		$config->method('getSystemValue')
			->with('secret', str_pad('portaliq', 32, '_'))
			->willReturn(self::OTHER_SECRET);

		$verifier = new PortalAssertionVerifier(config: $config);

		$this->assertIsArray($verifier->verify($this->mintAssertion(secret: self::SECRET)));
		$this->assertNull($verifier->verify($this->mintAssertion(secret: self::OTHER_SECRET)));

	}//end testAppConfigSecretTakesPrecedence()

	/**
	 * Secret derivation branch 2: empty or too-short app-config values fall
	 * back to the Nextcloud instance secret — exactly like portaliq.
	 *
	 * @return void
	 */
	public function testInstanceSecretFallback(): void {
		foreach (['', 'short'] as $appValue) {
			$config = $this->createMock(IConfig::class);
			$config->method('getAppValue')
				->with('portaliq', 'jwt_signing_secret', '')
				->willReturn($appValue);
			$config->method('getSystemValue')
				->with('secret', str_pad('portaliq', 32, '_'))
				->willReturn(self::SECRET);

			$verifier = new PortalAssertionVerifier(config: $config);

			$this->assertIsArray($verifier->verify($this->mintAssertion(secret: self::SECRET)));

		}

	}//end testInstanceSecretFallback()

	/**
	 * No usable secret (< 16 chars, or neither config nor override) fails
	 * closed even for a correctly signed token.
	 *
	 * @return void
	 */
	public function testUnusableSecretFailsClosed(): void {
		$shortSecret = new PortalAssertionVerifier(config: null, secretOverride: 'short');
		$this->assertNull($shortSecret->verify($this->mintAssertion(secret: 'short')));

		$noSource = new PortalAssertionVerifier();
		$this->assertNull($noSource->verify($this->mintAssertion(secret: self::SECRET)));

	}//end testUnusableSecretFailsClosed()
}//end class
