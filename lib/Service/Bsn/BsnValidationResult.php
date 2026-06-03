<?php

/**
 * Pipelinq BsnValidationResult.
 *
 * Immutable value object describing the outcome of a formal 11-proef BSN
 * validation. It deliberately exposes only the MASKED BSN and a generic,
 * citizen-data-free error message so the result can be returned to the client,
 * logged, or serialised without ever leaking a plain-text BSN (ADR-005).
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Bsn;

/**
 * Result DTO for a single 11-proef validation (REQ-BSN-001).
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.1
 */
final class BsnValidationResult
{
    /**
     * Constructor.
     *
     * @param bool        $isFormeelGeldig Whether the input is a formally valid BSN.
     * @param string      $bsnGemaskeerd   The masked BSN (`***45678*`), never the raw value.
     * @param string|null $errorCode       A stable, translatable error code, or null when valid.
     * @param string|null $errorMessage    A generic, BSN-free human message, or null when valid.
     */
    public function __construct(
        public readonly bool $isFormeelGeldig,
        public readonly string $bsnGemaskeerd,
        public readonly ?string $errorCode=null,
        public readonly ?string $errorMessage=null,
    ) {
    }//end __construct()

    /**
     * Serialise the result for a JSON response (no raw BSN ever included).
     *
     * @return array<string, mixed> The serialisable result.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.1
     */
    public function toArray(): array
    {
        return [
            'isFormeelGeldig' => $this->isFormeelGeldig,
            'bsnGemaskeerd'   => $this->bsnGemaskeerd,
            'errorCode'       => $this->errorCode,
            'errorMessage'    => $this->errorMessage,
        ];
    }//end toArray()
}//end class
