<?php

/**
 * Pipelinq CircuitOpenException.
 *
 * Raised when the circuit breaker for the target endpoint is currently open
 * and the cooldown has not yet elapsed.
 *
 * @category Exception
 * @package  OCA\Pipelinq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

/**
 * Short-circuited: circuit breaker is open for the endpoint.
 */
class CircuitOpenException extends StufException
{
}//end class
