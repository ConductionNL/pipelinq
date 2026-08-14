<?php

/**
 * Pipelinq DocumentSigningService.
 *
 * Issues and validates short-lived HMAC-signed download tokens so portal
 * document downloads never expose a Nextcloud file path and cannot be guessed
 * or replayed past their TTL. The token binds the object id, object type, the
 * issuing account and the expiry into an HMAC-SHA256 signature over a
 * per-instance secret key; validation is constant-time and expiry-checked, and
 * the bound account lets the download endpoint re-check per-customer access
 * before streaming (ADR-005, REQ-005).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Signed-URL token issuer/validator for portal document downloads.
 */
class DocumentSigningService {
	/**
	 * App-config key holding the per-instance signing secret.
	 *
	 * @var string
	 */
	private const SIGNING_KEY = 'portal_document_signing_key';

	/**
	 * Default token TTL in minutes.
	 *
	 * @var int
	 */
	public const DEFAULT_TTL_MINUTES = 5;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param ISecureRandom $secureRandom The CSPRNG (for the signing key).
	 * @param ITimeFactory $time The time factory.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ISecureRandom $secureRandom,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Generate a signed download token for an object, valid for $ttlMinutes.
	 *
	 * @param string $objectId The object id.
	 * @param string $objectType The object type (e.g. invoice).
	 * @param string $accountId The requesting account id (re-checked on download).
	 * @param int $ttlMinutes The TTL in minutes.
	 *
	 * @return array{token: string, path: string, expiresAt: int} The signed material.
	 */
	public function generateUrl(
		string $objectId,
		string $objectType,
		string $accountId,
		int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
	): array {
		$issuedAt = $this->time->getTime();
		$expiresAt = ($issuedAt + (max(1, $ttlMinutes) * 60));

		$payload = [
			'objectId' => $objectId,
			'objectType' => $objectType,
			'accountId' => $accountId,
			'issuedAt' => $issuedAt,
			'expiresAt' => $expiresAt,
		];

		$encoded = $this->base64UrlEncode(data: json_encode($payload));
		$signature = $this->base64UrlEncode(data: $this->sign(encoded: $encoded));
		$token = $encoded . '.' . $signature;

		return [
			'token' => $token,
			'path' => '/portal/api/documents/' . $token . '/download',
			'expiresAt' => $expiresAt,
		];
	}//end generateUrl()

	/**
	 * Validate a token: verify the signature, then the expiry.
	 *
	 * Returns the decoded payload when valid, or a typed failure marker the
	 * caller maps to the right status: null = invalid (404), 'expired' = expired
	 * (410 Gone) so a legitimately-issued-but-stale link is distinguishable.
	 *
	 * @param string $token The presented token.
	 *
	 * @return array<string, mixed>|string|null The payload, 'expired', or null.
	 */
	public function validateToken(string $token): array|string|null {
		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return null;
		}

		[$encoded, $signature] = $parts;
		$expected = $this->base64UrlEncode(data: $this->sign(encoded: $encoded));
		if (hash_equals($expected, $signature) === false) {
			return null;
		}

		$decoded = json_decode((string)$this->base64UrlDecode(data: $encoded), true);
		if (is_array($decoded) === false || isset($decoded['expiresAt']) === false) {
			return null;
		}

		if ((int)$decoded['expiresAt'] < $this->time->getTime()) {
			return 'expired';
		}

		return $decoded;
	}//end validateToken()

	/**
	 * Compute the HMAC-SHA256 signature of an encoded payload.
	 *
	 * @param string $encoded The base64url-encoded payload.
	 *
	 * @return string The raw HMAC bytes.
	 */
	private function sign(string $encoded): string {
		return hash_hmac('sha256', $encoded, $this->signingKey(), true);
	}//end sign()

	/**
	 * Get (or lazily mint and persist) the per-instance signing key.
	 *
	 * @return string The signing key.
	 */
	private function signingKey(): string {
		$key = $this->appConfig->getValueString(Application::APP_ID, self::SIGNING_KEY, '');
		if ($key === '') {
			$key = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, self::SIGNING_KEY, $key, false, true);
		}

		return $key;
	}//end signingKey()

	/**
	 * Base64url-encode (RFC 4648 §5, no padding).
	 *
	 * @param string $data The raw data.
	 *
	 * @return string The encoded string.
	 */
	private function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}//end base64UrlEncode()

	/**
	 * Base64url-decode.
	 *
	 * @param string $data The encoded string.
	 *
	 * @return string|false The decoded data, or false.
	 */
	private function base64UrlDecode(string $data): string|false {
		return base64_decode(strtr($data, '-_', '+/'), true);
	}//end base64UrlDecode()
}//end class
