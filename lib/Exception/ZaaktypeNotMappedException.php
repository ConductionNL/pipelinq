<?php

/**
 * Pipelinq ZaaktypeNotMappedException.
 *
 * Raised when a Request type has no zkn:zaaktype mapping before envelope build.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#7.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Thrown when a request type cannot be mapped to a zaaktype omschrijving.
 */
class ZaaktypeNotMappedException extends StufException
{
}//end class
