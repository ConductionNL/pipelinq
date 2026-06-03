<?php

/**
 * Pipelinq StufTimeoutException.
 *
 * Raised when a synchronous vraag/antwoord query times out without a La01.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#6.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Thrown when a synchronous StUF query exceeds its timeout.
 */
class StufTimeoutException extends StufException
{
}//end class
