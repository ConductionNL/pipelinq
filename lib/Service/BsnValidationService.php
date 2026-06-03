<?php

/**
 * Pipelinq BsnValidationService.
 *
 * Deterministic, self-contained implementation of the RvIG 11-proef BSN format
 * check. It performs NO external call and NEVER logs, returns, or persists a
 * plain-text BSN — only the masked representation and a generic error code
 * leave this service (ADR-005, REQ-BSN-001 / REQ-BSN-009).
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Bsn\BsnMasker;
use OCA\Pipelinq\Service\Bsn\BsnValidationResult;

/**
 * Service implementing the formal 11-proef BSN validation (REQ-BSN-001).
 *
 * The 11-proef weights the nine digits 9·d1 + 8·d2 + … + 2·d8 + (−1)·d9 and
 * requires the sum to be divisible by 11. `000000000` is explicitly rejected
 * even though its checksum is 0, since an all-zero BSN is never issued.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.1
 */
class BsnValidationService
{
    /**
     * Error code: the input is not exactly nine digits.
     *
     * @var string
     */
    public const ERROR_LENGTH = 'bsn.validation.length';

    /**
     * Error code: the input is nine digits but fails the 11-proef checksum.
     *
     * @var string
     */
    public const ERROR_ELFPROEF = 'bsn.validation.invalid';

    /**
     * Per-position weights for the 11-proef (last digit weighted −1).
     *
     * @var int[]
     */
    private const WEIGHTS = [9, 8, 7, 6, 5, 4, 3, 2, -1];

    /**
     * Validate a BSN input string against the formal 11-proef.
     *
     * Length / character class is checked first so a malformed value (e.g. 8
     * digits or a letter) is rejected without running the checksum. The returned
     * result carries only the masked BSN.
     *
     * @param string $bsnInput The raw 9-digit BSN candidate (never logged).
     *
     * @return BsnValidationResult The validation outcome.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.1
     */
    public function validate(string $bsnInput): BsnValidationResult
    {
        $masked = BsnMasker::mask($bsnInput);

        if (strlen($bsnInput) !== 9 || ctype_digit($bsnInput) === false) {
            return new BsnValidationResult(
                isFormeelGeldig: false,
                bsnGemaskeerd: $masked,
                errorCode: self::ERROR_LENGTH,
                errorMessage: 'Een BSN bestaat uit exact 9 cijfers'
            );
        }

        if ($this->passesElfproef(bsn: $bsnInput) === false) {
            return new BsnValidationResult(
                isFormeelGeldig: false,
                bsnGemaskeerd: $masked,
                errorCode: self::ERROR_ELFPROEF,
                errorMessage: 'Dit BSN voldoet niet aan de 11-proef'
            );
        }

        return new BsnValidationResult(
            isFormeelGeldig: true,
            bsnGemaskeerd: $masked
        );
    }//end validate()

    /**
     * Compute the 11-proef for a validated 9-digit numeric string.
     *
     * @param string $bsn A string guaranteed to be exactly nine digits.
     *
     * @return bool True when the weighted sum is divisible by 11 and the BSN is
     *              not the all-zero sentinel.
     */
    private function passesElfproef(string $bsn): bool
    {
        if ($bsn === '000000000') {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $bsn[$i]) * self::WEIGHTS[$i];
        }

        return ($sum % 11) === 0;
    }//end passesElfproef()
}//end class
