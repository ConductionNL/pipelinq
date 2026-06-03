<?php

/**
 * Pipelinq EncryptionService.
 *
 * AES-256-GCM encryption / decryption and keyed hashing of citizen BSNs for the
 * Berichtenbox bridge. Encryption keys and the HMAC pepper are read from the
 * Nextcloud app-config vault (sensitive, lazy values), never hardcoded
 * (ADR-005). Key rotation is supported: a key-id is folded into the ciphertext
 * envelope so old keys can still decrypt historical records. Plaintext BSN
 * material is never written to logs or exception messages.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-SECURITY-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\CryptoException;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * AES-256-GCM BSN crypto with key rotation and keyed hashing.
 *
 * Ciphertext envelope (base64 of): keyIdLen(1) | keyId | iv(12) | tag(16) | ct.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-SECURITY-009
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class EncryptionService
{
    /**
     * App-config key holding the active encryption key-id.
     *
     * @var string
     */
    private const ACTIVE_KEY_ID = 'berichtenbox_crypto_active_key_id';

    /**
     * App-config key prefix for a versioned base64 encryption key.
     *
     * @var string
     */
    private const KEY_PREFIX = 'berichtenbox_crypto_key_';

    /**
     * App-config key for the HMAC pepper used by hashBsn().
     *
     * @var string
     */
    private const HMAC_PEPPER_KEY = 'berichtenbox_bsn_hmac_pepper';

    /**
     * The AEAD cipher.
     *
     * @var string
     */
    private const CIPHER = 'aes-256-gcm';

    /**
     * GCM IV length in bytes.
     *
     * @var int
     */
    private const IV_LEN = 12;

    /**
     * GCM tag length in bytes.
     *
     * @var int
     */
    private const TAG_LEN = 16;

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig    The app config (vault).
     * @param ISecureRandom   $secureRandom The secure random source.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ISecureRandom $secureRandom,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Encrypt a plaintext BSN.
     *
     * @param string $plaintext The plaintext BSN.
     *
     * @return string The base64 ciphertext envelope.
     *
     * @throws CryptoException If no active key is configured or encryption fails.
     */
    public function encrypt(string $plaintext): string
    {
        $keyId = $this->getActiveKeyId();
        $key   = $this->getKey(keyId: $keyId);

        $iv  = $this->secureRandom->generate(self::IV_LEN, ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
        $iv  = substr(hash('sha256', $iv, true), 0, self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new CryptoException(message: 'BSN encryption failed.');
        }

        $envelope = chr(strlen($keyId)).$keyId.$iv.$tag.$ciphertext;

        return base64_encode($envelope);
    }//end encrypt()

    /**
     * Decrypt a ciphertext envelope back to the plaintext BSN.
     *
     * @param string $ciphertext The base64 ciphertext envelope.
     *
     * @return string The plaintext BSN.
     *
     * @throws CryptoException If the envelope is malformed or decryption fails.
     */
    public function decrypt(string $ciphertext): string
    {
        $envelope = base64_decode($ciphertext, true);
        if ($envelope === false || strlen($envelope) < (1 + self::IV_LEN + self::TAG_LEN)) {
            throw new CryptoException(message: 'Malformed BSN ciphertext envelope.');
        }

        $keyIdLen = ord($envelope[0]);
        $offset   = 1;
        $keyId    = substr($envelope, $offset, $keyIdLen);
        $offset  += $keyIdLen;
        $iv       = substr($envelope, $offset, self::IV_LEN);
        $offset  += self::IV_LEN;
        $tag      = substr($envelope, $offset, self::TAG_LEN);
        $offset  += self::TAG_LEN;
        $raw      = substr($envelope, $offset);

        $key = $this->getKey(keyId: $keyId);

        $plaintext = openssl_decrypt(
            $raw,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if ($plaintext === false) {
            throw new CryptoException(message: 'BSN decryption failed (wrong key or tampered ciphertext).');
        }

        return $plaintext;
    }//end decrypt()

    /**
     * Compute an HMAC-SHA256 hash of a BSN for index lookups.
     *
     * The hash is peppered with a vault-stored secret so the index cannot be
     * brute-forced against the small BSN keyspace without the pepper.
     *
     * @param string $plaintext The plaintext BSN.
     *
     * @return string The lowercase hex HMAC-SHA256 hash.
     *
     * @throws CryptoException If the HMAC pepper is not configured.
     */
    public function hashBsn(string $plaintext): string
    {
        $pepper = $this->getOrCreatePepper();

        return hash_hmac('sha256', $plaintext, $pepper);
    }//end hashBsn()

    /**
     * Crypto-shred a BSN: re-encrypt under a destroyed one-time key.
     *
     * Returns a ciphertext that can never be decrypted again (the random key is
     * discarded), satisfying AVG Art. 17 erasure while leaving row structure and
     * the audit trail intact.
     *
     * @return string A base64 envelope that is undecryptable by design.
     */
    public function shred(): string
    {
        $shredKey = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
        $key      = substr(hash('sha256', $shredKey, true), 0, 32);

        $iv  = substr(hash('sha256', $this->secureRandom->generate(32), true), 0, self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt('shredded', self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($ciphertext === false) {
            // Even on failure, return a non-decryptable marker.
            return base64_encode('SHRED');
        }

        // The random key is intentionally never persisted -> data is unrecoverable.
        $envelope = chr(strlen('shred')).'shred'.$iv.$tag.$ciphertext;

        return base64_encode($envelope);
    }//end shred()

    /**
     * Resolve the active key-id from the vault.
     *
     * @return string The active key-id.
     *
     * @throws CryptoException If no active encryption key is configured.
     */
    private function getActiveKeyId(): string
    {
        $keyId = $this->appConfig->getValueString(Application::APP_ID, self::ACTIVE_KEY_ID, '');
        if ($keyId === '') {
            // Bootstrap a first key on first use so the bridge is self-provisioning
            // in dev/test; production deployments provision keys out-of-band.
            $keyId = $this->provisionKey();
        }

        return $keyId;
    }//end getActiveKeyId()

    /**
     * Load a 32-byte key by key-id from the vault.
     *
     * @param string $keyId The key-id.
     *
     * @return string The raw 32-byte key.
     *
     * @throws CryptoException If the key is missing or the wrong length.
     */
    private function getKey(string $keyId): string
    {
        $encoded = $this->appConfig->getValueString(Application::APP_ID, self::KEY_PREFIX.$keyId, '');
        if ($encoded === '') {
            throw new CryptoException(message: 'Encryption key not found for key-id.');
        }

        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new CryptoException(message: 'Encryption key is malformed.');
        }

        return $key;
    }//end getKey()

    /**
     * Provision a new active 256-bit key and persist it in the vault.
     *
     * @return string The new key-id.
     */
    private function provisionKey(): string
    {
        $keyId = substr(hash('sha256', $this->secureRandom->generate(32)), 0, 8);
        $key   = $this->secureRandom->generate(32);
        // ISecureRandom returns printable chars; fold to exactly 32 raw bytes.
        $raw = substr(hash('sha256', $key, true), 0, 32);

        $this->appConfig->setValueString(
            app: Application::APP_ID,
            key: self::KEY_PREFIX.$keyId,
            value: base64_encode($raw),
            sensitive: true,
            lazy: true
        );
        $this->appConfig->setValueString(app: Application::APP_ID, key: self::ACTIVE_KEY_ID, value: $keyId);

        $this->logger->info('Berichtenbox: provisioned new BSN encryption key', ['keyId' => $keyId]);

        return $keyId;
    }//end provisionKey()

    /**
     * Get or lazily provision the HMAC pepper.
     *
     * @return string The HMAC pepper.
     */
    private function getOrCreatePepper(): string
    {
        $pepper = $this->appConfig->getValueString(Application::APP_ID, self::HMAC_PEPPER_KEY, '');
        if ($pepper === '') {
            $pepper = bin2hex(substr(hash('sha256', $this->secureRandom->generate(32), true), 0, 32));
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: self::HMAC_PEPPER_KEY,
                value: $pepper,
                sensitive: true,
                lazy: true
            );
        }

        return $pepper;
    }//end getOrCreatePepper()

    /**
     * Mask a BSN for safe logging (first/last digit only).
     *
     * @param string $bsn The plaintext BSN.
     *
     * @return string The masked BSN, e.g. "1*******9".
     */
    public static function mask(string $bsn): string
    {
        $len = strlen($bsn);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }

        return $bsn[0].str_repeat('*', ($len - 2)).$bsn[($len - 1)];
    }//end mask()
}//end class
