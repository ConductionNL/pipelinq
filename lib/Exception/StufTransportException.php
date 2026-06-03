<?php

/**
 * Pipelinq StufTransportException.
 *
 * Raised when the SOAP transport fails (network error, unresolved credentials).
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Thrown when the StUF SOAP transport cannot deliver an envelope.
 */
class StufTransportException extends StufException
{
}//end class
