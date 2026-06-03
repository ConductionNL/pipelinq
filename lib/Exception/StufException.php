<?php

/**
 * Pipelinq StufException.
 *
 * Base exception for the StUF ZKN/BG adapter.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#7.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

use Exception;

/**
 * Base exception type for all StUF adapter failures.
 */
class StufException extends Exception
{
}//end class
