<?php

/**
 * Pipelinq EncryptionService.
 *
 * AES-256-GCM encryption/decryption + HMAC-SHA256 hashing helper for
 * BSN values stored by the Berichtenbox bridge. Per-tenant keys are
 * resolved from openregister's key-vault when available (the actual
 * vault binding is encapsulated through a setter so unit tests can
 * inject an in-memory provider). Falls back to a deterministic
 * per-tenant key derived from the Nextcloud secret + tenant id when
 * the vault is absent (dev/test only).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-encryption-008
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-keyvault-015
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * BSN encryption + keyed-hash service for the Berichtenbox bridge.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-encryption-008
 */
class EncryptionService
{
    /**
     * AES-256-GCM key length in bytes.
     *
     * @var int
     */
    private const KEY_BYTES = 32;

    /**
     * AES-256-GCM IV length in bytes.
     *
     * @var int
     */
    private const IV_BYTES = 12;

    /**
     * GCM authentication tag length in bytes.
     *
     * @var int
     */
    private const TAG_BYTES = 16;

    /**
     * Cipher identifier.
     *
     * @var string
     */
    private const CIPHER = 'aes-256-gcm';

    /**
     * Optional injected vault provider — duck-typed so tests can stub it
     * without depending on the openregister classes at compile time.
     * The provider exposes `getEncryptionKey(string $tenantId): string`
     * (raw 32-byte key) and `getHmacKey(string $tenantId): string`.
     *
     * @var object|null
     */
    private ?object $vaultProvider = null;

    /**
     * Constructor.
     *
     * @param IConfig         $config Nextcloud config service.
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Inject a vault provider (used by tests / app-bootstrap wiring when
     * openregister's key-vault binding is available).
     *
     * @param object $provider Duck-typed vault provider.
     *
     * @return void
     */
    public function setVaultProvider(object $provider): void
    {
        $this->vaultProvider = $provider;
    }//end setVaultProvider()

    /**
     * Encrypt a plaintext value with the per-tenant AES-256-GCM key.
     *
     * Returns a base64-encoded IV || ciphertext || tag string that
     * can be persisted as a single field.
     *
     * @param string $plaintext The value to encrypt (never logged).
     * @param string $tenantId  The tenant identifier (drives key selection).
     *
     * @return string Base64-encoded IV || ciphertext || tag.
     *
     * @throws RuntimeException If encryption fails.
     *
     * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-encryption-008
     */
    public function encrypt(string $plaintext, string $tenantId): string
    {
        $key        = $this->getEncryptionKey(tenantId: $tenantId);
        $initVector = random_bytes(self::IV_BYTES);
        $tag        = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $initVector,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AES-256-GCM encryption failed.');
        }

        return base64_encode($initVector.$ciphertext.$tag);
    }//end encrypt()

    /**
     * Decrypt a previously-encrypted value for the given tenant.
     *
     * @param string $ciphertext Base64-encoded IV || ciphertext || tag.
     * @param string $tenantId   Tenant identifier.
     *
     * @return string The plaintext (never logged).
     *
     * @throws RuntimeException If decryption fails or the payload is malformed.
     *
     * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-encryption-008
     */
    public function decrypt(string $ciphertext, string $tenantId): string
    {
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) < (self::IV_BYTES + self::TAG_BYTES + 1)) {
            throw new RuntimeException('Malformed ciphertext payload.');
        }

        $initVector = substr($raw, 0, self::IV_BYTES);
        $tag        = substr($raw, -self::TAG_BYTES);
        $cipher     = substr($raw, self::IV_BYTES, -self::TAG_BYTES);
        $keys       = $this->getDecryptionKeys(tenantId: $tenantId);

        foreach ($keys as $key) {
            $plain = openssl_decrypt(
                $cipher,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $initVector,
                $tag
            );
            if ($plain !== false) {
                return $plain;
            }
        }

        $this->logger->error('Berichtenbox EncryptionService decrypt failed (all keys exhausted).');
        throw new RuntimeException('AES-256-GCM decryption failed.');
    }//end decrypt()

    /**
     * Return a deterministic HMAC-SHA256 hash of the BSN suitable for
     * SQL index lookups. The hash never reveals the BSN even if leaked;
     * it MUST NOT be reused across tenants.
     *
     * @param string $plaintext Plaintext BSN.
     * @param string $tenantId  Tenant identifier.
     *
     * @return string Hex-encoded HMAC-SHA256 digest.
     */
    public function hashBsn(string $plaintext, string $tenantId): string
    {
        $hmacKey = $this->getHmacKey(tenantId: $tenantId);
        return hash_hmac('sha256', $plaintext, $hmacKey);
    }//end hashBsn()

    /**
     * Compare a candidate BSN against an existing hash in constant time.
     *
     * @param string $candidate The plaintext BSN to verify.
     * @param string $hash      The stored hex HMAC-SHA256 digest.
     * @param string $tenantId  Tenant identifier.
     *
     * @return bool True iff the candidate matches the hash.
     */
    public function bsnEquals(string $candidate, string $hash, string $tenantId): bool
    {
        return hash_equals($hash, $this->hashBsn(plaintext: $candidate, tenantId: $tenantId));
    }//end bsnEquals()

    /**
     * Resolve the active (write) encryption key for a tenant.
     *
     * @param string $tenantId Tenant identifier.
     *
     * @return string 32 raw bytes.
     *
     * @throws RuntimeException If the key cannot be obtained.
     */
    private function getEncryptionKey(string $tenantId): string
    {
        if ($this->vaultProvider !== null && method_exists($this->vaultProvider, 'getEncryptionKey') === true) {
            try {
                $key = (string) $this->vaultProvider->getEncryptionKey($tenantId);
                if (strlen($key) === self::KEY_BYTES) {
                    return $key;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Berichtenbox vault encryption-key lookup failed; falling back to derived key.',
                    ['exception' => $e->getMessage()]
                );
            }
        }

        return $this->deriveKey(tenantId: $tenantId, purpose: 'enc.active');
    }//end getEncryptionKey()

    /**
     * Resolve the set of decryption keys to try (active first, then any
     * older keys still valid for read). Supports key rotation.
     *
     * @param string $tenantId Tenant identifier.
     *
     * @return array<int, string> Raw 32-byte keys to try in order.
     */
    private function getDecryptionKeys(string $tenantId): array
    {
        $keys = [];
        if ($this->vaultProvider !== null && method_exists($this->vaultProvider, 'getDecryptionKeys') === true) {
            try {
                $vaultKeys = $this->vaultProvider->getDecryptionKeys($tenantId);
                if (is_array($vaultKeys) === true) {
                    foreach ($vaultKeys as $vaultKey) {
                        if (is_string($vaultKey) === true && strlen($vaultKey) === self::KEY_BYTES) {
                            $keys[] = $vaultKey;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Berichtenbox vault decryption-keys lookup failed; falling back to derived key.',
                    ['exception' => $e->getMessage()]
                );
            }
        }

        $keys[] = $this->deriveKey(tenantId: $tenantId, purpose: 'enc.active');
        // Allow a previous-generation derived key (in case the system secret rotated).
        $keys[] = $this->deriveKey(tenantId: $tenantId, purpose: 'enc.previous');

        return $keys;
    }//end getDecryptionKeys()

    /**
     * Resolve the HMAC key for index hashing.
     *
     * @param string $tenantId Tenant identifier.
     *
     * @return string Raw key bytes (at least 32 bytes recommended).
     */
    private function getHmacKey(string $tenantId): string
    {
        if ($this->vaultProvider !== null && method_exists($this->vaultProvider, 'getHmacKey') === true) {
            try {
                $key = (string) $this->vaultProvider->getHmacKey($tenantId);
                if ($key !== '') {
                    return $key;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Berichtenbox vault HMAC-key lookup failed; falling back to derived key.',
                    ['exception' => $e->getMessage()]
                );
            }
        }

        return $this->deriveKey(tenantId: $tenantId, purpose: 'hmac.bsn');
    }//end getHmacKey()

    /**
     * Deterministically derive a tenant-scoped key from the Nextcloud system
     * secret. This is a development/test fallback; production deployments
     * MUST configure the openregister vault provider via setVaultProvider().
     *
     * @param string $tenantId Tenant identifier (mixed into the salt).
     * @param string $purpose  Purpose tag (e.g. 'enc.active', 'hmac.bsn').
     *
     * @return string 32 raw bytes.
     */
    private function deriveKey(string $tenantId, string $purpose): string
    {
        $systemSecret = (string) $this->config->getSystemValue('secret', '');
        if ($systemSecret === '') {
            // As a last resort use the instance-id to remain deterministic per install.
            $systemSecret = (string) $this->config->getSystemValue('instanceid', 'pipelinq-dev');
        }

        return hash_hkdf(
            'sha256',
            $systemSecret,
            self::KEY_BYTES,
            'berichtenbox.'.$purpose.':'.$tenantId
        );
    }//end deriveKey()
}//end class
