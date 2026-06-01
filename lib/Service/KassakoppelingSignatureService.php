<?php

/**
 * Pipelinq KassakoppelingSignatureService.
 *
 * Cryptographic signing and hash-chain management for the Dutch
 * Kassakoppeling POS audit log standard (Belastingdienst). Provides
 * HMAC-SHA256 signatures and SHA-256 hash-chain operations; never
 * stores plaintext secrets — signing key is fetched from app config.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IAppConfig;
use RuntimeException;

/**
 * Cryptographic service for Kassakoppeling POS audit log compliance.
 *
 * Signing spec:
 *   fields  = [operatorId, registerNumber, action, amount, taxAmount, timestamp, previousHash]
 *   message = implode('|', fields)
 *   signature = hash_hmac('sha256', message, secretKey)
 *
 * Hash-chain spec:
 *   message = implode('|', [operatorId, registerNumber, action, amount,
 *                            itemCount, taxAmount, timestamp,
 *                            transactionUuid, previousHash])
 *   currentHash = hash('sha256', message)
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
 */
class KassakoppelingSignatureService
{
    /**
     * Fields used to build the signing message, in order.
     * Must match the Kassakoppeling specification (design.md).
     */
    private const SIGN_FIELDS = [
        'operatorId',
        'registerNumber',
        'action',
        'amount',
        'taxAmount',
        'timestamp',
        'previousHash',
    ];

    /**
     * Fields used to build the hash-chain message, in order.
     */
    private const HASH_FIELDS = [
        'operatorId',
        'registerNumber',
        'action',
        'amount',
        'itemCount',
        'taxAmount',
        'timestamp',
        'transactionUuid',
        'previousHash',
    ];

    /**
     * App config key for the Kassakoppeling signing secret.
     */
    private const CONFIG_KEY = 'kassakoppeling_secret';

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig The Nextcloud app config service.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Generate an HMAC-SHA256 signature for the given audit entry data.
     *
     * Only the fields defined in SIGN_FIELDS are included (in order). Missing
     * fields default to an empty string so the message stays deterministic.
     *
     * @param array<string,mixed> $entryData The entry data array.
     *
     * @return string Lowercase hex HMAC-SHA256 digest (64 chars).
     *
     * @throws RuntimeException When the signing key is not configured.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function generateSignature(array $entryData): string
    {
        $key     = $this->getSecretKey();
        $message = $this->buildSignMessage($entryData);
        return hash_hmac('sha256', $message, $key);
    }//end generateSignature()

    /**
     * Generate the SHA-256 hash for this entry (hash-chain link).
     *
     * @param array<string,mixed> $entryData    The entry data array.
     * @param string              $previousHash The hash of the previous entry
     *                                          (or '0' for the first entry).
     *
     * @return string Lowercase hex SHA-256 digest (64 chars).
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function generateHash(array $entryData, string $previousHash): string
    {
        $data               = $entryData;
        $data['previousHash'] = $previousHash;
        $message            = $this->buildHashMessage($data);
        return hash('sha256', $message);
    }//end generateHash()

    /**
     * Verify an HMAC-SHA256 signature against the stored entry.
     *
     * Uses `hash_equals()` to prevent timing-based side-channel attacks.
     *
     * @param array<string,mixed> $entryData The entry data (without 'signature' or 'currentHash' keys).
     * @param string              $signature The signature to verify.
     *
     * @return bool True when the recomputed signature matches.
     *
     * @throws RuntimeException When the signing key is not configured.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function verifySignature(array $entryData, string $signature): bool
    {
        $computed = $this->generateSignature($entryData);
        return hash_equals($computed, $signature);
    }//end verifySignature()

    /**
     * Validate the integrity of an entire hash chain.
     *
     * Iterates entries (oldest-first) and verifies that each entry's
     * `currentHash` equals the SHA-256 computed from its own fields +
     * `previousHash`. The first entry must have `previousHash === '0'`
     * (or any other sentinel that was stored).
     *
     * @param array<int,array<string,mixed>> $entries Ordered list of audit entries.
     *
     * @return bool True when every link in the chain is intact.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function verifyHashChain(array $entries): bool
    {
        foreach ($entries as $entry) {
            $stored   = (string) ($entry['currentHash'] ?? '');
            $previous = (string) ($entry['previousHash'] ?? '0');
            $computed = $this->generateHash($entry, $previous);
            if (hash_equals($computed, $stored) === false) {
                return false;
            }
        }

        return true;
    }//end verifyHashChain()

    /**
     * Return the Kassakoppeling signing key from app config.
     *
     * The key is stored via:
     *   `php occ config:app:set pipelinq kassakoppeling_secret --value="<key>"`
     * or by setting it programmatically in config/config.php as an app value.
     *
     * @return string The signing key string.
     *
     * @throws RuntimeException When the key has not been configured.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function getSecretKey(): string
    {
        $key = $this->appConfig->getAppValue('pipelinq', self::CONFIG_KEY, '');
        if ($key === '') {
            throw new RuntimeException(
                'Kassakoppeling signing key is not configured. '
                . 'Set it via: occ config:app:set pipelinq kassakoppeling_secret --value="<key>"'
            );
        }

        return $key;
    }//end getSecretKey()

    /**
     * Build the pipe-delimited signing message from an entry.
     *
     * @param array<string,mixed> $entryData Entry fields.
     *
     * @return string Pipe-delimited message string.
     */
    private function buildSignMessage(array $entryData): string
    {
        $parts = [];
        foreach (self::SIGN_FIELDS as $field) {
            $parts[] = (string) ($entryData[$field] ?? '');
        }

        return implode('|', $parts);
    }//end buildSignMessage()

    /**
     * Build the pipe-delimited hash-chain message from an entry.
     *
     * @param array<string,mixed> $entryData Entry fields (must include 'previousHash').
     *
     * @return string Pipe-delimited message string.
     */
    private function buildHashMessage(array $entryData): string
    {
        $parts = [];
        foreach (self::HASH_FIELDS as $field) {
            $parts[] = (string) ($entryData[$field] ?? '');
        }

        return implode('|', $parts);
    }//end buildHashMessage()
}//end class
