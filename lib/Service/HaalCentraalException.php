<?php

/**
 * Pipelinq HaalCentraalException.
 *
 * Wraps every error path of the HaalCentraal Personen REST client so callers
 * can surface a consistent message and an HTTP status code to the UI.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use RuntimeException;

/**
 * Exception thrown by HaalCentraalClient on every non-success path.
 *
 * Carries an HTTP status code that callers / controllers can pass straight back to the UI.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003
 */
class HaalCentraalException extends RuntimeException
{

    /**
     * HTTP status returned by HaalCentraal (or 0 on transport / config errors).
     *
     * @var integer
     */
    private int $statusCode;

    /**
     * Correlation ID returned by HaalCentraal (when known).
     *
     * @var string|null
     */
    private ?string $correlationId;

    /**
     * Constructor.
     *
     * @param string      $message       The error message (callers may surface this; never include BSN).
     * @param int         $statusCode    HTTP status code from HaalCentraal (0 on transport failure).
     * @param string|null $correlationId Optional correlation ID for audit / support.
     * @param \Throwable  $previous      Optional cause.
     */
    public function __construct(
        string $message,
        int $statusCode=0,
        ?string $correlationId=null,
        ?\Throwable $previous=null,
    ) {
        parent::__construct(message: $message, code: $statusCode, previous: $previous);
        $this->statusCode    = $statusCode;
        $this->correlationId = $correlationId;
    }//end __construct()

    /**
     * Get the HTTP status code (0 = transport error).
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }//end getStatusCode()

    /**
     * Get the correlation ID (or null).
     *
     * @return string|null
     */
    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }//end getCorrelationId()
}//end class
