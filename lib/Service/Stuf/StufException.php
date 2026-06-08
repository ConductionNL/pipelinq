<?php

/**
 * Pipelinq StufException — base exception for the StUF-ZKN/BG adapter.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use RuntimeException;

/**
 * Base StUF adapter exception.
 */
class StufException extends RuntimeException
{
}//end class
