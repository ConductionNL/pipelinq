<?php

/**
 * Pipelinq VrijBerichtNotRegisteredException.
 *
 * Raised when adapter.vrijBericht() is called with an unregistered template name.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Thrown when no vrijBericht template is registered for the requested name.
 */
class VrijBerichtNotRegisteredException extends StufException
{
}//end class
