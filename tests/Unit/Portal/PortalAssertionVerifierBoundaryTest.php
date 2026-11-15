<?php

/**
 * Boundary tests for PortalAssertionVerifier.
 *
 * Pins the exact edges of each check: the minimum secret length, the expiry
 * instant, the issued-at leeway, an issued-at equal to the expiry, claim
 * types, and that an accepted assertion's claims come back unchanged.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Portal;

use OCA\Pipelinq\Portal\PortalAssertionVerifier;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Edges of the assertion checks.
 *
 * @covers \OCA\Pipelinq\Portal\PortalAssertionVerifier
 */
class PortalAssertionVerifierBoundaryTest extends TestCase {

	/**
	 * A secret of exactly the minimum length (16).
	 */
	private const SECRET16 = '0123456789abcdef';

	/**
	 * A secret alone works at exactly sixteen characters and not at fifteen.
	 *
	 * @return void
	 */
	public function testMinimumSecretLengthIsSixteen(): void {
		$sixteen = new PortalAssertionVerifier(secretOverride: self::SECRET16);
		self::assertIsArray($sixteen->verify($this->mint(secret: self::SECRET16)));

		$fifteen = substr(self::SECRET16, 0, 15);
		$short = new PortalAssertionVerifier(secretOverride: $fifteen);
		self::assertNull($short->verify($this->mint(secret: $fifteen)));
	}//end testMinimumSecretLengthIsSixteen()

	/**
	 * The app-config secret is subject to the same minimum length.
	 *
	 * @return void
	 */
	public function testAppConfigSecretBelowMinimumFailsClosed(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('getValueString')
			->with('portaliq', 'jwt_signing_secret', '', false)
			->willReturn('fifteen-chars!!');

		$verifier = new PortalAssertionVerifier(appConfig: $appConfig);

		self::assertNull($verifier->verify($this->mint(secret: 'fifteen-chars!!')));
	}//end testAppConfigSecretBelowMinimumFailsClosed()

	/**
	 * An assertion whose expiry is now is already expired.
	 *
	 * @return void
	 */
	public function testExpiryAtNowIsRejected(): void {
		$now = time();
		$jwt = $this->mint(secret: self::SECRET16, overrides: ['iat' => ($now - 10), 'exp' => $now]);

		self::assertNull($this->verifier()->verify($jwt));
	}//end testExpiryAtNowIsRejected()

	/**
	 * An issued-at up to the leeway ahead of now is accepted.
	 *
	 * @return void
	 */
	public function testIssuedAtWithinLeewayIsAccepted(): void {
		$now = time();
		$jwt = $this->mint(secret: self::SECRET16, overrides: ['iat' => ($now + 60), 'exp' => ($now + 120)]);

		self::assertIsArray($this->verifier()->verify($jwt));
	}//end testIssuedAtWithinLeewayIsAccepted()

	/**
	 * An issued-at equal to the expiry is accepted; one second later is not.
	 *
	 * @return void
	 */
	public function testIssuedAtEqualToExpiryIsAccepted(): void {
		$now = time();
		$equal = $this->mint(secret: self::SECRET16, overrides: ['iat' => ($now + 30), 'exp' => ($now + 30)]);
		self::assertIsArray($this->verifier()->verify($equal));

		$after = $this->mint(secret: self::SECRET16, overrides: ['iat' => ($now + 31), 'exp' => ($now + 30)]);
		self::assertNull($this->verifier()->verify($after));
	}//end testIssuedAtEqualToExpiryIsAccepted()

	/**
	 * Numeric strings for exp or iat are not timestamps.
	 *
	 * @return void
	 */
	public function testStringTimestampsAreRejected(): void {
		$now = time();

		self::assertNull($this->verifier()->verify($this->mint(secret: self::SECRET16, overrides: ['exp' => (string) ($now + 60)])));
		self::assertNull($this->verifier()->verify($this->mint(secret: self::SECRET16, overrides: ['iat' => (string) $now])));
	}//end testStringTimestampsAreRejected()

	/**
	 * A validly signed payload that decodes to a JSON scalar is rejected.
	 *
	 * @return void
	 */
	public function testSignedScalarPayloadIsRejected(): void {
		$hPart = $this->b64Url(bytes: '{"alg":"HS256","typ":"JWT"}');
		$cPart = $this->b64Url(bytes: '42');
		$mac = $this->b64Url(bytes: hash_hmac('sha256', $hPart . '.' . $cPart, self::SECRET16, true));

		self::assertNull($this->verifier()->verify($hPart . '.' . $cPart . '.' . $mac));
	}//end testSignedScalarPayloadIsRejected()

	/**
	 * An accepted assertion returns every claim unchanged, extra ones included.
	 *
	 * @return void
	 */
	public function testAcceptedClaimsAreReturnedUnchanged(): void {
		$now = time();
		$claims = [
			'sub' => 'contact-1',
			'use' => 'assertion',
			'iss' => 'portaliq',
			'iat' => $now,
			'exp' => ($now + 60),
			'audience' => 'citizen',
			'extra' => ['nested' => true],
		];

		self::assertSame($claims, $this->verifier()->verify($this->mintClaims(secret: self::SECRET16, claims: $claims)));
	}//end testAcceptedClaimsAreReturnedUnchanged()

	/**
	 * Claims whose encoding needs base64 padding still verify.
	 *
	 * @return void
	 */
	public function testPaddedLengthsDecode(): void {
		$now = time();
		foreach (['a', 'ab', 'abc', 'abcd'] as $sub) {
			$claims = ['sub' => $sub, 'use' => 'assertion', 'iss' => 'portaliq', 'iat' => $now, 'exp' => ($now + 60)];
			$result = $this->verifier()->verify($this->mintClaims(secret: self::SECRET16, claims: $claims));
			self::assertSame($sub, $result['sub'] ?? null, 'sub ' . $sub);
		}
	}//end testPaddedLengthsDecode()

	/**
	 * A verifier with the sixteen-character secret.
	 *
	 * @return PortalAssertionVerifier
	 */
	private function verifier(): PortalAssertionVerifier {
		return new PortalAssertionVerifier(secretOverride: self::SECRET16);
	}//end verifier()

	/**
	 * Mint a valid assertion with claim overrides.
	 *
	 * @param string               $secret    Signing secret.
	 * @param array<string, mixed> $overrides Claim overrides.
	 *
	 * @return string
	 */
	private function mint(string $secret, array $overrides = []): string {
		$now = time();
		$claims = array_merge(
			['sub' => 'contact-1', 'use' => 'assertion', 'iss' => 'portaliq', 'iat' => $now, 'exp' => ($now + 60)],
			$overrides
		);

		return $this->mintClaims(secret: $secret, claims: $claims);
	}//end mint()

	/**
	 * Mint an HS256 token over exactly these claims.
	 *
	 * @param string               $secret Signing secret.
	 * @param array<string, mixed> $claims Claims.
	 *
	 * @return string
	 */
	private function mintClaims(string $secret, array $claims): string {
		$hPart = $this->b64Url(bytes: (string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
		$cPart = $this->b64Url(bytes: (string) json_encode($claims, JSON_UNESCAPED_SLASHES));
		$mac = $this->b64Url(bytes: hash_hmac('sha256', $hPart . '.' . $cPart, $secret, true));

		return $hPart . '.' . $cPart . '.' . $mac;
	}//end mintClaims()

	/**
	 * Unpadded base64url.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64Url(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64Url()
}//end class
