<?php

/**
 * Pipelinq Portal Assertion Verifier
 *
 * The ADR-046 contract-v2 A6 endpoint-action RECEIVER of pipelinq, a copy of the
 * fleet reference in petstore (lib/Portal/PortalAssertionVerifier.php). When portaliq forwards a declared endpoint action server-to-server
 * it attaches an `X-Portal-Subject` header: a short-lived (60s TTL) HS256 JWT
 * assertion carrying the resolved portal subject. This class verifies that
 * assertion and hands the receiving controller a trusted claim set — the ONLY
 * legitimate identity source for a portal-driven request (ADR-005).
 *
 * SELF-CONTAINED by design: no portaliq import, no firebase/php-jwt composer
 * dependency. The HS256 verification is hand-rolled with hash_equals
 * (constant time), mirroring procest's TenantJwtService and portaliq's own
 * PortalJwtService, so all three stay reviewable side-by-side. Kept
 * verbatim to the reference apart from the namespace and the log prefix.
 *
 * SECRET DERIVATION — copied verbatim from portaliq's
 * `PortalSessionService::__construct()` (the single place portaliq derives
 * the signing secret for sessions AND assertions); both ends MUST stay
 * byte-identical or every forward 401s:
 *
 *   1. `IAppConfig::getValueString('portaliq', 'jwt_signing_secret', '')`,
 *      the dedicated app-config secret OF THE PORTALIQ APP ID (not this
 *      app's). Portaliq stores it SENSITIVE (encrypted at rest), so it MUST
 *      be read through IAppConfig, which decrypts it. The legacy
 *      `IConfig::getAppValue()` returns the stored ciphertext, and every
 *      HMAC check then fails (401 on every portal action, 13-14 Oct 2026).
 *   2. There is no second source. Portaliq refuses to mint without its
 *      dedicated secret (`PortalSessionService`), so a receiver that fell
 *      back to the Nextcloud instance `secret` could only ever accept a
 *      token portaliq never made (ADR-054). An empty or short secret fails
 *      closed.
 *
 * Same instance, same config store, same secret — nothing is exchanged out
 * of band. The secret never comes from the request.
 *
 * @category Portal
 * @package  OCA\Pipelinq\Portal
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

namespace OCA\Pipelinq\Portal;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Verifies portaliq's `X-Portal-Subject` HS256 assertion — fail-closed.
 *
 * `verify()` returns the full claims array only when EVERY check passes and
 * null on ANY failure; it never throws and never leaks the rejection reason
 * to the caller (debug log only). A receiving controller derives ALL subject
 * scope from the returned claims — never from request parameters.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */
class PortalAssertionVerifier {
	/**
	 * The header portaliq attaches the assertion to (contract v2, A6).
	 */
	public const HEADER = 'X-Portal-Subject';

	/**
	 * The only accepted JWS algorithm — exact-match kills `none` and any
	 * RS/ES algorithm-confusion header in one check.
	 */
	private const ALG = 'HS256';

	/**
	 * Hash function name passed to hash_hmac.
	 */
	private const HASH_FN = 'sha256';

	/**
	 * The minting edge — portaliq stamps `iss` on every assertion.
	 */
	private const ISSUER = 'portaliq';

	/**
	 * The `use` claim value marking an X-Portal-Subject assertion. The
	 * receiver-side token-confusion guard: a portal SESSION token (no `use`
	 * claim) can never drive a domain endpoint, exactly as portaliq rejects
	 * assertions presented as session bearers.
	 */
	private const USE_ASSERTION = 'assertion';

	/**
	 * The app id whose config carries the signing secret (portaliq's, NOT
	 * this app's — the receiver reads the minter's secret).
	 */
	private const PORTALIQ_APP_ID = 'portaliq';

	/**
	 * The portaliq app-config key holding the dedicated signing secret.
	 */
	private const SECRET_KEY = 'jwt_signing_secret';

	/**
	 * Minimum usable secret length — portaliq refuses to MINT with less;
	 * the receiver refuses to ACCEPT with less.
	 */
	private const MIN_SECRET_LENGTH = 16;

	/**
	 * Tolerated clock skew (seconds) when checking `iat` is not in the
	 * future. The forward is an instance-local loopback hop, so real skew
	 * is zero; the leeway only absorbs same-host second boundaries.
	 */
	private const IAT_LEEWAY = 60;

	/**
	 * Constructor.
	 *
	 * Auto-wireable (the DI container hands in IAppConfig and the logger and
	 * falls back to the scalar default). Unit tests construct it with a plain
	 * secret instead, mirroring portaliq's PortalJwtService testability pattern:
	 * `new PortalAssertionVerifier(secretOverride: 'sixteen-chars-min')`.
	 *
	 * @param string|null          $secretOverride Plain signing secret for tests (bypasses config).
	 * @param LoggerInterface|null $logger         Optional logger — rejection reasons at debug level only.
	 * @param IAppConfig|null      $appConfig      Reads portaliq's sensitive signing secret (decrypted).
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function __construct(
		private readonly ?string $secretOverride=null,
		private readonly ?LoggerInterface $logger=null,
		private readonly ?IAppConfig $appConfig=null,
	) {
	}//end __construct()

	/**
	 * Verify an `X-Portal-Subject` assertion and return its claims.
	 *
	 * Fail-closed on EVERYTHING — the claims array is returned only when all
	 * of the following hold, null otherwise (no exception ever escapes):
	 *
	 *   1. compact JWS structure — exactly three non-empty segments;
	 *   2. header `alg` is exactly `HS256` (rejects `none`, case tricks,
	 *      and any asymmetric algorithm-confusion attempt);
	 *   3. HMAC signature matches — hash_equals, constant time;
	 *   4. claims decode to a JSON object;
	 *   5. `use` is exactly `assertion` (session tokens are refused);
	 *   6. `iss` is exactly `portaliq`;
	 *   7. `exp` present, integer, strictly in the future;
	 *   8. `iat` present, integer, not in the future (60s leeway), <= exp;
	 *   9. `sub` present, non-empty string — an assertion without a subject
	 *      can scope nothing, so it authorises nothing.
	 *
	 * @param string $jwt The raw header value (compact JWT).
	 *
	 * @return array<string, mixed>|null The verified claims, or null.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) -- one fail-closed guard
	 * per attack surface on an auth boundary (ADR-005); collapsing them would
	 * trade auditability for a score.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      -- same rationale: the
	 * guards are sequential early-returns, not combinatorial paths.
	 */
	public function verify(string $jwt): ?array {
		$secret = $this->secret();
		if ($secret === null) {
			return $this->reject(reason: 'no usable signing secret');
		}

		$parts = explode('.', $jwt);
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return $this->reject(reason: 'malformed structure');
		}

		[$hPart, $cPart, $sPart] = $parts;

		$header = json_decode($this->b64UrlDecode(encoded: $hPart), true);
		if (is_array($header) === false || ($header['alg'] ?? '') !== self::ALG) {
			return $this->reject(reason: 'unexpected algorithm');
		}

		$expected = $this->b64UrlEncode(bytes: hash_hmac(self::HASH_FN, $hPart.'.'.$cPart, $secret, true));
		if (hash_equals($expected, $sPart) === false) {
			return $this->reject(reason: 'signature mismatch');
		}

		$claims = json_decode($this->b64UrlDecode(encoded: $cPart), true);
		if (is_array($claims) === false) {
			return $this->reject(reason: 'malformed claims');
		}

		if (($claims['use'] ?? '') !== self::USE_ASSERTION) {
			return $this->reject(reason: 'not an assertion');
		}

		if (($claims['iss'] ?? '') !== self::ISSUER) {
			return $this->reject(reason: 'unexpected issuer');
		}

		$now = time();
		$exp = ($claims['exp'] ?? null);
		if (is_int($exp) === false || $exp <= $now) {
			return $this->reject(reason: 'expired or missing exp');
		}

		$iat = ($claims['iat'] ?? null);
		if (is_int($iat) === false || $iat > ($now + self::IAT_LEEWAY) || $iat > $exp) {
			return $this->reject(reason: 'implausible iat');
		}

		$sub = ($claims['sub'] ?? null);
		if (is_string($sub) === false || $sub === '') {
			return $this->reject(reason: 'missing subject');
		}

		return $claims;
	}//end verify()

	/**
	 * Derive the signing secret — EXACT copy of portaliq's derivation (see
	 * the class docblock). Returns null when no usable (>= 16 chars) secret
	 * exists, which makes verify() fail closed.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-portal-assertion-is-verified-with-portaliqs-dedicated-secret-only-req-pvs-001
	 */
	private function secret(): ?string {
		$secret = $this->secretOverride;
		if ($secret === null) {
			// Portaliq's dedicated secret only: it never signs with anything else.
			$secret = $this->portaliqSecret();
		}

		if (strlen($secret) < self::MIN_SECRET_LENGTH) {
			return null;
		}

		return $secret;
	}//end secret()

	/**
	 * Read portaliq's dedicated signing secret the way portaliq itself does
	 * (PortalSessionService::__construct()): through IAppConfig, non-lazy as
	 * stored, so the sensitive value comes back decrypted. Never through
	 * `IConfig::getAppValue()`, which returns the encrypted value.
	 *
	 * @return string The secret, or '' when IAppConfig is unavailable or the key is unset.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	private function portaliqSecret(): string {
		if ($this->appConfig === null) {
			return '';
		}

		return $this->appConfig->getValueString(self::PORTALIQ_APP_ID, self::SECRET_KEY, '', lazy: false);
	}//end portaliqSecret()

	/**
	 * Fail closed — never tell the caller (or an attacker probing the
	 * endpoint) WHICH check failed; debug-level log only.
	 *
	 * @param string $reason The rejection reason for the debug log.
	 *
	 * @return null
	 */
	private function reject(string $reason): null {
		if ($this->logger !== null) {
			$this->logger->debug('Pipelinq: portal assertion rejected', ['reason' => $reason]);
		}

		return null;
	}//end reject()

	/**
	 * Base64-url encode (no padding) — mirrors portaliq's encoding exactly.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64UrlEncode()

	/**
	 * Base64-url decode — mirrors portaliq's decoding exactly.
	 *
	 * @param string $encoded Encoded string.
	 *
	 * @return string Raw bytes.
	 */
	private function b64UrlDecode(string $encoded): string {
		$pad = (4 - (strlen($encoded) % 4));
		if ($pad < 4) {
			$encoded .= str_repeat('=', $pad);
		}

		return (string) base64_decode(strtr($encoded, '-_', '+/'));
	}//end b64UrlDecode()
}//end class

