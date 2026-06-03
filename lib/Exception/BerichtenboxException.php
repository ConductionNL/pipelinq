<?php

/**
 * Pipelinq BerichtenboxException.
 *
 * Base exception for the Berichtenbox bridge.
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

use RuntimeException;

/**
 * Base exception type for the Berichtenbox bridge.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md
 */
class BerichtenboxException extends RuntimeException
{
}//end class
