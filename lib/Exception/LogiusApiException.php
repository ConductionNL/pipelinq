<?php

/**
 * Pipelinq LogiusApiException.
 *
 * Thrown when a Logius Berichtenbox-koppelvlak (BBK 1.7) API call fails.
 *
 * @category Exception
 * @package  OCA\Pipelinq\Exception
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Thrown on a Logius BBK 1.7 API failure (auth, rate-limit, validation, server).
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-CONFORMANCE-008
 */
class LogiusApiException extends BerichtenboxException
{

    /**
     * Machine-readable failure reason.
     *
     * @var string
     */
    private string $reason;

    /**
     * Constructor.
     *
     * @param string $message The human-readable message (no BSN material).
     * @param string $reason  The machine reason (auth|rate-limit|validation|server|network).
     */
    public function __construct(string $message, string $reason='server')
    {
        parent::__construct(message: $message);
        $this->reason = $reason;
    }//end __construct()

    /**
     * Get the machine-readable failure reason.
     *
     * @return string The reason code.
     */
    public function getReason(): string
    {
        return $this->reason;
    }//end getReason()
}//end class
