<?php

/**
 * Pipelinq KassakoppelingSignatureService.
 *
 * Cryptographic primitives for the POS Kassakoppeling-compliant audit log.
 * Generates HMAC-SHA256 entry signatures and SHA-256 chain hashes; verifies
 * both an individual signature (against a stored entry) and a complete
 * per-register chain (every link's previousHash matches the prior entry's
 * currentHash). The secret key is read from the app config (sensitive flag)
 * with a deterministic instance-id-bound fallback so the chain stays usable
 * in CI without leaking a hardcoded key into source control.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use RuntimeException;

/**
 * HMAC-SHA256 + SHA-256-chain primitives for kassakoppelingAuditLog entries.
 *
 * Canonical field order for the signed message:
 *
 *   operatorId | registerNumber | action | amount | taxAmount | timestamp | previousHash
 *
 * Canonical field order for the chain hash:
 *
 *   operatorId | registerNumber | action | amount | itemCount | taxAmount
 *   | timestamp | transactionUuid | description | previousHash
 *
 * Both functions are deterministic and pure — given the same fields and the
 * same secret they always produce the same hex digest, so the auditor (or a
 * Newman integration test) can replay the chain from genesis.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
 */
class KassakoppelingSignatureService
{
    /**
     * App-config key storing the HMAC secret (sensitive).
     *
     * @var string
     */
    public const SECRET_KEY = 'kassakoppeling.secret';

    /**
     * Sentinel `previousHash` value for the first entry on a register.
     *
     * @var string
     */
    public const GENESIS_HASH = '0';

    /**
     * Canonical signature field order.
     *
     * @var array<int, string>
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
     * Canonical chain-hash field order. Wider than the signature so a
     * tampered description / itemCount also breaks the chain even though
     * the HMAC signature has not changed.
     *
     * @var array<int, string>
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
        'description',
        'previousHash',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig The app config (sensitive secret storage).
     * @param IConfig    $config    The system config (instance-id fallback).
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
    ) {
    }//end __construct()

    /**
     * Generate the HMAC-SHA256 signature for an entry.
     *
     * The fields are joined by `|` in the canonical order defined by
     * {@see self::SIGNATURE_FIELDS}; null/missing values render as empty
     * strings so the message stays stable across PHP / JSON round-trips.
     *
     * @param array<string, mixed> $entryData The entry data.
     *
     * @return string The HMAC-SHA256 hex digest.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function generateSignature(array $entryData): string
    {
        $message = $this->canonicalMessage(entryData: $entryData, fields: self::SIGNATURE_FIELDS);

        return hash_hmac('sha256', $message, $this->getSecretKey());
    }//end generateSignature()

    /**
     * Generate the SHA-256 chain hash for an entry.
     *
     * The previousHash MUST be supplied explicitly (read from the prior
     * entry's currentHash, or `self::GENESIS_HASH` for the first entry on
     * the register) so the caller is in control of the chain ordering.
     *
     * @param array<string, mixed> $entryData    The entry data.
     * @param string               $previousHash The prior entry's currentHash.
     *
     * @return string The SHA-256 hex digest.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function generateHash(array $entryData, string $previousHash): string
    {
        $entry = $entryData;
        $entry['previousHash'] = $previousHash;
        $message = $this->canonicalMessage(entryData: $entry, fields: self::HASH_FIELDS);

        return hash('sha256', $message);
    }//end generateHash()

    /**
     * Verify an entry's signature.
     *
     * Returns true when the recomputed HMAC-SHA256 matches `signature` byte
     * for byte under a constant-time comparison (`hash_equals`). Returns
     * false when the entry has been tampered with after signing, or when the
     * secret has been rotated and the entry was signed under the old key.
     *
     * @param array<string, mixed> $entryData The entry data.
     * @param string               $signature The stored signature.
     *
     * @return bool Whether the signature matches.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function verifySignature(array $entryData, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $computed = $this->generateSignature(entryData: $entryData);

        return hash_equals($computed, $signature);
    }//end verifySignature()

    /**
     * Verify a per-register chain.
     *
     * Each entry's `previousHash` MUST match the prior entry's `currentHash`,
     * and each entry's `currentHash` MUST equal the recomputed hash. The
     * first entry's `previousHash` MUST be `self::GENESIS_HASH`. The check
     * returns a structured result so the caller can render the break point
     * in the Belastingdienst export metadata.
     *
     * @param array<int, array<string, mixed>> $entries Entries in order.
     *
     * @return array{chainValid: bool, brokenAt: int|null, unbrokenLinks: int}
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function verifyHashChain(array $entries): array
    {
        $expectedPrevious = self::GENESIS_HASH;
        $unbroken         = 0;

        foreach ($entries as $index => $entry) {
            $previous = (string) ($entry['previousHash'] ?? '');
            if ($previous !== $expectedPrevious) {
                return ['chainValid' => false, 'brokenAt' => ($index + 1), 'unbrokenLinks' => $unbroken];
            }

            $expectedCurrent = $this->generateHash(entryData: $entry, previousHash: $previous);
            $current         = (string) ($entry['currentHash'] ?? '');
            if (hash_equals($expectedCurrent, $current) === false) {
                return ['chainValid' => false, 'brokenAt' => ($index + 1), 'unbrokenLinks' => $unbroken];
            }

            $expectedPrevious = $current;
            $unbroken++;
        }//end foreach

        return ['chainValid' => true, 'brokenAt' => null, 'unbrokenLinks' => $unbroken];
    }//end verifyHashChain()

    /**
     * Read the HMAC secret key.
     *
     * Priority order:
     *
     *   1. App-config `kassakoppeling.secret` (sensitive flag) — set by the
     *      operator at install time.
     *   2. Deterministic fallback derived from the instance id (`config.php`
     *      `instanceid`) so dev / CI runs produce a stable but non-public
     *      key. The fallback is documented and explicitly NOT a security
     *      boundary — production deployments MUST set the explicit secret.
     *
     * Throws when neither value is available (the instance id is required
     * by Nextcloud's bootstrap so this is effectively unreachable in a
     * running install — defensive guard for tests with a stub IConfig).
     *
     * @return string The secret key.
     *
     * @throws RuntimeException When no key material is available.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.1
     */
    public function getSecretKey(): string
    {
        $configured = trim($this->appConfig->getValueString(Application::APP_ID, self::SECRET_KEY, ''));
        if ($configured !== '') {
            return $configured;
        }

        $instanceId = trim((string) $this->config->getSystemValue('instanceid', ''));
        if ($instanceId !== '') {
            return hash('sha256', 'pipelinq:kassakoppeling:'.$instanceId);
        }

        throw new RuntimeException('Kassakoppeling secret is not configured and no instance id fallback is available.');
    }//end getSecretKey()

    /**
     * Build the canonical `|`-joined message from an entry + field order.
     *
     * Null, missing, or non-scalar values render as the empty string so the
     * message stays stable across PHP / JSON round-trips and is easy to
     * reproduce in test fixtures.
     *
     * @param array<string, mixed> $entryData The entry data.
     * @param array<int, string>   $fields    The canonical field order.
     *
     * @return string The canonical message.
     */
    private function canonicalMessage(array $entryData, array $fields): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $value = $entryData[$field] ?? '';
            if (is_array($value) === true) {
                $value = '';
            }

            if (is_object($value) === true) {
                $value = '';
            }

            if (is_bool($value) === true) {
                $boolValue = $value;
                $value     = 'false';
                if ($boolValue === true) {
                    $value = 'true';
                }
            }

            $parts[] = (string) $value;
        }//end foreach

        return implode('|', $parts);
    }//end canonicalMessage()
}//end class
