<?php

/**
 * Pipelinq HaalCentraalException.
 *
 * Raised by a BRP client when a lookup fails for any reason other than a clean
 * "not found". It carries the upstream HTTP status (when known) so the
 * controller can map it to the correct response and audit outcome WITHOUT ever
 * embedding the BSN in the message (ADR-005).
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Bsn;

use RuntimeException;
use Throwable;

/**
 * Exception for BRP lookup failures (REQ-BSN-003).
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
 */
class HaalCentraalException extends RuntimeException
{
    /**
     * Constructor.
     *
     * @param string         $message    A generic, BSN-free failure description.
     * @param int            $statusCode The upstream HTTP status (0 when unknown).
     * @param Throwable|null $previous   The underlying throwable, if any.
     */
    public function __construct(
        string $message,
        private readonly int $statusCode=0,
        ?Throwable $previous=null,
    ) {
        parent::__construct(message: $message, code: $statusCode, previous: $previous);
    }//end __construct()

    /**
     * Get the upstream HTTP status code (0 when unknown, e.g. on timeout).
     *
     * @return int The status code.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }//end getStatusCode()
}//end class
