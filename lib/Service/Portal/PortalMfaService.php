<?php

/**
 * Pipelinq PortalMfaService.
 *
 * TOTP (RFC 6238) second factor for portal accounts. Implements secret
 * generation, otpauth provisioning URI (for QR rendering on the client), and
 * code verification with a small time-skew window. The shared secret is
 * encrypted at rest via Nextcloud's ICrypto (AES) before it touches the
 * portal_account record and is never returned by any read API (REQ-001).
 *
 * RFC 6238 / RFC 4226 are implemented inline rather than pulling in an external
 * one-time-password library, so the portal adds no new composer dependency.
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

/**
 * TOTP MFA helper for portal accounts.
 */
class PortalMfaService {
	/**
	 * Base32 alphabet (RFC 4648) for secret encoding.
	 *
	 * @var string
	 */
	private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * TOTP step in seconds.
	 *
	 * @var int
	 */
	private const PERIOD = 30;

	/**
	 * Number of digits in a code.
	 *
	 * @var int
	 */
	private const DIGITS = 6;

	/**
	 * Allowed +/- step skew window.
	 *
	 * @var int
	 */
	private const SKEW = 1;

	/**
	 * Constructor.
	 *
	 * @param ISecureRandom $secureRandom The CSPRNG.
	 * @param ICrypto $crypto The encryption service.
	 * @param ITimeFactory $time The time factory.
	 */
	public function __construct(
		private ISecureRandom $secureRandom,
		private ICrypto $crypto,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Generate a new base32 TOTP secret (160 bits).
	 *
	 * @return string The base32-encoded secret.
	 */
	public function generateSecret(): string {
		$raw = $this->secureRandom->generate(20, ISecureRandom::CHAR_ALPHANUMERIC);
		$secret = '';
		$buffer = 0;
		$bits = 0;
		foreach (str_split($raw) as $char) {
			$buffer = (($buffer << 8) | ord($char));
			$bits += 8;
			while ($bits >= 5) {
				$bits -= 5;
				$secret .= self::BASE32[(($buffer >> $bits) & 31)];
			}
		}

		if ($bits > 0) {
			$secret .= self::BASE32[(($buffer << (5 - $bits)) & 31)];
		}

		return $secret;
	}//end generateSecret()

	/**
	 * Build the otpauth:// provisioning URI the client renders as a QR code.
	 *
	 * @param string $secret The base32 secret.
	 * @param string $accountLabel The account label (usually the email).
	 * @param string $issuer The issuer name shown in the authenticator.
	 *
	 * @return string The otpauth URI.
	 */
	public function provisioningUri(string $secret, string $accountLabel, string $issuer): string {
		$label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
		return sprintf(
			'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
			$label,
			$secret,
			rawurlencode($issuer),
			self::DIGITS,
			self::PERIOD
		);
	}//end provisioningUri()

	/**
	 * Encrypt a secret for storage on the account record.
	 *
	 * @param string $secret The base32 secret.
	 *
	 * @return string The ciphertext.
	 */
	public function encryptSecret(string $secret): string {
		return $this->crypto->encrypt($secret);
	}//end encryptSecret()

	/**
	 * Verify a presented 6-digit code against an encrypted stored secret.
	 *
	 * Accepts the current step plus +/- one step of skew. Returns false on any
	 * decryption error or malformed code (fail-closed).
	 *
	 * @param string|null $encryptedSecret The encrypted base32 secret.
	 * @param string|null $code The presented code.
	 *
	 * @return bool True when the code is valid for the current time window.
	 */
	public function verifyCode(?string $encryptedSecret, ?string $code): bool {
		if ($encryptedSecret === null || $encryptedSecret === '' || $code === null) {
			return false;
		}

		$code = trim($code);
		if (preg_match('/^\d{6}$/', $code) !== 1) {
			return false;
		}

		try {
			$secret = $this->crypto->decrypt($encryptedSecret);
		} catch (\Throwable $e) {
			return false;
		}

		$counter = intdiv($this->time->getTime(), self::PERIOD);
		for ($offset = (-self::SKEW); $offset <= self::SKEW; $offset++) {
			if (hash_equals($this->codeForCounter(base32Secret: $secret, counter: ($counter + $offset)), $code) === true) {
				return true;
			}
		}

		return false;
	}//end verifyCode()

	/**
	 * Compute the HOTP/TOTP code for a given counter (RFC 4226).
	 *
	 * @param string $base32Secret The base32 secret.
	 * @param int $counter The time-step counter.
	 *
	 * @return string The zero-padded 6-digit code.
	 */
	private function codeForCounter(string $base32Secret, int $counter): string {
		$key = $this->base32Decode(secret: $base32Secret);
		$binary = pack('N*', 0) . pack('N*', $counter);
		$hash = hash_hmac('sha1', $binary, $key, true);
		$offset = (ord($hash[19]) & 0x0F);
		$byte0 = ((ord($hash[$offset]) & 0x7F) << 24);
		$byte1 = ((ord($hash[($offset + 1)]) & 0xFF) << 16);
		$byte2 = ((ord($hash[($offset + 2)]) & 0xFF) << 8);
		$byte3 = (ord($hash[($offset + 3)]) & 0xFF);
		$part = ($byte0 | $byte1 | $byte2 | $byte3);

		return str_pad((string)($part % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
	}//end codeForCounter()

	/**
	 * Decode a base32 string to raw bytes.
	 *
	 * @param string $secret The base32 secret.
	 *
	 * @return string The raw key bytes.
	 */
	private function base32Decode(string $secret): string {
		$secret = strtoupper(rtrim($secret, '='));
		$buffer = 0;
		$bits = 0;
		$output = '';
		foreach (str_split($secret) as $char) {
			$index = strpos(self::BASE32, $char);
			if ($index === false) {
				continue;
			}

			$buffer = (($buffer << 5) | $index);
			$bits += 5;
			if ($bits >= 8) {
				$bits -= 8;
				$output .= chr((($buffer >> $bits) & 0xFF));
			}
		}

		return $output;
	}//end base32Decode()
}//end class
