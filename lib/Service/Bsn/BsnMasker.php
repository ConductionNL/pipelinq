<?php

/**
 * Pipelinq BsnMasker.
 *
 * Privacy helper that produces the masked / pseudonymised representations of a
 * BSN that ADR-005 permits outside the secure boundary. A plain-text BSN must
 * NEVER be logged, returned in an error message, placed in a URL, or persisted;
 * every such surface uses {@see self::mask()} (display) or {@see self::hash()}
 * (irreversible pseudonymisation for the audit trail under AVG art. 17).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Bsn
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Bsn;

/**
 * Stateless BSN masking / hashing helper (ADR-005, REQ-BSN-009).
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.4
 */
final class BsnMasker
{
    /**
     * Mask a BSN for display / logging as `***45678*`.
     *
     * The first three characters are replaced with `***`, the middle digits are
     * shown, and the final digit is replaced with `*`. Any input that is not a
     * 9-character digit string collapses to a fully-redacted token so a stray
     * value can never leak through this function.
     *
     * @param string $bsn The raw BSN (never logged by the caller).
     *
     * @return string The masked representation, e.g. `***45678*`.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.4
     */
    public static function mask(string $bsn): string
    {
        if (strlen($bsn) !== 9 || ctype_digit($bsn) === false) {
            return '*********';
        }

        return '***'.substr($bsn, 3, 5).'*';
    }//end mask()

    /**
     * Irreversibly pseudonymise a BSN for retained audit records (AVG art. 17).
     *
     * A keyed SHA-256 (HMAC) is used so the digest cannot be brute-forced from
     * the small BSN keyspace without the instance secret. The result is prefixed
     * so a hashed value is never mistaken for a masked display value.
     *
     * @param string $bsn    The raw BSN.
     * @param string $secret The instance-wide HMAC secret.
     *
     * @return string The `sha256:`-prefixed keyed digest.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.4
     */
    public static function hash(string $bsn, string $secret): string
    {
        return 'sha256:'.hash_hmac('sha256', $bsn, $secret);
    }//end hash()
}//end class
