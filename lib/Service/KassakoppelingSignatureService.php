<?php

/**
 * Pipelinq KassakoppelingSignatureService.
 *
 * Cryptographic primitives for the Belastingdienst Kassakoppeling audit log:
 * the HMAC-SHA256 entry signature, the SHA-256 hash-chain link and their
 * constant-time verification. The signing key is read from app config and is
 * never hardcoded; an unconfigured key is a hard error so an unsigned entry can
 * never be written.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Signs and verifies Kassakoppeling audit entries.
 *
 * The signature is an HMAC-SHA256 over a fixed, ordered field list joined with
 * '|'; the hash chain is a SHA-256 over the same authoritative fields plus the
 * previous entry's hash. Verification uses hash_equals for constant-time
 * comparison so a tampered field is detected without timing leaks. The service
 * holds no state beyond the injected app config.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
 */
class KassakoppelingSignatureService
{
    /**
     * App-config key holding the HMAC signing secret.
     *
     * @var string
     */
    public const SECRET_KEY = 'kassakoppeling_secret';

    /**
     * Ordered fields hashed into the HMAC signature.
     *
     * @var string[]
     */
    private const SIGNATURE_FIELDS = [
        'operatorId',
        'registerNumber',
        'action',
        'amount',
        'taxAmount',
        'timestamp',
        'previousHash',
    ];

    /**
     * Ordered fields hashed into the SHA-256 chain hash (previousHash appended).
     *
     * @var string[]
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
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig The app config.
     */
    public function __construct(private IAppConfig $appConfig)
    {
    }//end __construct()

    /**
     * Return the configured HMAC signing secret.
     *
     * @return string The secret key.
     *
     * @throws RuntimeException When no secret is configured (fail closed).
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function getSecretKey(): string
    {
        $secret = $this->appConfig->getValueString(Application::APP_ID, self::SECRET_KEY, '');
        if ($secret === '') {
            throw new RuntimeException('Kassakoppeling signing secret is not configured.');
        }

        return $secret;
    }//end getSecretKey()

    /**
     * Generate the HMAC-SHA256 signature for an entry.
     *
     * @param array<string, mixed> $entryData The entry fields (must include previousHash).
     *
     * @return string The hex HMAC-SHA256 digest.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function generateSignature(array $entryData): string
    {
        $message = $this->signatureMessage(entryData: $entryData);

        return hash_hmac('sha256', $message, $this->getSecretKey());
    }//end generateSignature()

    /**
     * Verify an entry's signature in constant time.
     *
     * @param array<string, mixed> $entryData The entry fields (must include previousHash).
     * @param string               $signature The signature to check.
     *
     * @return bool Whether the signature matches the recomputed value.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function verifySignature(array $entryData, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals($this->generateSignature(entryData: $entryData), $signature);
    }//end verifySignature()

    /**
     * Generate the SHA-256 chain hash for an entry.
     *
     * @param array<string, mixed> $entryData    The entry fields.
     * @param string               $previousHash The prior entry's currentHash, or '0'.
     *
     * @return string The hex SHA-256 digest.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function generateHash(array $entryData, string $previousHash): string
    {
        $parts = [];
        foreach (self::HASH_FIELDS as $field) {
            $parts[] = $this->scalar(value: ($entryData[$field] ?? ''));
        }

        $parts[] = $previousHash;

        return hash('sha256', implode('|', $parts));
    }//end generateHash()

    /**
     * Verify the integrity of an ordered hash chain.
     *
     * Each entry's currentHash must equal the SHA-256 recomputed from its own
     * fields and its previousHash, and its previousHash must equal the prior
     * entry's currentHash. The first entry's previousHash must be '0'. Returns
     * false on the first broken link.
     *
     * @param array<int, array<string, mixed>> $entries The entries in chain order.
     *
     * @return bool Whether the whole chain is intact.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function verifyHashChain(array $entries): bool
    {
        $expectedPrevious = '0';

        foreach ($entries as $entry) {
            $previousHash = (string) ($entry['previousHash'] ?? '');
            if ($previousHash !== $expectedPrevious) {
                return false;
            }

            $computed = $this->generateHash(entryData: $entry, previousHash: $previousHash);
            if (hash_equals($computed, (string) ($entry['currentHash'] ?? '')) === false) {
                return false;
            }

            $expectedPrevious = (string) ($entry['currentHash'] ?? '');
        }

        return true;
    }//end verifyHashChain()

    /**
     * Build the '|'-joined signature message for an entry.
     *
     * @param array<string, mixed> $entryData The entry fields.
     *
     * @return string The message to HMAC.
     */
    private function signatureMessage(array $entryData): string
    {
        $parts = [];
        foreach (self::SIGNATURE_FIELDS as $field) {
            $parts[] = $this->scalar(value: ($entryData[$field] ?? ''));
        }

        return implode('|', $parts);
    }//end signatureMessage()

    /**
     * Coerce a value to a stable scalar string for hashing.
     *
     * @param mixed $value The value.
     *
     * @return string The canonical string form.
     */
    private function scalar(mixed $value): string
    {
        if (is_bool($value) === true) {
            if ($value === true) {
                return 'true';
            }

            return 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }//end scalar()
}//end class
