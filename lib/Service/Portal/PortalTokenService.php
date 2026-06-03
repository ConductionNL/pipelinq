<?php

/**
 * Pipelinq PortalTokenService.
 *
 * Generates and verifies single-purpose, short-lived, hashed tokens for the
 * customer portal (password reset, email-change verification, account-closure
 * confirmation). Tokens are 256-bit random values issued once in plaintext to
 * be delivered out-of-band (email); only their SHA-256 hash is ever stored, and
 * verification is constant-time and expiry-checked. This is the shared
 * primitive behind every "click the link in your email" flow (ADR-005).
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
use OCP\Security\ISecureRandom;

/**
 * Issues and verifies single-use hashed portal tokens.
 */
class PortalTokenService
{
    /**
     * Token byte length (32 bytes = 256 bits).
     *
     * @var int
     */
    private const TOKEN_BYTES = 32;

    /**
     * Constructor.
     *
     * @param ISecureRandom $secureRandom The CSPRNG.
     * @param ITimeFactory  $time         The time factory.
     */
    public function __construct(
        private ISecureRandom $secureRandom,
        private ITimeFactory $time,
    ) {
    }//end __construct()

    /**
     * Issue a token, returning the plaintext (to email) and the hash + expiry
     * (to persist on the account). The plaintext is never persisted.
     *
     * @param int $ttlMinutes Minutes until the token expires.
     *
     * @return array{plain: string, hash: string, expiresAt: string} The token material.
     */
    public function issue(int $ttlMinutes): array
    {
        $plain  = $this->randomToken();
        $expiry = $this->time->getDateTime();
        $expiry->modify('+'.max(1, $ttlMinutes).' minutes');

        return [
            'plain'     => $plain,
            'hash'      => $this->hash(plain: $plain),
            'expiresAt' => $expiry->format(DATE_ATOM),
        ];
    }//end issue()

    /**
     * Verify a presented plaintext token against a stored hash and expiry.
     *
     * Returns false for an empty/absent token, a hash mismatch, or an expired
     * token. The hash comparison is constant-time.
     *
     * @param string|null $plain      The presented plaintext token.
     * @param string|null $storedHash The stored SHA-256 hash.
     * @param string|null $expiresAt  The stored ISO-8601 expiry, or null.
     *
     * @return bool True when the token is valid and unexpired.
     */
    public function verify(?string $plain, ?string $storedHash, ?string $expiresAt): bool
    {
        if ($plain === null || $plain === '' || $storedHash === null || $storedHash === '') {
            return false;
        }

        if (hash_equals($storedHash, $this->hash(plain: $plain)) === false) {
            return false;
        }

        return $this->isUnexpired(expiresAt: $expiresAt);
    }//end verify()

    /**
     * Whether an ISO-8601 expiry timestamp is still in the future.
     *
     * @param string|null $expiresAt The expiry timestamp, or null.
     *
     * @return bool True when unexpired.
     */
    public function isUnexpired(?string $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }

        $now = $this->time->getDateTime()->getTimestamp();
        $exp = strtotime($expiresAt);
        if ($exp === false) {
            return false;
        }

        return $exp > $now;
    }//end isUnexpired()

    /**
     * SHA-256 hash of a token (the only form stored).
     *
     * @param string $plain The plaintext token.
     *
     * @return string The hex SHA-256 digest.
     */
    public function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }//end hash()

    /**
     * Generate a 256-bit URL-safe random token.
     *
     * @return string The base64url-encoded token.
     */
    public function randomToken(): string
    {
        $bytes = $this->secureRandom->generate(
            self::TOKEN_BYTES,
            ISecureRandom::CHAR_ALPHANUMERIC
        );

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }//end randomToken()
}//end class
