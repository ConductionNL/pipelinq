<?php

/**
 * Pipelinq VrijBerichtNotRegisteredException.
 *
 * Raised when adapter.vrijBericht is invoked with a name that has no
 * registered template on the target StufEndpoint.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

/**
 * Pre-send domain error: vrijBericht template not registered.
 */
class VrijBerichtNotRegisteredException extends StufException
{
}//end class
