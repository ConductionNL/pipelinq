<?php

/**
 * Pipelinq PayloadTooLargeException.
 *
 * Raised when the document payload exceeds the configured pre-base64 ceiling.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#7.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Thrown when an attachment payload is too large to embed in a StUF envelope.
 */
class PayloadTooLargeException extends StufException
{
}//end class
