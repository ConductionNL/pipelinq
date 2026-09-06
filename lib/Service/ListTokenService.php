<?php

/**
 * Pipelinq ListTokenService.
 *
 * Signs and verifies the links a mailing-list subscriber follows from an
 * email: the confirmation link, the unsubscribe link and the preference
 * centre link. The token shape mirrors {@see TrackingLinkService}: a
 * base64url JSON payload joined with `.` to a base64url HMAC-SHA256
 * signature over that payload, compared with `hash_equals`.
 *
 * Two things are deliberately different from the tracking token. Every
 * payload carries a purpose (`p`), and verification refuses a token minted
 * for a different one, so an unsubscribe link can never be replayed as a
 * confirmation. And the confirmation token additionally carries a random
 * nonce whose SHA-256 the subscription stores, so a link is bound to one
 * subscription and can be spent exactly once (ADR-005 fail-closed).
 *
 * The signing key is a per-instance random value in app-config key
 * `lists.token_secret`, minted on first use. It is never written to a
 * register, a fixture or an object: ADR-064 keeps secrets off objects, and
 * a digest is not a secret, which is why the digest is what the
 * subscription holds.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * ListTokenService — purpose-scoped signed links for mailing lists.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
 */
class ListTokenService {
	/**
	 * Purpose of a confirmation link.
	 *
	 * @var string
	 */
	public const PURPOSE_CONFIRM = 'confirm';

	/**
	 * Purpose of an unsubscribe link.
	 *
	 * @var string
	 */
	public const PURPOSE_UNSUBSCRIBE = 'unsub';

	/**
	 * Purpose of a preference-centre link.
	 *
	 * @var string
	 */
	public const PURPOSE_PREFERENCES = 'prefs';

	/**
	 * App-config key for the per-instance HMAC signing secret.
	 *
	 * @var string
	 */
	private const SECRET_CONFIG_KEY = 'lists.token_secret';

	/**
	 * App-config key for the confirmation-link lifetime in days.
	 *
	 * @var string
	 */
	private const CONFIRM_TTL_CONFIG_KEY = 'lists.confirm_token_ttl_days';

	/**
	 * App-config key for the unsubscribe and preference link lifetime in days.
	 *
	 * @var string
	 */
	private const LINK_TTL_CONFIG_KEY = 'lists.link_token_ttl_days';

	/**
	 * A confirmation link is used within minutes or not at all.
	 *
	 * @var int
	 */
	private const DEFAULT_CONFIRM_TTL_DAYS = 7;

	/**
	 * An unsubscribe link sits in a mail archive and has to keep working.
	 *
	 * @var int
	 */
	private const DEFAULT_LINK_TTL_DAYS = 730;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param ITimeFactory $time Time factory (token iat/exp).
	 * @param ISecureRandom $secureRandom CSPRNG (key and nonce minting).
	 * @param IURLGenerator $urlGenerator Absolute link builder.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private ISecureRandom $secureRandom,
		private IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Mint a random nonce for a confirmation link.
	 *
	 * @return string A 43-character alphanumeric nonce.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
	 */
	public function mintNonce(): string {
		return $this->secureRandom->generate(43, ISecureRandom::CHAR_ALPHANUMERIC);
	}//end mintNonce()

	/**
	 * Digest a nonce for storage on the subscription.
	 *
	 * The subscription stores this, never the nonce, so a register export
	 * carries no bearer credential.
	 *
	 * @param string $nonce The nonce from {@see self::mintNonce()}.
	 *
	 * @return string Lower-case hex SHA-256, or an empty string for an
	 *                empty nonce.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
	 */
	public function digest(string $nonce): string {
		if ($nonce === '') {
			return '';
		}

		return hash('sha256', $nonce);
	}//end digest()

	/**
	 * Keyed hash of a caller address, kept as opt-in evidence.
	 *
	 * Keyed rather than plain so the stored value cannot be reversed by
	 * hashing the whole IPv4 space, which a bare SHA-256 would allow.
	 *
	 * @param string $address The remote address.
	 *
	 * @return string Lower-case hex digest, or an empty string when the
	 *                address is empty.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
	 */
	public function hashAddress(string $address): string {
		if ($address === '') {
			return '';
		}

		return hash_hmac('sha256', $address, $this->signingKey());
	}//end hashAddress()

	/**
	 * Sign a confirmation link for one subscription.
	 *
	 * @param string $subscriptionId Subscription UUID or slug.
	 * @param string $nonce The nonce whose digest the subscription holds.
	 *
	 * @return string The dotted `<payload>.<signature>` token.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
	 */
	public function signConfirmToken(string $subscriptionId, string $nonce): string {
		return $this->sign(
			purpose: self::PURPOSE_CONFIRM,
			claims: ['s' => $subscriptionId, 'n' => $nonce],
			ttlSeconds: $this->ttlSeconds(key: self::CONFIRM_TTL_CONFIG_KEY, default: self::DEFAULT_CONFIRM_TTL_DAYS),
		);
	}//end signConfirmToken()

	/**
	 * Sign an unsubscribe link for one subscription.
	 *
	 * @param string $subscriptionId Subscription UUID or slug.
	 * @param string $contactId Contact the subscription belongs to, so a
	 *                          global unsubscribe can reach every list.
	 *
	 * @return string The dotted `<payload>.<signature>` token.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 */
	public function signUnsubscribeToken(string $subscriptionId, string $contactId): string {
		return $this->sign(
			purpose: self::PURPOSE_UNSUBSCRIBE,
			claims: ['s' => $subscriptionId, 'c' => $contactId],
			ttlSeconds: $this->ttlSeconds(key: self::LINK_TTL_CONFIG_KEY, default: self::DEFAULT_LINK_TTL_DAYS),
		);
	}//end signUnsubscribeToken()

	/**
	 * Sign a preference-centre link for one contact.
	 *
	 * @param string $contactId Contact UUID or slug.
	 *
	 * @return string The dotted `<payload>.<signature>` token.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function signPreferencesToken(string $contactId): string {
		return $this->sign(
			purpose: self::PURPOSE_PREFERENCES,
			claims: ['c' => $contactId],
			ttlSeconds: $this->ttlSeconds(key: self::LINK_TTL_CONFIG_KEY, default: self::DEFAULT_LINK_TTL_DAYS),
		);
	}//end signPreferencesToken()

	/**
	 * Verify a token and assert it was minted for the expected purpose.
	 *
	 * Fails closed on a malformed token, a signature mismatch, an expired
	 * token or a purpose mismatch. Never throws.
	 *
	 * @param string $token The presented token.
	 * @param string $purpose One of the `PURPOSE_*` constants.
	 *
	 * @return array<string, mixed>|null The decoded payload, or null when
	 *                                   the token is unusable.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-public-list-endpoints-are-throttled-and-fail-closed
	 */
	public function verify(string $token, string $purpose): ?array {
		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return null;
		}

		[$encoded, $signature] = $parts;
		if ($encoded === '' || $signature === '') {
			return null;
		}

		$expected = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		if (hash_equals($expected, $signature) === false) {
			return null;
		}

		$decoded = json_decode($this->base64UrlDecode(data: $encoded), true);
		if (is_array($decoded) === false || isset($decoded['exp']) === false) {
			return null;
		}

		if ((string)($decoded['p'] ?? '') !== $purpose) {
			return null;
		}

		if ((int)$decoded['exp'] < $this->time->getTime()) {
			return null;
		}

		return $decoded;
	}//end verify()

	/**
	 * Absolute URL of the confirmation endpoint for a token.
	 *
	 * @param string $token The signed confirmation token.
	 *
	 * @return string Absolute URL.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
	 */
	public function confirmUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('pipelinq.listPublic.confirm', ['token' => $token]);
	}//end confirmUrl()

	/**
	 * Absolute URL of the unsubscribe endpoint for a token.
	 *
	 * @param string $token The signed unsubscribe token.
	 *
	 * @return string Absolute URL.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 */
	public function unsubscribeUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('pipelinq.listPublic.unsubscribePage', ['token' => $token]);
	}//end unsubscribeUrl()

	/**
	 * Absolute URL of the preference centre for a token.
	 *
	 * @param string $token The signed preferences token.
	 *
	 * @return string Absolute URL.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function preferencesUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('pipelinq.listPublic.preferences', ['token' => $token]);
	}//end preferencesUrl()

	/**
	 * Sign a payload for a purpose.
	 *
	 * @param string $purpose One of the `PURPOSE_*` constants.
	 * @param array<string, mixed> $claims Purpose-specific claims.
	 * @param int $ttlSeconds Lifetime in seconds.
	 *
	 * @return string The dotted token.
	 */
	private function sign(string $purpose, array $claims, int $ttlSeconds): string {
		$issuedAt = $this->time->getTime();
		$full = array_merge(
			['p' => $purpose],
			$claims,
			['iat' => $issuedAt, 'exp' => ($issuedAt + $ttlSeconds)],
		);

		$encoded = $this->base64UrlEncode(data: (string)json_encode($full));
		$signature = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		return ($encoded . '.' . $signature);
	}//end sign()

	/**
	 * Resolve, or lazily mint, the per-instance HMAC signing key.
	 *
	 * @return string The signing key.
	 */
	private function signingKey(): string {
		$key = $this->appConfig->getValueString(Application::APP_ID, self::SECRET_CONFIG_KEY, '');
		if ($key === '') {
			$key = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, self::SECRET_CONFIG_KEY, $key, false, true);
		}

		return $key;
	}//end signingKey()

	/**
	 * Resolve a TTL in seconds from app config, falling back to a default.
	 *
	 * @param string $key App-config key holding the lifetime in days.
	 * @param int $default Fallback lifetime in days.
	 *
	 * @return int Lifetime in seconds.
	 */
	private function ttlSeconds(string $key, int $default): int {
		$raw = $this->appConfig->getValueString(Application::APP_ID, $key, (string)$default);
		if (is_numeric($raw) === false) {
			return ($default * 86400);
		}

		$days = (int)$raw;
		if ($days <= 0) {
			return ($default * 86400);
		}

		return ($days * 86400);
	}//end ttlSeconds()

	/**
	 * Base64url-encode (RFC 4648 section 5, no padding).
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
	 * @return string The decoded data, empty on failure.
	 */
	private function base64UrlDecode(string $data): string {
		$decoded = base64_decode(strtr($data, '-_', '+/'), true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}//end base64UrlDecode()
}//end class
