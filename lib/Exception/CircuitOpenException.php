<?php

/**
 * Pipelinq CircuitOpenException.
 *
 * Raised when an endpoint circuit breaker is open and a send is short-circuited.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#6.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Thrown when the circuit breaker is open for the target endpoint.
 */
class CircuitOpenException extends StufException
{
}//end class
